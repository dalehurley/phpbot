<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Apple;

/**
 * Interface for a small/cheap model client used for auxiliary tasks.
 *
 * Implementations:
 * - AppleFMClient: Free on-device Apple Intelligence (macOS 26+)
 * - HaikuModelClient: Cheap cloud fallback via Claude Haiku
 *
 * Used for tasks that need a lightweight model rather than the full
 * Claude Sonnet/Opus: skill relevance filtering, tool result
 * summarization, context compaction, classification, and simple
 * bash-only task execution.
 */
interface SmallModelClient
{
    /**
     * General-purpose text completion.
     *
     * @param string $prompt The prompt to send
     * @param int $maxTokens Maximum tokens for the response
     * @param string $purpose Purpose label for the token ledger
     * @param string|null $instructions Optional system instructions
     * @return string The model's text response
     */
    public function call(string $prompt, int $maxTokens = 512, string $purpose = 'general', ?string $instructions = null): string;

    /**
     * Summarize content with context about what it is and why it matters.
     *
     * @param string $content The content to summarize
     * @param string $context Context about the content (tool name, purpose, etc.)
     * @param int $maxTokens Maximum tokens for the summary
     * @return string The summarized content
     */
    public function summarize(string $content, string $context, int $maxTokens = 256): string;

    /**
     * Classify a request, returning JSON.
     *
     * @param string $prompt The classification prompt
     * @param int $maxTokens Maximum tokens for the response
     * @return string Raw JSON text response
     */
    public function classify(string $prompt, int $maxTokens = 256): string;

    /**
     * Check if this model client is available and ready to use.
     */
    public function isAvailable(): bool;

    /**
     * Set an optional logger.
     */
    public function setLogger(?\Closure $logger): void;
}
