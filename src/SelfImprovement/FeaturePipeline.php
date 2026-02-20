<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\SelfImprovement;

use Dalehurley\Phpbot\Bot;

/**
 * Orchestrates the full self-improvement pipeline for a single feature:
 *
 *   1. Classify type and risk tier (ProtectedPathGuard)
 *   2. Create a git feature branch (BranchManager)
 *   3. Invoke the bot to build the feature
 *   4. Run smoke tests (php -l, phpstan, bot self-test)
 *   5. Commit, push, and create a GitHub PR
 *   6. Switch back to main and inform the user
 *
 * The pipeline is intentionally synchronous — it blocks the interactive CLI
 * while running. Progress is emitted via the $progress closure so callers can
 * surface messages to the user in real time.
 */
class FeaturePipeline
{
    private Bot $bot;
    private array $config;
    private string $repoRoot;
    private BranchManager $git;

    /** @var \Closure fn(string $stage, string $message): void */
    private \Closure $progress;

    public function __construct(Bot $bot, array $config)
    {
        $this->bot      = $bot;
        $this->config   = $config;
        $this->repoRoot = $this->detectRepoRoot();
        $this->git      = new BranchManager(
            $this->repoRoot,
            fn(string $msg) => $this->emit('git', $msg)
        );

        $this->progress = fn($stage, $msg) => null;
    }

    public function setProgress(\Closure $progress): void
    {
        $this->progress = $progress;
    }

    // =========================================================================
    // Public entry point
    // =========================================================================

