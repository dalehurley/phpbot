<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Listener\Watchers;

use Dalehurley\Phpbot\Listener\ListenerEvent;
use Dalehurley\Phpbot\Listener\StateStore;
use Dalehurley\Phpbot\Platform;

/**
 * Watches for new iMessages by reading the Messages chat.db SQLite database.
 *
 * Messages.app has no AppleScript support for reading inbound messages,
 * so we query the database directly. This requires Full Disk Access
 * permission in System Settings > Privacy & Security.
 *
 * Falls back gracefully if the database is locked or inaccessible.
 */
class MessageWatcher implements WatcherInterface
{
    private string $dbPath;

    /** @var \Closure|null */
    private ?\Closure $logger;

    public function __construct(?string $dbPath = null)
    {
        $this->dbPath = $dbPath ?? (getenv('HOME') ?: '/Users/' . get_current_user()) . '/Library/Messages/chat.db';
        $this->logger = null;
    }

    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'messages';
    }

    public function isAvailable(): bool
    {
        if (!Platform::isMacOS()) {
            return false;
        }

        // Check if the database file exists and is readable
        return is_file($this->dbPath) && is_readable($this->dbPath);
    }

    public function poll(StateStore $state): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $lastRowId = (int) $state->get('messages', 'last_row_id', 0);

        try {
            $db = new \SQLite3($this->dbPath, SQLITE3_OPEN_READONLY);
            $db->busyTimeout(5000);
        } catch (\Throwable $e) {
            $this->log('Cannot open chat.db: ' . $e->getMessage());

            return [];
        }

        try {
            // Query new inbound messages (is_from_me = 0 means received)
            $sql = <<<SQL
SELECT
    m.ROWID,
    m.text,
    m.date AS message_date,
    m.is_from_me,
    h.id AS handle_id,
    h.service
FROM message m
LEFT JOIN handle h ON m.handle_id = h.ROWID
WHERE m.ROWID > :last_row_id
  AND m.is_from_me = 0
  AND m.text IS NOT NULL
  AND m.text != ''
ORDER BY m.ROWID ASC
LIMIT 50
SQL;

            $stmt = $db->prepare($sql);
            $stmt->bindValue(':last_row_id', $lastRowId, SQLITE3_INTEGER);
            $result = $stmt->execute();

            $events = [];
            $highestRowId = $lastRowId;

            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rowId = (int) $row['ROWID'];
                $highestRowId = max($highestRowId, $rowId);

                // Messages.app stores dates as nanoseconds since 2001-01-01
                $timestamp = $this->convertMessageDate((int) $row['message_date']);

                $sender = $row['handle_id'] ?? 'Unknown';
                $text = $row['text'] ?? '';

                // Truncate long messages for classification
                if (mb_strlen($text) > 2000) {
                    $text = mb_substr($text, 0, 2000);
                }

                $events[] = new ListenerEvent(
                    source: 'messages',
                    type: 'new_message',
                    subject: mb_substr($text, 0, 100),
                    sender: $sender,
                    body: $text,
                    timestamp: $timestamp,
                    rawId: (string) $rowId,
                    metadata: [
                        'service' => $row['service'] ?? 'iMessage',
                        'handle_id' => $sender,
                    ],
                );
            }

            $stmt->close();
            $db->close();

            if (!empty($events)) {
                $state->set('messages', 'last_row_id', $highestRowId);
                $state->set('messages', 'last_check', (new \DateTimeImmutable())->format('c'));
                $state->save();

                $this->log('Found ' . count($events) . ' new message(s), watermark updated to ' . $highestRowId);
            }

            return $events;
        } catch (\Throwable $e) {
            $this->log('Error querying chat.db: ' . $e->getMessage());
            $db->close();

            return [];
        }
    }

    /**
     * Convert Messages.app date (nanoseconds since 2001-01-01) to DateTimeImmutable.
     */
    private function convertMessageDate(int $messageDate): \DateTimeImmutable
    {
        // Messages.app uses Core Data timestamp: seconds since 2001-01-01
        // Some versions store nanoseconds (large values), others store seconds
        if ($messageDate > 1_000_000_000_000) {
            // Nanoseconds — convert to seconds
            $messageDate = (int) ($messageDate / 1_000_000_000);
        }

        // Core Data epoch: 2001-01-01 00:00:00 UTC = Unix timestamp 978307200
        $unixTimestamp = $messageDate + 978307200;

        try {
            return (new \DateTimeImmutable())->setTimestamp($unixTimestamp);
        } catch (\Throwable) {
            return new \DateTimeImmutable();
        }
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
