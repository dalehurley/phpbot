<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Listener;

/**
 * Value object representing an event detected by a watcher.
 */
class ListenerEvent
{
    public function __construct(
        /** Source watcher name: mail, calendar, messages, notifications */
        public readonly string $source,
        /** Event type: new_email, upcoming_event, new_event, new_message, notification */
        public readonly string $type,
        /** Subject or title of the event */
        public readonly string $subject,
        /** Sender, organizer, or origin of the event */
        public readonly string $sender,
        /** Body or content preview */
        public readonly string $body,
        /** When the event occurred or was received */
        public readonly \DateTimeImmutable $timestamp,
        /** Raw identifier from the source (message ID, event UID, row ID, etc.) */
        public readonly string $rawId,
        /** Additional source-specific metadata */
        public readonly array $metadata = [],
    ) {}

    /**
     * Build a concise summary suitable for LLM classification.
     */
    public function toSummary(): string
    {
        $parts = [
            "Source: {$this->source}",
            "Type: {$this->type}",
            "From: {$this->sender}",
            "Subject: {$this->subject}",
        ];

        if ($this->body !== '') {
            $preview = mb_strlen($this->body) > 500
                ? mb_substr($this->body, 0, 500) . '...'
                : $this->body;
            $parts[] = "Body: {$preview}";
        }

        $parts[] = 'Date: ' . $this->timestamp->format('Y-m-d H:i:s');

        return implode("\n", $parts);
    }

    /**
     * Serialize to an array for logging/storage.
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'type' => $this->type,
            'subject' => $this->subject,
            'sender' => $this->sender,
            'body' => $this->body,
            'timestamp' => $this->timestamp->format('c'),
            'raw_id' => $this->rawId,
            'metadata' => $this->metadata,
        ];
    }
}
