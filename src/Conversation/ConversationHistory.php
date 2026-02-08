<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Conversation;

/**
 * Manages conversation history across multiple Bot::run() calls.
 *
 * Stores turns at all three layers and builds context strings suitable
 * for injection into the agent's enhanced prompt.
 */
class ConversationHistory
{
    /** @var ConversationTurn[] */
    private array $turns = [];

    private ConversationLayer $activeLayer;

    /** Per-layer max-turn overrides (from config). */
    private array $maxTurns;

    public function __construct(
        ConversationLayer $defaultLayer = ConversationLayer::Summarized,
        array $maxTurns = [],
    ) {
        $this->activeLayer = $defaultLayer;
        $this->maxTurns = $maxTurns;
    }

    // -----------------------------------------------------------------
    // Mutation
    // -----------------------------------------------------------------

    public function addTurn(ConversationTurn $turn): void
    {
        $this->turns[] = $turn;
    }

    /**
     * Replace the last turn (e.g. after the summary has been generated).
     */
    public function replaceLastTurn(ConversationTurn $turn): void
    {
        if (!empty($this->turns)) {
            $this->turns[count($this->turns) - 1] = $turn;
        }
    }

    public function clear(): void
    {
        $this->turns = [];
    }

    // -----------------------------------------------------------------
    // Layer management
    // -----------------------------------------------------------------

    public function getActiveLayer(): ConversationLayer
    {
        return $this->activeLayer;
    }

    public function setActiveLayer(ConversationLayer $layer): void
    {
        $this->activeLayer = $layer;
    }

    // -----------------------------------------------------------------
    // Queries
    // -----------------------------------------------------------------

    public function getTurnCount(): int
    {
        return count($this->turns);
    }

    public function isEmpty(): bool
    {
        return empty($this->turns);
    }

    /**
     * Get a specific turn (1-indexed from most recent, so 1 = last turn).
     */
    public function getTurn(int $recencyIndex): ?ConversationTurn
    {
        if ($recencyIndex < 1 || $recencyIndex > count($this->turns)) {
            return null;
        }

        return $this->turns[count($this->turns) - $recencyIndex];
    }

    /** @return ConversationTurn[] */
    public function getTurns(): array
    {
        return $this->turns;
    }

    // -----------------------------------------------------------------
    // Context building — produces a string for the enhanced prompt
    // -----------------------------------------------------------------

    /**
     * Build a context string for injection into the user message.
     *
     * Returns an empty string when there is no history.
     */
    public function buildContextBlock(?ConversationLayer $layer = null, ?int $maxTurns = null): string
    {
        if (empty($this->turns)) {
            return '';
        }

        $layer ??= $this->activeLayer;
        $limit = $maxTurns ?? ($this->maxTurns[$layer->value] ?? $layer->defaultMaxTurns());

        // Take the most recent $limit turns
        $relevant = array_slice($this->turns, -$limit);

        $lines = ["## Previous Conversation\n"];

        foreach ($relevant as $i => $turn) {
            $turnNum = $i + 1;
            $lines[] = $this->formatTurn($turn, $turnNum, $layer);
        }

        return implode("\n", $lines);
    }

