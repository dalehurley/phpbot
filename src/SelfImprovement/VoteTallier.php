<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\SelfImprovement;

/**
 * Counts phpbot-verdict comments and applies the community-approved label
 * when quorum is reached.
 *
 * This class is idempotent: applying a label that already exists is a no-op,
 * and posting a tally comment is skipped when one already exists.
 *
 * Tally comment format (HTML comment = invisible to humans):
 *   <!-- phpbot-tally -->
 *   {"passes":2,"fails":0,"total":2,"quorumMet":true,"talliedBy":"uuid","talliedAt":"ISO8601"}
 */
class VoteTallier
{
    private string $repo;
    private string $botId;
    private int $quorum;
    private string $maintainerHandle;

    /** @var \Closure|null */
    private ?\Closure $logger;

    public function __construct(
        string $repo,
        string $botId,
        int    $quorum           = 2,
        string $maintainerHandle = '',
        ?\Closure $logger        = null
    ) {
        $this->repo             = $repo;
        $this->botId            = $botId;
        $this->quorum           = $quorum;
        $this->maintainerHandle = $maintainerHandle;
        $this->logger           = $logger;
    }

    /**
     * Tally all phpbot-verdict comments on the PR and act on the result.
     *
     * @return array{passes: int, fails: int, quorum_met: bool, action: string}
     */
    public function tally(int $prNumber): array
    {
        $verdicts = $this->fetchVerdicts($prNumber);

        $passes = count(array_filter($verdicts, fn($v) => $v['verdict'] === 'pass'));
        $fails  = count(array_filter($verdicts, fn($v) => $v['verdict'] === 'fail'));
        $total  = count($verdicts);

        $this->log("PR #{$prNumber}: {$passes} pass, {$fails} fail out of {$total} verdicts.");

        if ($this->hasTallyComment($prNumber)) {
            $this->log("PR #{$prNumber}: tally already posted, skipping.");
            return ['passes' => $passes, 'fails' => $fails, 'quorum_met' => false, 'action' => 'already_tallied'];
        }

        $quorumMet = $passes >= $this->quorum;

        if ($quorumMet) {
            $this->applyLabel($prNumber, 'community-approved');
            $this->postTallyComment($prNumber, $passes, $fails, true);

            if ($this->maintainerHandle !== '') {
                $this->postMaintainerNotification($prNumber, $passes, $fails);
            }

            return ['passes' => $passes, 'fails' => $fails, 'quorum_met' => true, 'action' => 'approved'];
        }

        $activeClaims = $this->countActiveClaims($prNumber);
        $allVoted     = $total >= $activeClaims && $activeClaims > 0;

        if ($allVoted && $fails > $passes) {
            $this->applyLabel($prNumber, 'community-rejected');
            $this->postTallyComment($prNumber, $passes, $fails, false);
            return ['passes' => $passes, 'fails' => $fails, 'quorum_met' => false, 'action' => 'rejected'];
        }

        return ['passes' => $passes, 'fails' => $fails, 'quorum_met' => false, 'action' => 'pending'];
    }

    // =========================================================================
    // Private helpers — verdict parsing
    // =========================================================================

    /**
     * @return array<array{botId: string, verdict: string, confidence: float, notes: string}>
     */
    private function fetchVerdicts(int $prNumber): array
    {
        $comments = $this->fetchComments($prNumber);
        $seen     = [];

        foreach ($comments as $comment) {
            $body = $comment['body'] ?? '';
            if (!str_contains($body, '<!-- phpbot-verdict -->')) {
                continue;
            }

            $data = $this->parseJsonFromComment($body);
            if ($data === null || empty($data['botId']) || empty($data['verdict'])) {
                continue;
            }

            $botId         = $data['botId'];
            $seen[$botId]  = [
                'botId'      => $botId,
                'verdict'    => in_array($data['verdict'], ['pass', 'fail'], true) ? $data['verdict'] : 'fail',
                'confidence' => (float) ($data['confidence'] ?? 0.5),
                'notes'      => (string) ($data['notes'] ?? ''),
            ];
        }

        return array_values($seen);
    }

    private function countActiveClaims(int $prNumber): int
    {
        $comments = $this->fetchComments($prNumber);
        $count    = 0;
        $cutoff   = new \DateTimeImmutable('-30 minutes');

        foreach ($comments as $comment) {
            $body = $comment['body'] ?? '';
            if (!str_contains($body, '<!-- phpbot-claim -->')) {
                continue;
            }
            $data      = $this->parseJsonFromComment($body);
            $claimedAt = new \DateTimeImmutable($data['claimedAt'] ?? 'now');
            if ($claimedAt >= $cutoff) {
                $count++;
            }
        }

        return $count;
    }

    private function hasTallyComment(int $prNumber): bool
    {
        $comments = $this->fetchComments($prNumber);
        foreach ($comments as $comment) {
            if (str_contains($comment['body'] ?? '', '<!-- phpbot-tally -->')) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // Private helpers — GitHub API
    // =========================================================================

    private function postTallyComment(int $prNumber, int $passes, int $fails, bool $quorumMet): void
    {
        $status  = $quorumMet ? 'APPROVED' : 'REJECTED';
        $payload = json_encode([
            'passes'    => $passes,
            'fails'     => $fails,
            'total'     => $passes + $fails,
            'quorumMet' => $quorumMet,
            'talliedBy' => $this->botId,
            'talliedAt' => date('c'),
        ], JSON_UNESCAPED_SLASHES);

        $humanSummary = $quorumMet
            ? "**Community review: APPROVED** ({$passes} pass / {$fails} fail)\n\n"
              . "This PR has met the quorum threshold and has been labelled `community-approved`. "
              . "A maintainer has been notified for final merge decision."
            : "**Community review: REJECTED** ({$passes} pass / {$fails} fail)\n\n"
              . "This PR did not receive enough passing votes from community reviewers.";

        $body = "<!-- phpbot-tally -->\n{$payload}\n\n---\n\n{$humanSummary}";

        $this->postComment($prNumber, $body);
        $this->log("PR #{$prNumber}: tally posted — {$status}.");
    }

    private function postMaintainerNotification(int $prNumber, int $passes, int $fails): void
    {
        $handle = ltrim($this->maintainerHandle, '@');
        $body   = "@{$handle} — this self-improvement PR has been approved by "
                . "{$passes} community bot(s) and is ready for your review. "
                . "({$passes} pass / {$fails} fail)";

        $this->postComment($prNumber, $body);
    }

    private function applyLabel(int $prNumber, string $label): void
    {
        $repoFlag = $this->repo !== '' ? '--repo ' . escapeshellarg($this->repo) : '';
        $cmd      = sprintf(
            'gh pr edit %d %s --add-label %s 2>/dev/null',
            $prNumber,
            $repoFlag,
            escapeshellarg($label)
        );
        exec($cmd, $out, $code);
        $this->log("PR #{$prNumber}: apply label '{$label}' — exit {$code}.");
    }

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

    /**
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

    private function parseJsonFromComment(string $body): ?array
    {
        $lines = array_values(array_filter(
            explode("\n", $body),
            fn($l) => !str_starts_with(trim($l), '<!--') && trim($l) !== ''
        ));
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
