<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Listener\Watchers;

use Dalehurley\Phpbot\Apple\AppleScriptRunner;
use Dalehurley\Phpbot\Listener\ListenerEvent;
use Dalehurley\Phpbot\Listener\StateStore;
use Dalehurley\Phpbot\Platform;

/**
 * Watches for macOS user notifications via the Unified Logging system.
 *
 * Uses `log show` to query recent notification delivery events from
 * the UNUserNotificationCenter subsystem. This captures notifications
 * from most apps but may miss some depending on how they're delivered.
 *
 * This is the most experimental watcher — notification content is
 * limited to what the logging subsystem exposes (app name, category,
 * and partial content).
 */
class NotificationWatcher implements WatcherInterface
{
    private AppleScriptRunner $runner;

    /** @var \Closure|null */
    private ?\Closure $logger;

    public function __construct(AppleScriptRunner $runner)
    {
        $this->runner = $runner;
        $this->logger = null;
    }

    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'notifications';
    }

    public function isAvailable(): bool
    {
        return Platform::isMacOS();
    }

    public function poll(StateStore $state): array
    {
        $lastCheck = $state->get('notifications', 'last_check', null);

        $now = new \DateTimeImmutable();

        // On first run, only look back 60 seconds to avoid a flood
        if ($lastCheck === null) {
            $since = $now->modify('-60 seconds');
        } else {
            try {
                $since = new \DateTimeImmutable($lastCheck);
            } catch (\Throwable) {
                $since = $now->modify('-60 seconds');
            }
        }

        $sinceStr = $since->format('Y-m-d H:i:s');

        // Query the Unified Logging system for notification deliveries
        $command = sprintf(
            'log show --predicate %s --start %s --style json --no-pager 2>/dev/null',
            escapeshellarg('subsystem == "com.apple.UNUserNotificationCenter" AND category == "Delivery"'),
            escapeshellarg($sinceStr),
        );

        $result = $this->runner->runCommand($command, 15);

        // Update checkpoint regardless of success to prevent re-scanning
        $state->set('notifications', 'last_check', $now->format('c'));
        $state->save();

        if ($result['exit_code'] !== 0 || trim($result['stdout']) === '') {
            return [];
        }

        $events = $this->parseLogOutput($result['stdout'], $now);

        if (!empty($events)) {
            $this->log('Found ' . count($events) . ' notification(s)');
        }

        return $events;
    }

    /**
     * Parse the JSON output from `log show --style json`.
     *
     * @return array<ListenerEvent>
     */
    private function parseLogOutput(string $output, \DateTimeImmutable $now): array
    {
        // The log output is a JSON array of log entries
        $decoded = json_decode($output, true);

        if (!is_array($decoded)) {
            // Sometimes log show returns non-JSON; try line-by-line
            return $this->parseLogLines($output, $now);
        }

        $events = [];
        $seen = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $processName = $entry['processImagePath'] ?? ($entry['process'] ?? 'Unknown');
            $message = $entry['eventMessage'] ?? '';

            if ($message === '') {
                continue;
            }

            // Deduplicate by message content within this poll
            $hash = md5($processName . $message);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;

            // Extract app name from process path
            $appName = basename(dirname($processName, 2));
            if (str_ends_with($appName, '.app')) {
                $appName = substr($appName, 0, -4);
            } else {
                $appName = basename($processName);
            }

            try {
                $timestamp = new \DateTimeImmutable($entry['timestamp'] ?? 'now');
            } catch (\Throwable) {
                $timestamp = $now;
            }

            $events[] = new ListenerEvent(
                source: 'notifications',
                type: 'notification',
                subject: mb_substr($message, 0, 200),
                sender: $appName,
                body: $message,
                timestamp: $timestamp,
                rawId: $hash,
                metadata: [
                    'process' => $processName,
                    'subsystem' => $entry['subsystem'] ?? '',
                    'category' => $entry['category'] ?? '',
                ],
            );
        }

        return $events;
    }

    /**
     * Fallback parser for non-JSON log output (line-based).
     *
     * @return array<ListenerEvent>
     */
    private function parseLogLines(string $output, \DateTimeImmutable $now): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $output)), fn($l) => $l !== '');
        $events = [];

        foreach ($lines as $line) {
            // Skip header lines and empty lines
            if (str_starts_with($line, 'Timestamp') || str_starts_with($line, '---') || str_starts_with($line, 'Filtering')) {
                continue;
            }

            // Basic parsing: timestamp process[pid] message
            if (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}[^\s]*)\s+\S+\s+(\S+)\[?\d*\]?\s+(.+)$/', $line, $matches)) {
                try {
                    $timestamp = new \DateTimeImmutable($matches[1]);
                } catch (\Throwable) {
                    $timestamp = $now;
                }

                $hash = md5($line);
                $events[] = new ListenerEvent(
                    source: 'notifications',
                    type: 'notification',
                    subject: mb_substr($matches[3], 0, 200),
                    sender: $matches[2],
                    body: $matches[3],
                    timestamp: $timestamp,
                    rawId: $hash,
                    metadata: [],
                );
            }
        }

        return $events;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