    /**
     * Run the full pipeline for a feature description.
     *
     * @return array{ok: bool, pr_url: string, branch: string, message: string}
     */
    public function run(string $description): array
    {
        $si      = $this->config['self_improvement'] ?? [];
        $maxTier = $si['max_risk_tier'] ?? 'tool';
        $repo    = $si['github_repo'] ?? '';
        $botId   = $si['bot_id'] ?? gethostname();

        // --- 1. Classify ----------------------------------------------------
        $this->emit('classify', 'Classifying change type and risk tier...');
        $type     = $this->classifyType($description);
        $riskTier = ProtectedPathGuard::classify(
            $this->pathsForType($type),
            isNewFileOnly: true
        );
        $slug = $this->slugify($description);

        $proposal = ImprovementProposal::create($description, $type, $riskTier, $slug);

        if ($proposal->isBlocked()) {
            return $this->fail('This change targets a permanently blocked path and cannot be submitted automatically.');
        }

        if (!ProtectedPathGuard::isWithinLimit($riskTier, $maxTier)) {
            return $this->fail(
                "Risk tier '{$riskTier}' exceeds the configured maximum '{$maxTier}'. "
                . "Set PHPBOT_MAX_RISK_TIER={$riskTier} to allow this."
            );
        }

        $this->emit('classify', "Type: {$type} | Risk: {$riskTier} | Branch: {$proposal->branchName}");

        // --- 2. Branch -------------------------------------------------------
        $this->emit('branch', "Creating branch {$proposal->branchName}...");

        if (!$this->git->isGhAvailable()) {
            return $this->fail('gh CLI is not authenticated. Run `gh auth login` first.');
        }

        $branchResult = $this->git->createBranch($proposal->branchName);
        if (!$branchResult['ok']) {
            return $this->fail("Failed to create branch: {$branchResult['error']}");
        }

        // --- 3. Build --------------------------------------------------------
        // Run the bot with automatic continuation when the iteration limit is
        // hit — complex features often need more than one segment to complete.
        $this->emit('build', 'Asking the bot to implement the feature...');

        $buildRound         = 0;
        $totalBuildIter     = 0;
        $maxBuildRounds     = 5;

        $buildPrompt = $this->buildPrompt($proposal);
        $botResult   = $this->bot->run($buildPrompt, fn($stage, $msg) => $this->emit('build', $msg));
        $totalBuildIter += $botResult->getIterations();

        // The agent library marks the run as *failed* when it hits the
        // iteration limit ("Maximum iterations (N) reached without completion").
        // That is not a real failure — it just means the bot needs more room.
        // Detect this and continue automatically for up to $maxBuildRounds.
        while (
            $this->isIterationLimitError($botResult)
            && $buildRound < $maxBuildRounds
        ) {
            $buildRound++;
            $this->emit(
                'build',
                "Iteration limit reached ({$totalBuildIter} used). "
                . "Continuing build (segment {$buildRound}/{$maxBuildRounds})..."
            );

            $botResult = $this->bot->run(
                "Continue implementing the feature from exactly where you left off. "
                . "Review the conversation history to see what files have been created or modified. "
                . "Complete all remaining steps — do not repeat work already done. "
                . "(Build continuation segment: {$buildRound})",
                fn($stage, $msg) => $this->emit('build', $msg)
            );
            $totalBuildIter += $botResult->getIterations();
        }

        $this->emit('build', "Build finished ({$totalBuildIter} total iterations across " . ($buildRound + 1) . " segment(s)).");

        // After continuation rounds, the bot may have genuinely failed (non-iteration error).
        if (!$botResult->isSuccess() && !$this->isIterationLimitError($botResult)) {
            $this->git->revertToMain($proposal->branchName);
            return $this->fail('Bot failed to implement the feature: ' . ($botResult->getError() ?? 'unknown error'));
        }

        if (!$this->git->hasChanges()) {
            $this->git->revertToMain($proposal->branchName);
            return $this->fail('The bot ran successfully but made no file changes. Nothing to commit.');
        }

        // --- 4. Smoke tests --------------------------------------------------
        $this->emit('test', 'Running smoke tests...');
        $testResult = $this->runSmokeTests();
        if (!$testResult['ok']) {
            $this->git->revertToMain($proposal->branchName);
            return $this->fail("Smoke tests failed:\n" . $testResult['output']);
        }
        $this->emit('test', 'Smoke tests passed.');

        // --- 5. Commit and push ----------------------------------------------
        $this->emit('commit', 'Committing changes...');

        $commitMessage = "feat({$type}): {$description}\n\n"
                       . "Auto-generated by PHPBot self-improvement pipeline.\n"
                       . "Risk tier: {$riskTier} | Bot ID: {$botId}";

        $commitResult = $this->git->stageAndCommit($commitMessage);
        if (!$commitResult['ok']) {
            $this->git->revertToMain($proposal->branchName);
            return $this->fail("Failed to commit: {$commitResult['error']}");
        }

        $this->emit('push', "Pushing branch {$proposal->branchName}...");
        $pushResult = $this->git->push($proposal->branchName);
        if (!$pushResult['ok']) {
            $this->git->revertToMain($proposal->branchName);
            return $this->fail("Failed to push: {$pushResult['error']}");
        }

        // --- 6. Create PR ----------------------------------------------------
        $this->emit('pr', 'Creating pull request...');

        $quorumInfo       = ProtectedPathGuard::quorumForTier($riskTier);
        $maintainerRequired = $quorumInfo['maintainer_flag'] ? 'Yes' : 'No';

        $prBody = <<<BODY
## Summary

{$description}

## Change Details

- **Type**: {$type}
- **Risk tier**: {$riskTier}
- **Bot ID**: {$botId}
- **Branch**: `{$proposal->branchName}`
- **Submitted**: {$proposal->createdAt}

## Review Requirements

| Setting | Value |
|---|---|
| Max reviewers | {$quorumInfo['max_reviewers']} |
| Quorum required | {$quorumInfo['quorum']} passes |
| Maintainer required | {$maintainerRequired} |

## Community Review Status

_Reviewer bots will post `<!-- phpbot-verdict -->` comments below. The `VoteTallier` applies the `community-approved` label when quorum is reached._

| Bot ID | Verdict | Confidence | Notes |
|---|---|---|---|
| _(pending)_ | — | — | — |

---

> This PR was created automatically by the PHPBot self-improvement pipeline.
> Human review is always welcome and encouraged before merging.
BODY;

        $labels    = ['phpbot-self-improvement', "risk-{$riskTier}"];
        $prResult  = $this->git->createPullRequest(
            title:  "feat({$type}): {$description}",
            body:   $prBody,
            labels: $labels,
            base:   'main'
        );

        // --- 7. Return to main -----------------------------------------------
        $this->emit('cleanup', 'Switching back to main...');
        $this->git->checkoutMain();

        if (!$prResult['ok']) {
            return $this->fail("PR created but something went wrong: {$prResult['error']}");
        }

        $prUrl = $prResult['url'];
        $this->emit('done', "PR submitted: {$prUrl}");

        return [
            'ok'      => true,
            'pr_url'  => $prUrl,
            'branch'  => $proposal->branchName,
            'message' => "Feature submitted for community review!\n\nPR: {$prUrl}\n\n"
                       . "A quorum of {$quorumInfo['quorum']} reviewer bots must approve before "
                       . ($quorumInfo['maintainer_flag'] ? 'the maintainer is notified.' : 'the `community-approved` label is applied.'),
        ];
    }

    // =========================================================================
    // Smoke tests
    // =========================================================================

