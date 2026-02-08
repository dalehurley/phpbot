<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Apple;

use ClaudeAgents\Context\ContextManager;
use Dalehurley\Phpbot\Stats\TokenLedger;

/**
 * Apple FM-powered context compactor.
 *
 * Extends the framework's built-in ContextManager to use Apple FM
 * for intelligent summarization of old tool results and messages,
 * instead of just truncating them to "[tool result truncated]".
 *
 * This dramatically reduces input tokens sent to Claude on subsequent
 * iterations while preserving the information the agent needs.
 */
class AppleFMContextCompactor extends ContextManager
{
    private ?SmallModelClient $appleFM;
    private ?TokenLedger $ledger;

    /** Track which tool_use_ids we've already compacted to avoid re-summarizing. */
    private array $compactedIds = [];

    public function __construct(
        ?SmallModelClient $appleFM = null,
        ?TokenLedger $ledger = null,
        int $maxContextTokens = 80000,
        array $options = [],
    ) {
        $this->appleFM = $appleFM;
        $this->ledger = $ledger;

        // Use aggressive defaults: compact at 50% to keep context lean
        $options = array_merge([
            'compact_threshold' => 0.50,
            'auto_compact' => true,
            'clear_tool_results' => false, // We do our own smarter compaction
        ], $options);

        parent::__construct($maxContextTokens, $options);
    }

    /**
     * Compact messages using Apple FM summarization.
     *
     * Strategy:
     * - Keep the most recent 2 tool_use/tool_result pairs intact (agent needs them)
     * - Summarize older tool results via Apple FM (or light compression if unavailable)
     * - Summarize long assistant reasoning text from older iterations
     * - Always preserve: system message, initial user prompt, error results
     *
     * @param array<array<string, mixed>> $messages
     * @param array<array<string, mixed>> $tools
     * @return array<array<string, mixed>>
     */
    public function compactMessages(array $messages, array $tools = []): array
    {
        if ($this->fitsInContext($messages, $tools)) {
            return $messages;
        }

        // Find boundaries: keep the first 2 messages (system/user) and the
        // last 4 messages (most recent iteration pair) untouched.
        $totalMessages = count($messages);
        $keepRecent = min(4, $totalMessages);
        $keepPrefix = min(2, $totalMessages - $keepRecent);

        if ($totalMessages <= $keepPrefix + $keepRecent) {
            // Too few messages to compact meaningfully
            return $messages;
        }

        $prefix = array_slice($messages, 0, $keepPrefix);
        $middle = array_slice($messages, $keepPrefix, $totalMessages - $keepPrefix - $keepRecent);
        $suffix = array_slice($messages, -$keepRecent);

        // Compact the middle messages
        $compactedMiddle = $this->compactMiddleMessages($middle);

        $result = array_merge($prefix, $compactedMiddle, $suffix);

        // If still too large, fall back to parent's compaction
        if (!$this->fitsInContext($result, $tools)) {
            return parent::compactMessages($result, $tools);
        }

        return $result;
    }

    /**
     * Compact the middle section of messages (older iterations).
     *
     * @param array<array<string, mixed>> $messages
     * @return array<array<string, mixed>>
     */
    private function compactMiddleMessages(array $messages): array
    {
        return array_map(function (array $message) {
            $role = $message['role'] ?? '';

            // Compact tool results in user messages
            if ($role === 'user' && is_array($message['content'] ?? null)) {
                $message['content'] = $this->compactToolResults($message['content']);

                return $message;
            }

            // Compact long assistant reasoning text
            if ($role === 'assistant' && is_array($message['content'] ?? null)) {
                $message['content'] = $this->compactAssistantContent($message['content']);

                return $message;
            }

            return $message;
        }, $messages);
    }

    /**
     * Compact tool_result blocks in a user message.
     */
    private function compactToolResults(array $content): array
    {
        return array_map(function ($block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'tool_result') {
                return $block;
            }

            $toolUseId = $block['tool_use_id'] ?? '';

            // Don't re-compact already compacted results
            if (in_array($toolUseId, $this->compactedIds, true)) {
                return $block;
            }

            // Never compact errors
            if (!empty($block['is_error'])) {
                return $block;
            }

            $originalContent = $block['content'] ?? '';

            if (!is_string($originalContent) || strlen($originalContent) <= 200) {
                return $block;
            }

            // Use Apple FM if available, otherwise truncate
            $compacted = $this->compactContent($originalContent);
            $this->compactedIds[] = $toolUseId;

            $block['content'] = $compacted;

            return $block;
        }, $content);
    }

    /**
     * Compact long assistant content (reasoning text).
     */
    private function compactAssistantContent(array $content): array
    {
        return array_map(function ($block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'text') {
                return $block;
            }

            $text = $block['text'] ?? '';

            if (strlen($text) <= 300) {
                return $block;
            }

            // Summarize long reasoning text
            $block['text'] = $this->compactContent($text, 'Assistant reasoning from a previous iteration');

            return $block;
        }, $content);
    }

    /**
     * Compact a piece of content using Apple FM or truncation.
     */
    private function compactContent(string $content, string $context = 'Tool result from a previous iteration'): string
    {
        $originalLength = strlen($content);

        // Try Apple FM summarization
        if ($this->appleFM !== null && $this->appleFM->isAvailable()) {
            try {
                $summary = $this->appleFM->summarize(
                    $content,
                    "Context compaction: {$context}. Preserve key data, results, file paths, error codes. Be very concise.",
                    128,
                );

                if (strlen($summary) < $originalLength) {
                    $bytesSaved = $originalLength - strlen($summary);
                    $this->ledger?->record(
                        'apple_fm',
                        'context_compaction',
                        (int) ceil($originalLength / 4),
                        (int) ceil(strlen($summary) / 4),
                        0.0,
                        $bytesSaved,
                    );

                    return "[Compacted: {$originalLength} -> " . strlen($summary) . " chars]\n{$summary}";
                }
            } catch (\Throwable) {
                // Fall through to truncation
            }
        }

        // Fallback: simple truncation with start+end preservation
        $headLen = 150;
        $tailLen = 100;

        if ($originalLength <= $headLen + $tailLen + 50) {
            return $content; // Not worth truncating
        }

        return substr($content, 0, $headLen)
            . "\n[... " . ($originalLength - $headLen - $tailLen) . " chars omitted ...]\n"
            . substr($content, -$tailLen);
    }
}