    /**
     * Build a full-detail block for a single turn (always Layer 3 data).
     */
    public function buildTurnDetailBlock(int $recencyIndex): string
    {
        $turn = $this->getTurn($recencyIndex);

        if ($turn === null) {
            return "Turn {$recencyIndex} not found (only {$this->getTurnCount()} turns in history).";
        }

        $lines = ["## Turn Detail (#{$recencyIndex} most recent)\n"];
        $lines[] = "**User:** {$turn->userInput}";

        if ($turn->answer !== null) {
            $lines[] = "**Answer:** {$turn->answer}";
        }
        if ($turn->error !== null) {
            $lines[] = "**Error:** {$turn->error}";
        }
        if ($turn->summary !== null) {
            $lines[] = "**Summary:** {$turn->summary}";
        }

        $lines[] = "**Tools:** {$turn->toolSummary()}";

        $meta = $turn->metadata;
        if (!empty($meta)) {
            $iterations = $meta['iterations'] ?? '?';
            $tokens = $meta['token_usage']['total'] ?? '?';
            $lines[] = "**Iterations:** {$iterations} | **Tokens:** {$tokens}";
        }

        // Include full messages for Layer 3 inspection
        if (!empty($turn->fullMessages)) {
            $lines[] = "\n### Full Message History";
            foreach ($turn->fullMessages as $msg) {
                $role = $msg['role'] ?? 'unknown';
                $content = $this->extractTextContent($msg['content'] ?? '');
                $preview = mb_substr($content, 0, 500);
                if (mb_strlen($content) > 500) {
                    $preview .= '...';
                }
                $lines[] = "[{$role}] {$preview}";
            }
        }

        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------
    // Serialization
    // -----------------------------------------------------------------

    public function toArray(): array
    {
        return [
            'active_layer' => $this->activeLayer->value,
            'turn_count' => count($this->turns),
            'turns' => array_map(fn(ConversationTurn $t) => $t->toArray(), $this->turns),
        ];
    }

    // -----------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------

    private function formatTurn(ConversationTurn $turn, int $num, ConversationLayer $layer): string
    {
        $lines = [];

        // Truncate long user inputs for context (keep it concise)
        $userPreview = mb_substr($turn->userInput, 0, 300);
        if (mb_strlen($turn->userInput) > 300) {
            $userPreview .= '...';
        }
        $lines[] = "[Turn {$num}] User: \"{$userPreview}\"";

        // Answer or error
        if ($turn->answer !== null) {
            $answerPreview = mb_substr($turn->answer, 0, 500);
            if (mb_strlen($turn->answer) > 500) {
                $answerPreview .= '...';
            }
            $lines[] = "Assistant: {$answerPreview}";
        } elseif ($turn->error !== null) {
            $lines[] = "Assistant [ERROR]: {$turn->error}";
        }

        // Layer 2: add summary
        if ($layer !== ConversationLayer::Basic && $turn->summary !== null) {
            $lines[] = "[Summary: {$turn->summary}]";
        }

        // Layer 3: add full messages (compacted preview)
        if ($layer === ConversationLayer::Full && !empty($turn->fullMessages)) {
            $msgCount = count($turn->fullMessages);
            $toolCount = count($turn->toolCalls);
            $lines[] = "[Full context: {$msgCount} messages, {$toolCount} tool calls]";

            // Include a compact view of tool calls
            foreach (array_slice($turn->toolCalls, 0, 5) as $tc) {
                $tool = $tc['tool'] ?? 'unknown';
                $inputPreview = mb_substr(json_encode($tc['input'] ?? []), 0, 120);
                $lines[] = "  > {$tool}: {$inputPreview}";
            }
            if ($toolCount > 5) {
                $lines[] = "  > ... and " . ($toolCount - 5) . " more tool calls";
            }
        }

        $lines[] = ''; // Blank line between turns

        return implode("\n", $lines);
    }

    /**
     * Extract text from a message content value (string or content blocks array).
     */
    private function extractTextContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return '';
        }

        $texts = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $texts[] = $block;
            } elseif (is_array($block)) {
                $type = $block['type'] ?? '';
                if ($type === 'text') {
                    $texts[] = $block['text'] ?? '';
                } elseif ($type === 'tool_use') {
                    $texts[] = "[tool_use: {$block['name']}]";
                } elseif ($type === 'tool_result') {
                    $resultContent = $block['content'] ?? '';
                    $preview = is_string($resultContent) ? mb_substr($resultContent, 0, 100) : '[complex]';
                    $texts[] = "[tool_result: {$preview}]";
                }
            }
        }

        return implode(' ', $texts);
    }
}
