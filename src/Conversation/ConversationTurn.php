<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Conversation;

/**
 * Value object representing a single conversation turn (one Bot::run() cycle).
 *
 * Captures data at all three layers so any layer can be served on demand:
 *   Layer 1 (Basic):      userInput + answer/error
 *   Layer 2 (Summarized): Layer 1 + summary
 *   Layer 3 (Full):       Layer 1 + fullMessages (raw agent context)
 */
class ConversationTurn
{
    public function __construct(
        public readonly string $userInput,
        public readonly ?string $answer = null,
        public readonly ?string $error = null,
        public readonly ?string $summary = null,
        public readonly array $toolCalls = [],
        public readonly array $fullMessages = [],
        public readonly array $metadata = [],
        public readonly float $timestamp = 0.0,
    ) {}

    /**
     * Create a turn with a summary added after initial construction.
     */
    public function withSummary(string $summary): self
    {
        return new self(
            userInput: $this->userInput,
            answer: $this->answer,
            error: $this->error,
            summary: $summary,
            toolCalls: $this->toolCalls,
            fullMessages: $this->fullMessages,
            metadata: $this->metadata,
            timestamp: $this->timestamp,
        );
    }

    /**
     * Whether the turn completed successfully.
     */
    public function isSuccess(): bool
    {
        return $this->answer !== null && $this->error === null;
    }

    /**
     * Brief tool list for display (e.g. "bash(3), read_file(2)").
     */
    public function toolSummary(): string
    {
        if (empty($this->toolCalls)) {
            return '(no tools)';
        }

        $counts = array_count_values(array_column($this->toolCalls, 'tool'));
        $parts = [];
        foreach ($counts as $name => $count) {
            $parts[] = $count > 1 ? "{$name}({$count})" : $name;
        }

        return implode(', ', $parts);
    }

    public function toArray(): array
    {
        return [
            'user_input' => $this->userInput,
            'answer' => $this->answer,
            'error' => $this->error,
            'summary' => $this->summary,
            'tool_calls' => $this->toolCalls,
            'full_messages' => $this->fullMessages,
            'metadata' => $this->metadata,
            'timestamp' => $this->timestamp,
        ];
    }
}
