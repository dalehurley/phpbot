<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Listener\Watchers;

use Dalehurley\Phpbot\Listener\ListenerEvent;
use Dalehurley\Phpbot\Listener\StateStore;

/**
 * Polls GitHub for open pull requests labelled `phpbot-self-improvement`
 * and emits a ListenerEvent for each unseen PR.
 *
 * Uses the `gh` CLI so no additional dependencies are required.
 * State tracking (seen PR numbers) is persisted via StateStore so a
 * daemon restart does not re-emit already-processed PRs.
 */
class GitHubPRWatcher implements WatcherInterface
{
    private const LABEL     = 'phpbot-self-improvement';
    private const WATCHER   = 'github_pr';
    private const STATE_KEY = 'seen_numbers';

    private string $repo;

    /** @var \Closure|null */
    private ?\Closure $logger;

    public function __construct(string $repo, ?\Closure $logger = null)
    {
        $this->repo   = $repo;
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'github_pr';
    }

    public function isAvailable(): bool
    {
        if ($this->repo === '') {
            return false;
        }
        $output   = [];
        $exitCode = 0;
        exec('gh auth status 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Poll for new self-improvement PRs and return unseen ones as events.
     *
     * @return ListenerEvent[]
     */
    public function poll(StateStore $state): array
    {
        $openPrs = $this->fetchOpenPrs();
        if (empty($openPrs)) {
            return [];
        }

        $seen   = $this->loadSeen($state);
        $events = [];

        foreach ($openPrs as $pr) {
            $number = (int) ($pr['number'] ?? 0);
            if ($number <= 0 || in_array($number, $seen, true)) {
                continue;
            }

            $this->log("New self-improvement PR #{$number}: {$pr['title']}");

            $events[] = new ListenerEvent(
                source:    'github',
                type:      'github_pr',
                subject:   "Self-improvement PR #{$number}: {$pr['title']}",
                sender:    $pr['author'] ?? 'unknown',
                body:      $pr['body'] ?? '',
                timestamp: new \DateTimeImmutable(),
                rawId:     (string) $number,
                metadata: [
                    'pr_number' => $number,
                    'pr_url'    => $pr['url'] ?? '',
                    'pr_title'  => $pr['title'] ?? '',
                    'pr_labels' => $pr['labels'] ?? [],
                    'pr_author' => $pr['author'] ?? '',
                    'repo'      => $this->repo,
                ],
            );

            $seen[] = $number;
        }

        if (!empty($events)) {
            $this->saveSeen($state, $seen);
            $state->save();
        }

        return $events;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * @return array<array{number: int, title: string, url: string, body: string, author: string, labels: string[]}>
     */
    private function fetchOpenPrs(): array
    {
        $label    = escapeshellarg(self::LABEL);
        $repoFlag = $this->repo !== '' ? '--repo ' . escapeshellarg($this->repo) : '';
        $cmd      = "gh pr list {$repoFlag} --label {$label} --state open --json number,title,url,body,author,labels 2>/dev/null";

        $output   = [];
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return [];
        }

        $json = implode('', $output);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [];
        }

        return array_map(function (array $pr): array {
            return [
                'number' => (int) ($pr['number'] ?? 0),
                'title'  => (string) ($pr['title'] ?? ''),
                'url'    => (string) ($pr['url'] ?? ''),
                'body'   => (string) ($pr['body'] ?? ''),
                'author' => (string) ($pr['author']['login'] ?? ''),
                'labels' => array_map(fn($l) => $l['name'] ?? '', $pr['labels'] ?? []),
            ];
        }, $data);
    }

    /** @return int[] */
    private function loadSeen(StateStore $state): array
    {
        $raw = $state->get(self::WATCHER, self::STATE_KEY, []);
        return is_array($raw) ? array_map('intval', $raw) : [];
    }

    /** @param int[] $seen */
    private function saveSeen(StateStore $state, array $seen): void
    {
        if (count($seen) > 500) {
            $seen = array_slice($seen, -500);
        }
        $state->set(self::WATCHER, self::STATE_KEY, $seen);
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
