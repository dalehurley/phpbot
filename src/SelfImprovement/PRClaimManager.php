<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\SelfImprovement;

/**
 * Coordinates distributed PR review slots using GitHub comments as state.
 *
 * Protocol:
 *   1. Apply hash-based jitter delay so bots spread across the time window.
 *   2. Count existing phpbot-claim comments on the PR.
 *   3. If slots remain (count < maxReviewers), post a claim comment.
 *   4. Wait 5 seconds and recount to resolve concurrent claimants.
 *   5. If this bot's claim is within the first maxReviewers positions: proceed.
 *      Otherwise: back off silently.
 *
 * Claim comment format (HTML comment = invisible to humans on GitHub):
 *   <!-- phpbot-claim -->
 *   {"botId":"uuid","claimedAt":"ISO8601","phpbotVersion":"1.x"}
 *
 * Verdict comment format:
 *   <!-- phpbot-verdict -->
 *   {"botId":"uuid","verdict":"pass","confidence":0.87,"notes":"...","reviewedAt":"ISO8601"}
 */
class PRClaimManager
{
    private string $botId;
    private string $repo;
    private int $maxReviewers;
    private int $jitterMaxSec;
    private int $claimTimeoutMin;

    /** @var \Closure|null */
    private ?\Closure $logger;

    public function __construct(
        string $botId,
        string $repo,
        int $maxReviewers    = 3,
        int $jitterMaxSec    = 300,
        int $claimTimeoutMin = 30,
        ?\Closure $logger    = null
    ) {
        $this->botId           = $botId;
        $this->repo            = $repo;
        $this->maxReviewers    = $maxReviewers;
        $this->jitterMaxSec    = $jitterMaxSec;
        $this->claimTimeoutMin = $claimTimeoutMin;
        $this->logger          = $logger;
    }

    /**
     * Attempt to claim a reviewer slot for the given PR.
     *
     * Returns true when this bot successfully secured a slot and should
     * proceed with the review. Returns false if slots are full or this
     * bot lost the race.
     */
    public function claim(int $prNumber): bool
    {
        if ($this->isAuthor($prNumber)) {
            $this->log("PR #{$prNumber}: authored by this bot's account, skipping review.");
            return false;
        }

        // Hash-based deterministic jitter — same bot always gets the same
        // delay for the same PR, distributing bots evenly across the window.
        $jitter = abs(crc32($this->botId . $prNumber)) % max(1, $this->jitterMaxSec);
        $this->log("PR #{$prNumber}: waiting {$jitter}s (jitter) before claiming...");
        sleep($jitter);

        $claims = $this->fetchActiveClaims($prNumber);
        if (count($claims) >= $this->maxReviewers) {
            $this->log("PR #{$prNumber}: {$this->maxReviewers} reviewers already claimed, skipping.");
            return false;
        }

        $claimBody = $this->buildClaimComment($prNumber);
        $posted    = $this->postComment($prNumber, $claimBody);
        if (!$posted) {
            $this->log("PR #{$prNumber}: failed to post claim comment.");
            return false;
        }

        $this->log("PR #{$prNumber}: claim comment posted, waiting 5s to resolve races...");
        sleep(5);

        $claims   = $this->fetchActiveClaims($prNumber);
        $position = $this->findOurPosition($claims);

        if ($position === null || $position >= $this->maxReviewers) {
            $this->log("PR #{$prNumber}: lost the race (position {$position}), backing off.");
            return false;
        }

        $this->log("PR #{$prNumber}: claimed slot {$position} of {$this->maxReviewers}.");
        return true;
    }

    /**
     * Post a structured verdict comment on the PR.
     *
     * @param  string  $verdict    'pass' | 'fail'
     * @param  float   $confidence 0.0–1.0
     * @param  string  $notes      Human-readable review summary
     */
    public function postVerdict(int $prNumber, string $verdict, float $confidence, string $notes): bool
    {
        $body = $this->buildVerdictComment($prNumber, $verdict, $confidence, $notes);
        return $this->postComment($prNumber, $body);
    }

    // =========================================================================
    // Private helpers — comment building
    // =========================================================================

    private function buildClaimComment(int $prNumber): string
    {
        $payload = json_encode([
            'botId'     => $this->botId,
            'claimedAt' => date('c'),
            'prNumber'  => $prNumber,
        ], JSON_UNESCAPED_SLASHES);

        return "<!-- phpbot-claim -->\n{$payload}";
    }

