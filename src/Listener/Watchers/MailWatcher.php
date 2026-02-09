<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Listener\Watchers;

use Dalehurley\Phpbot\Apple\AppleScriptRunner;
use Dalehurley\Phpbot\Listener\ListenerEvent;
use Dalehurley\Phpbot\Listener\StateStore;
use Dalehurley\Phpbot\Platform;

/**
 * Watches for new emails in Apple Mail via AppleScript.
 *
 * Tracks the highest seen message ID as a watermark to detect
 * genuinely new emails on each poll.
 */
class MailWatcher implements WatcherInterface
{
    private AppleScriptRunner $runner;
    private int $limit;

    /** @var \Closure|null */
    private ?\Closure $logger;

    public function __construct(AppleScriptRunner $runner, int $limit = 50)
    {
        $this->runner = $runner;
        $this->limit = $limit;
        $this->logger = null;
    }

    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'mail';
    }

    public function isAvailable(): bool
    {
        return Platform::isMacOS();
    }

    public function poll(StateStore $state): array
    {
        $lastSeenId = (int) $state->get('mail', 'last_message_id', 0);

        // Fetch recent emails
        $mailbox = 'INBOX';
        $mailboxSafe = $this->runner->escapeAppleScript($mailbox);

        $script = <<<APPLESCRIPT
tell application "Mail"
    set output to ""
    set msgList to messages of mailbox "{$mailboxSafe}" of (first account)
    if (count of msgList) < {$this->limit} then
        set maxCount to (count of msgList)
    else
        set maxCount to {$this->limit}
    end if
    repeat with i from 1 to maxCount
        set m to item i of msgList
        set output to output & (id of m) & "\t" & (subject of m) & "\t" & (sender of m) & "\t" & (date received of m) & "\t" & (read status of m) & linefeed
    end repeat
    return output
end tell
APPLESCRIPT;

        $result = $this->runner->runOsascript($script, 30);

        if ($result === null || $result['exit_code'] !== 0) {
            $this->log('Mail poll failed or permission denied');

            return [];
        }

        $emails = $this->runner->parseTsvOutput($result['stdout'], ['id', 'subject', 'sender', 'date', 'read']);

        if (empty($emails)) {
            return [];
        }

        // Filter to only new emails (ID > watermark)
        $newEmails = [];
        $highestId = $lastSeenId;

        foreach ($emails as $email) {
            $id = (int) $email['id'];
            if ($id > $lastSeenId) {
                $newEmails[] = $email;
                $highestId = max($highestId, $id);
            }
        }

        if (empty($newEmails)) {
            return [];
        }

        // Fetch body for each new email (limit to first 10 to avoid overload)
        $events = [];
        $fetchLimit = min(count($newEmails), 10);

        for ($i = 0; $i < $fetchLimit; $i++) {
            $email = $newEmails[$i];
            $body = $this->fetchEmailBody((int) $email['id']);

            try {
                $timestamp = new \DateTimeImmutable($email['date']);
            } catch (\Throwable) {
                $timestamp = new \DateTimeImmutable();
            }

            $events[] = new ListenerEvent(
                source: 'mail',
                type: 'new_email',
                subject: $email['subject'],
                sender: $email['sender'],
                body: $body,
                timestamp: $timestamp,
                rawId: $email['id'],
                metadata: [
                    'read' => $email['read'] ?? 'false',
                    'mailbox' => $mailbox,
                ],
            );
        }

        // Update watermark
        $state->set('mail', 'last_message_id', $highestId);
        $state->set('mail', 'last_check', (new \DateTimeImmutable())->format('c'));
        $state->save();

        $this->log("Found " . count($events) . " new email(s), watermark updated to {$highestId}");

        return $events;
    }

    /**
     * Fetch the body content of a single email by ID.
     */
    private function fetchEmailBody(int $messageId): string
    {
        $script = <<<APPLESCRIPT
tell application "Mail"
    set m to first message of mailbox "INBOX" of (first account) whose id is {$messageId}
    return content of m
end tell
APPLESCRIPT;

        $result = $this->runner->runOsascript($script, 15);

        if ($result === null || $result['exit_code'] !== 0) {
            return '';
        }

        // Truncate to first 2000 chars for classification purposes
        $body = $result['stdout'];
        if (mb_strlen($body) > 2000) {
            $body = mb_substr($body, 0, 2000);
        }

        return $body;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
