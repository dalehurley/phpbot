<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Apple;

use ClaudeAgents\Agent;
use Dalehurley\Phpbot\Stats\TokenLedger;

/**
 * Cheap cloud-based fallback for the SmallModelClient interface.
 *
 * Uses Claude Haiku (or another small model) via the Anthropic API
 * when Apple FM is not available (non-macOS, older macOS, etc.).
 *
 * This ensures that all intelligence features (skill filtering,
 * tool result summarization, context compaction, simple agent)
 * still work on any platform — just at a small per-call cost
 * instead of free on-device.
 *
 * Typical cost: ~$0.001-0.003 per call with Haiku.
 */
class HaikuModelClient implements SmallModelClient
{
    /** Optional logging callback. */
    private ?\Closure $logger = null;

    /**
     * @param \Closure(): \ClaudePhp\ClaudePhp $clientFactory Factory for Anthropic client
     * @param string $model The small model name (e.g. 'claude-haiku-4-5')
     * @param TokenLedger|null $ledger Optional token usage tracker
     */
    public function __construct(
        private \Closure $clientFactory,
        private string $model,
        private ?TokenLedger $ledger = null,
    ) {}

    /**
     * Set an optional logger.
     */
    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    // -------------------------------------------------------------------------
    // SmallModelClient API
    // -------------------------------------------------------------------------

    /**
     * General-purpose text completion via Claude Haiku.
     */
    public function call(string $prompt, int $maxTokens = 512, string $purpose = 'general', ?string $instructions = null): string
    {
        $systemPrompt = $instructions ?? 'You are a helpful assistant. Be concise.';

        $this->log("Haiku call ({$purpose}): " . strlen($prompt) . ' chars');

        $client = ($this->clientFactory)();

        $agent = Agent::create($client)
            ->withName("small_model_{$purpose}")
            ->withSystemPrompt($systemPrompt)
            ->withModel($this->model)
            ->maxIterations(1)
            ->maxTokens($maxTokens);

        $result = $agent->run($prompt);

        // Record usage in ledger
        $tokens = $result->getTokenUsage();
        $inputTokens = $tokens['input'] ?? 0;
        $outputTokens = $tokens['output'] ?? 0;
        $this->ledger?->record('anthropic', $purpose, $inputTokens, $outputTokens);

        return $result->getAnswer();
    }

    /**
     * Summarize content via Claude Haiku.
     */
    public function summarize(string $content, string $context, int $maxTokens = 256): string
    {
        $instructions = 'You are a concise summarizer for an AI assistant. '
            . 'Preserve: error codes, exit codes, file paths, key data values, structural information, counts, sizes. '
            . 'Remove: repetitive content, verbose formatting, redundant whitespace, decorative text. '
            . 'Output only the summary, no preamble.';

        $prompt = "Context: {$context}\n\nContent (" . strlen($content) . " chars):\n{$content}";

        return $this->call($prompt, $maxTokens, 'summarization', $instructions);
    }

    /**
     * Classify a request via Claude Haiku, returning JSON.
     */
    public function classify(string $prompt, int $maxTokens = 256): string
    {
        $instructions = 'You are a task classifier. Respond with only valid JSON. '
            . 'Do not include any text before or after the JSON.';

        return $this->call($prompt, $maxTokens, 'classification', $instructions);
    }

    /**
     * Always available as long as an API key is configured.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