    /**
     * Run a series of quick validation checks on the changed files.
     *
     * @return array{ok: bool, output: string}
     */
    private function runSmokeTests(): array
    {
        $changedFiles = $this->git->changedFiles();
        $phpFiles     = array_filter($changedFiles, fn($f) => str_ends_with($f, '.php'));
        $errors       = [];

        // 1. PHP syntax check on every modified PHP file.
        foreach ($phpFiles as $file) {
            $fullPath = $this->repoRoot . '/' . $file;
            $output   = [];
            $exitCode = 0;
            exec('php -l ' . escapeshellarg($fullPath) . ' 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                $errors[] = "Syntax error in {$file}:\n" . implode("\n", $output);
            }
        }

        if (!empty($errors)) {
            return ['ok' => false, 'output' => implode("\n\n", $errors)];
        }

        // 2. PHPStan — only if binary exists and analyse is fast (max-file-size).
        $phpstanBin = $this->repoRoot . '/vendor/bin/phpstan';
        if (is_executable($phpstanBin) && !empty($phpFiles)) {
            $fileArgs = implode(' ', array_map(
                fn($f) => escapeshellarg($this->repoRoot . '/' . $f),
                $phpFiles
            ));
            $phpstanOutput = [];
            $phpstanCode   = 0;
            exec(
                $phpstanBin . ' analyse --level=5 --no-progress ' . $fileArgs . ' 2>&1',
                $phpstanOutput,
                $phpstanCode
            );
            if ($phpstanCode !== 0) {
                $errors[] = "PHPStan errors:\n" . implode("\n", $phpstanOutput);
            }
        }

        if (!empty($errors)) {
            return ['ok' => false, 'output' => implode("\n\n", $errors)];
        }

        // 3. Bot self-test: confirm phpbot --version exits cleanly.
        $binPath  = $this->repoRoot . '/bin/phpbot';
        $selfTest = [];
        $selfCode = 0;
        if (is_executable($binPath)) {
            exec('php ' . escapeshellarg($binPath) . ' --version 2>&1', $selfTest, $selfCode);
            if ($selfCode !== 0) {
                return ['ok' => false, 'output' => "Bot self-test failed:\n" . implode("\n", $selfTest)];
            }
        }

        return ['ok' => true, 'output' => 'All checks passed.'];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function buildPrompt(ImprovementProposal $proposal): string
    {
        return <<<PROMPT
You are implementing a self-improvement feature for PHPBot. Follow these instructions carefully:

**Feature request**: {$proposal->description}

**Change type**: {$proposal->type}
**Risk tier**: {$proposal->riskTier}

**Rules**:
1. You are on branch `{$proposal->branchName}`. Make only changes relevant to this feature.
2. Do NOT modify files in: src/Security/, config/, .github/workflows/, or src/SelfImprovement/.
3. If adding a new tool, create it under src/Tools/. If adding a skill, create it under skills/.
4. If modifying core code (src/*.php or src/Agent/, src/Router/, etc.), be surgical — minimal diffs.
5. After creating files, verify PHP syntax with `php -l <file>`.
6. Do not commit. The pipeline handles git operations.

Implement the feature now.
PROMPT;
    }

    /**
     * Detect the specific "Maximum iterations (N) reached" error from the agent library.
     * This is NOT a real failure — it means the bot just needs more iterations.
     */
    private function isIterationLimitError(\Dalehurley\Phpbot\BotResult $result): bool
    {
        if ($result->isSuccess()) {
            return false;
        }
        $error = $result->getError() ?? '';
        return str_contains($error, 'Maximum iterations') && str_contains($error, 'reached');
    }

    /** Convert a description string into a URL/branch-safe kebab-case slug. */
    private function slugify(string $description): string
    {
        $slug = strtolower($description);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug) ?? $slug;
        $slug = preg_replace('/[\s-]+/', '-', trim($slug)) ?? $slug;
        $slug = trim($slug, '-');
        // Truncate to keep branch names manageable
        if (strlen($slug) > 50) {
            $slug = substr($slug, 0, 50);
            $slug = rtrim($slug, '-');
        }
        return $slug !== '' ? $slug : 'feature';
    }

    private function classifyType(string $description): string
    {
        $lower = strtolower($description);

        // Only escalate to 'core' when the user explicitly targets bot internals.
        $coreKeywords = ['refactor', 'rewrite', 'modify core', 'change how the bot',
                         'change the agent', 'update bot.php', 'change daemon',
                         'change router', 'change scheduler'];
        foreach ($coreKeywords as $kw) {
            if (str_contains($lower, $kw)) {
                return 'core';
            }
        }

        // 'tool' tier — adding a new discrete capability callable by the agent.
        // Use word-boundary matching to avoid false positives like "control" matching "command".
        if (preg_match('/\btool\b|\bcommand\b|\bintegration\b|\bapi integration\b|\bmcp\b/', $lower)) {
            return 'tool';
        }

        // Everything else (new workflow, feature, automation, browser, etc.) → 'skill'.
        return 'skill';
    }

    /** Return a representative set of paths for a given change type. */
    private function pathsForType(string $type): array
    {
        return match ($type) {
            'skill' => ['skills/new-feature/SKILL.md'],
            'tool'  => ['src/Tools/NewFeatureTool.php'],
            'core'  => ['src/Bot.php'],
            default => ['skills/new-feature/SKILL.md'],
        };
    }

    private function fail(string $message): array
    {
        $this->emit('error', $message);
        return ['ok' => false, 'pr_url' => '', 'branch' => '', 'message' => $message];
    }

    private function emit(string $stage, string $message): void
    {
        ($this->progress)($stage, $message);
    }

    private function detectRepoRoot(): string
    {
        // Walk up from this file's location to find the composer.json root.
        $dir = dirname(__DIR__, 2);
        while ($dir !== '/' && !is_file($dir . '/composer.json')) {
            $dir = dirname($dir);
        }
        return $dir;
    }
}
