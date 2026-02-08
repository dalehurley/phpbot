<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Conversation;

/**
 * Defines the three layers of conversation context.
 *
 * Each layer adds more detail at the cost of more tokens:
 *   Basic      ~200 tokens/turn  (user requests + final answers)
 *   Summarized ~500 tokens/turn  (basic + tool/iteration summaries via Apple FM)
 *   Full       ~2000+ tokens/turn (complete message history from the agent run)
 */
enum ConversationLayer: string
{
    case Basic = 'basic';
    case Summarized = 'summarized';
    case Full = 'full';

    /**
     * Default max turns to inject for this layer (token budget guard).
     */
    public function defaultMaxTurns(): int
    {
        return match ($this) {
            self::Basic => 20,
            self::Summarized => 10,
            self::Full => 3,
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Basic => 'Basic (requests + answers)',
            self::Summarized => 'Summarized (basic + execution summaries)',
            self::Full => 'Full (complete message history)',
        };
    }
}