    private function buildVerdictComment(
        int $prNumber,
        string $verdict,
        float $confidence,
        string $notes
    ): string {
        $payload = json_encode([
            'botId'      => $this->botId,
            'prNumber'   => $prNumber,
            'verdict'    => $verdict,
            'confidence' => round($confidence, 3),
            'notes'      => $notes,
            'reviewedAt' => date('c'),
        ], JSON_UNESCAPED_SLASHES);

        return "<!-- phpbot-verdict -->\n{$payload}";
    }

    // =========================================================================
    // Private helpers — GitHub API via gh CLI
    // =========================================================================

    /**
     * Fetch all phpbot-claim comments that are not expired.
     *
     * @return array<array{botId: string, claimedAt: string, position: int}>
     */
    private function fetchActiveClaims(int $prNumber): array
    {
        $comments = $this->fetchComments($prNumber);
        $claims   = [];
        $cutoff   = new \DateTimeImmutable("-{$this->claimTimeoutMin} minutes");

        foreach ($comments as $comment) {
            $body = $comment['body'] ?? '';
            if (!str_contains($body, '<!-- phpbot-claim -->')) {
                continue;
            }

            $data = $this->parseJsonFromComment($body);
            if ($data === null || empty($data['botId'])) {
                continue;
            }

            $claimedAt = new \DateTimeImmutable($data['claimedAt'] ?? 'now');
            if ($claimedAt < $cutoff && !$this->hasVerdict($prNumber, $data['botId'])) {
                continue;
            }

            $claims[] = [
                'botId'     => $data['botId'],
                'claimedAt' => $data['claimedAt'] ?? '',
                'position'  => $comment['id'] ?? 0,
            ];
        }

        usort($claims, fn($a, $b) => $a['position'] <=> $b['position']);

        return $claims;
    }

    /** Return this bot's 0-based position in the claims list, or null if not found. */
    private function findOurPosition(array $claims): ?int
    {
        foreach ($claims as $index => $claim) {
            if ($claim['botId'] === $this->botId) {
                return $index;
            }
        }
        return null;
    }

    /** Check whether the given PR has a verdict comment from the given botId. */
    private function hasVerdict(int $prNumber, string $botId): bool
    {
        $comments = $this->fetchComments($prNumber);
        foreach ($comments as $comment) {
            $body = $comment['body'] ?? '';
            if (!str_contains($body, '<!-- phpbot-verdict -->')) {
                continue;
            }
            $data = $this->parseJsonFromComment($body);
            if ($data !== null && ($data['botId'] ?? '') === $botId) {
                return true;
            }
        }
        return false;
    }

    /** Return true when the authenticated gh user authored this PR. */
    private function isAuthor(int $prNumber): bool
    {
        $repoFlag = $this->repo !== '' ? '--repo ' . escapeshellarg($this->repo) : '';
        $cmd      = "gh pr view {$prNumber} {$repoFlag} --json author --jq '.author.login' 2>/dev/null";
        $prAuthor = trim((string) shell_exec($cmd));
        $ghUser   = trim((string) shell_exec('gh api user --jq .login 2>/dev/null'));
        return $ghUser !== '' && $prAuthor === $ghUser;
    }

    /**
     * Fetch all comments on a PR as raw arrays.
     *
     * @return array<array{id: int, body: string}>
     */
    private function fetchComments(int $prNumber): array
    {
        $cmd = sprintf(
            'gh api repos/%s/issues/%d/comments --jq \'.[] | {id: .id, body: .body}\' 2>/dev/null',
            $this->repo,
            $prNumber
        );

        $output   = [];
        exec($cmd, $output);

        $comments = [];
        foreach ($output as $line) {
            $obj = json_decode($line, true);
            if (is_array($obj)) {
                $comments[] = $obj;
            }
        }

        return $comments;
    }

    /** Post a comment body on a PR via the gh CLI. */
    private function postComment(int $prNumber, string $body): bool
    {
        $repoFlag = $this->repo !== '' ? '--repo ' . escapeshellarg($this->repo) : '';
        $cmd      = sprintf(
            'gh pr comment %d %s --body %s 2>/dev/null',
            $prNumber,
            $repoFlag,
            escapeshellarg($body)
        );
        exec($cmd, $out, $exitCode);
        return $exitCode === 0;
    }

    /** Extract a JSON object from a comment that contains an HTML comment marker. */
    private function parseJsonFromComment(string $body): ?array
    {
        $lines = array_values(array_filter(explode("\n", $body), fn($l) => !str_starts_with(trim($l), '<!--')));
        $json  = implode("\n", $lines);
        $data  = json_decode(trim($json), true);
        return is_array($data) ? $data : null;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
