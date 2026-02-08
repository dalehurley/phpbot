<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Conversation;

use Dalehurley\Phpbot\Apple\SmallModelClient;
use Dalehurley\Phpbot\BotResult;
use Dalehurley\Phpbot\Stats\TokenLedger;

/**
 * Generates Layer 2 conversation summaries using Apple FM / Haiku.
 *
 * Called after each Bot::run() to produce a concise summary of what
 * happened during the agent execution — tools used, files read/written,
 * key decisions, errors, and the final outcome.
 */
class ConversationSummarizer
{
    /** @var callable|null */
    private $logger = null;

    public function __construct(
        private SmallModelClient $smallModel,
        private ?TokenLedger $ledger = null,
    ) {}

    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Generate a concise summary of a bot run for conversation context.
     *
     * Returns null if summarization fails (the turn will still be stored
     * without a summary — Layer 1 still works).
     */
    public function summarize(string $userInput, BotResult $result): ?string
    {
        $toolCalls = $result->getToolCalls();
        $analysis = $result->getAnalysis();

        // Build a compact representation of the run for the summarizer
        $runDescription = $this->buildRunDescription($userInput, $result, $toolCalls, $analysis);

        $prompt = <<<PROMPT
Summarize this agent execution for future conversation context. Be extremely concise (2-3 sentences max).

Include:
- What tools were used and key actions taken
- Any files read, created, or modified
- Key decisions or findings
- Errors encountered (if any)
- The final outcome

{$runDescription}

Summary:
PROMPT;

        try {
            $summary = $this->smallModel->call(
                prompt: $prompt,
                maxTokens: 200,
                purpose: 'conversation_summary',
                instructions: 'You are a concise summarizer. Output only the summary, no preamble.',
            );

            $summary = trim($summary);

            if ($summary === '') {
                return null;
            }

            $this->log("Generated conversation summary ({$this->estimateTokens($summary)} tokens)");

            return $summary;
        } catch (\Throwable $e) {
            $this->log("Summary generation failed: {$e->getMessage()}");

            // Fallback: build a simple deterministic summary
            return $this->buildFallbackSummary($result, $toolCalls);
        }
    }

    /**
     * Build a compact description of the run for the LLM summarizer.
     */
    private function buildRunDescription(string $userInput, BotResult $result, array $toolCalls, array $analysis): string
    {
        $lines = [];

        $lines[] = "User request: \"{$userInput}\"";

        // Outcome
        if ($result->isSuccess()) {
            $answer = $result->getAnswer() ?? '';
            $answerPreview = mb_substr($answer, 0, 500);
            if (mb_strlen($answer) > 500) {
                $answerPreview .= '...';
            }
            $lines[] = "Outcome: Success";
            $lines[] = "Answer: {$answerPreview}";
        } else {
            $lines[] = "Outcome: Failed — {$result->getError()}";
        }

        // Iterations and tokens
        $lines[] = "Iterations: {$result->getIterations()}";
        $tokens = $result->getTokenUsage();
        $lines[] = "Tokens: {$tokens['total']} ({$tokens['input']} in / {$tokens['output']} out)";

        // Tool calls summary
        if (!empty($toolCalls)) {
            $toolNames = array_count_values(array_column($toolCalls, 'tool'));
            $toolParts = [];
            foreach ($toolNames as $name => $count) {
                $toolParts[] = $count > 1 ? "{$name}({$count}x)" : $name;
            }
            $lines[] = "Tools used: " . implode(', ', $toolParts);

            // Include brief tool call details (first 5)
            foreach (array_slice($toolCalls, 0, 5) as $tc) {
                $tool = $tc['tool'] ?? 'unknown';
                $inputPreview = mb_substr(json_encode($tc['input'] ?? []), 0, 150);
                $lines[] = "  - {$tool}: {$inputPreview}";
            }
            if (count($toolCalls) > 5) {
                $lines[] = "  - ... and " . (count($toolCalls) - 5) . " more calls";
            }
        }

        // Complexity
        $complexity = $analysis['complexity'] ?? 'unknown';
        $lines[] = "Complexity: {$complexity}";

        return implode("\n", $lines);
    }

    /**
     * Build a simple deterministic summary when LLM summarization fails.
     */
    private function buildFallbackSummary(BotResult $result, array $toolCalls): string
    {
        $parts = [];

        if ($result->isSuccess()) {
            $parts[] = 'Completed successfully.';
        } else {
            $parts[] = "Failed: {$result->getError()}.";
        }

        $parts[] = "{$result->getIterations()} iterations.";

        if (!empty($toolCalls)) {
            $toolNames = array_count_values(array_column($toolCalls, 'tool'));
            $toolParts = [];
            foreach ($toolNames as $name => $count) {
                $toolParts[] = $count > 1 ? "{$name}({$count}x)" : $name;
            }
            $parts[] = 'Tools: ' . implode(', ', $toolParts) . '.';
        }

        return implode(' ', $parts);
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
