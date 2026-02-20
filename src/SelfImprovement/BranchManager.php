<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\SelfImprovement;

/**
 * Handles all git and gh-CLI operations for the self-improvement pipeline.
 *
 * Every operation executes shell commands and returns a result array:
 *   ['ok' => bool, 'output' => string, 'error' => string]
 *
 * The working directory is the repository root — all paths must be relative.
 */
class BranchManager
{
    private string $repoRoot;

    /** @var \Closure|null fn(string $message): void */
    private ?\Closure $logger;

    public function __construct(string $repoRoot, ?\Closure $logger = null)
    {
        $this->repoRoot = rtrim($repoRoot, '/');
        $this->logger   = $logger;
    }

    // =========================================================================
    // Branch operations
    // =========================================================================

    /** Create and switch to a new branch from the current HEAD. */
    public function createBranch(string $branchName): array
    {
        return $this->exec("git checkout -b " . escapeshellarg($branchName));
    }

    /** Switch back to main (or master, whichever exists). */
    public function checkoutMain(): array
    {
        $main = $this->detectDefaultBranch();
        return $this->exec("git checkout " . escapeshellarg($main));
    }

    /** Return the name of the current branch. */
    public function currentBranch(): string
    {
        $result = $this->exec('git rev-parse --abbrev-ref HEAD');
        return $result['ok'] ? trim($result['output']) : '';
    }

    /** Delete a local branch (used when reverting a failed pipeline). */
    public function deleteBranch(string $branchName): array
    {
        return $this->exec("git branch -D " . escapeshellarg($branchName));
    }

    // =========================================================================
    // Staging and committing
    // =========================================================================

    /** Stage all modified and new tracked files. */
    public function stageAll(): array
    {
        return $this->exec('git add -A');
    }

    /**
     * Stage specific files.
     * @param string[] $paths Relative paths to stage.
     */
    public function stageFiles(array $paths): array
    {
        $escaped = implode(' ', array_map('escapeshellarg', $paths));
        return $this->exec("git add {$escaped}");
    }

    /** Commit staged changes with the given message. */
    public function commit(string $message): array
    {
        return $this->exec('git commit -m ' . escapeshellarg($message));
    }

    /** Convenience: stage all and commit in one call. */
    public function stageAndCommit(string $message): array
    {
        $stage = $this->stageAll();
        if (!$stage['ok']) {
            return $stage;
        }
        return $this->commit($message);
    }

    // =========================================================================
    // Push and PR creation
    // =========================================================================

    /** Push the current branch to origin, setting upstream tracking. */
    public function push(string $branchName): array
    {
        return $this->exec('git push -u origin ' . escapeshellarg($branchName));
    }

    /**
     * Create a GitHub pull request and return the PR URL.
     *
     * @param  string   $title      PR title.
     * @param  string   $body       PR body (markdown).
     * @param  string[] $labels     Labels to apply.
     * @param  string   $base       Base branch (default: main).
     * @return array{ok: bool, url: string, output: string, error: string}
     */
    public function createPullRequest(
        string $title,
        string $body,
        array $labels = [],
        string $base = 'main'
    ): array {
        $cmd = 'gh pr create'
             . ' --title ' . escapeshellarg($title)
             . ' --body '  . escapeshellarg($body)
             . ' --base '  . escapeshellarg($base);

        foreach ($labels as $label) {
            $cmd .= ' --label ' . escapeshellarg($label);
        }

        $result = $this->exec($cmd);

        // gh pr create prints the PR URL as the last line of output.
        $url = '';
        if ($result['ok']) {
            $lines = array_filter(array_map('trim', explode("\n", $result['output'])));
            $url   = end($lines) ?: '';
        }

        return array_merge($result, ['url' => $url]);
    }

    /** Close a pull request. */
    public function closePullRequest(int $prNumber, string $comment = ''): array
    {
        $cmd = 'gh pr close ' . (int) $prNumber;
        if ($comment !== '') {
            $cmd .= ' --comment ' . escapeshellarg($comment);
        }
        return $this->exec($cmd);
    }

    // =========================================================================
    // Revert helpers
    // =========================================================================

    /** Hard-reset the working tree and index to HEAD. */
    public function resetHard(): array
    {
        return $this->exec('git reset --hard HEAD');
    }

    /** Remove untracked files and directories. */
    public function cleanUntracked(): array
    {
        return $this->exec('git clean -fd');
    }

    /** Full revert: reset hard + clean, then switch back to main. */
    public function revertToMain(string $branchName): array
    {
        $this->resetHard();
        $this->cleanUntracked();
        $this->checkoutMain();
        return $this->deleteBranch($branchName);
    }

    // =========================================================================
    // Status helpers
    // =========================================================================

    /** Return true when the working tree has staged or unstaged changes. */
    public function hasChanges(): bool
    {
        $result = $this->exec('git status --porcelain');
        return $result['ok'] && trim($result['output']) !== '';
    }

    /** Check whether the `gh` CLI is authenticated and available. */
    public function isGhAvailable(): bool
    {
        $result = $this->exec('gh auth status 2>&1', captureStderr: true);
        return $result['ok'];
    }

    /**
     * Return the list of changed files between HEAD and the working tree.
     * @return string[]
     */
    public function changedFiles(): array
    {
        $result = $this->exec("git diff --name-only HEAD");
        if (!$result['ok'] || trim($result['output']) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode("\n", $result['output']))));
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function detectDefaultBranch(): string
    {
        $result = $this->exec('git symbolic-ref refs/remotes/origin/HEAD --short 2>/dev/null');
        if ($result['ok'] && $result['output'] !== '') {
            return trim(str_replace('origin/', '', $result['output']));
        }
        $main = $this->exec('git show-ref --verify refs/heads/main 2>/dev/null');
        return $main['ok'] ? 'main' : 'master';
    }

    /**
     * @return array{ok: bool, output: string, error: string}
     */
    private function exec(string $command, bool $captureStderr = false): array
    {
        $this->log("git: {$command}");

        $fullCommand = "cd " . escapeshellarg($this->repoRoot) . " && {$command}";
        if ($captureStderr) {
            $fullCommand .= ' 2>&1';
        }

        $output   = [];
        $exitCode = 0;
        exec($fullCommand . ($captureStderr ? '' : ' 2>/tmp/phpbot-git-err'), $output, $exitCode);

        $outputStr = implode("\n", $output);
        $errorStr  = '';

        if (!$captureStderr && is_readable('/tmp/phpbot-git-err')) {
            $errorStr = (string) file_get_contents('/tmp/phpbot-git-err');
        }

        if ($exitCode !== 0) {
            $this->log("git error (exit {$exitCode}): " . ($errorStr ?: $outputStr));
        }

        return [
            'ok'     => $exitCode === 0,
            'output' => $outputStr,
            'error'  => $errorStr,
        ];
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
