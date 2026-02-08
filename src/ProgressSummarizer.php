<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use ClaudeAgents\Agent;
use Dalehurley\Phpbot\Apple\SmallModelClient;
use Dalehurley\Phpbot\Stats\TokenLedger;

/**
 * Creates concise summaries of agent actions for progress display.
 *
 * Prefers Apple FM (free, on-device) when available, falling back
 * to Anthropic Haiku. Records all usage in the TokenLedger.
 */
class ProgressSummarizer
{
    /**
     * @param \Closure(): \ClaudePhp\ClaudePhp $clientFactory
     */
    public function __construct(
        private \Closure $clientFactory,
        private string $model,
        private string $fastModel = '',
        private ?SmallModelClient $appleFM = null,
        private ?TokenLedger $ledger = null,
    ) {}

    public function summarizeBefore(string $input, array $analysis): string
    {
        $payload = json_encode(['input' => $input, 'analysis' => $analysis]);
        $prompt = "Summarize the intended plan in 1-2 short sentences based on this JSON:\n{$payload}";

        // Prefer Apple FM (free, on-device)
        if ($this->appleFM !== null && $this->appleFM->isAvailable()) {
            try {
                return trim($this->appleFM->call($prompt, 256, 'progress'));
            } catch (\Throwable) {
                // Fall through to Anthropic
            }
        }

        // Fallback: Anthropic Haiku
        return $this->callAnthropic(
            'nano_summary_before',
            'You are a concise assistant. Summarize the intended plan in 1-2 short sentences.',
            "Summarize the plan based on this JSON:\n{$payload}",
            256,
        );
    }

    public function summarizeAfter(string $input, array $analysis, $result): string
    {
        $payload = json_encode([
            'input' => $input,
            'analysis' => $analysis,
            'success' => $result->isSuccess(),
            'answer' => $result->getAnswer(),
            'error' => $result->getError(),
            'iterations' => $result->getIterations(),
            'tool_calls' => $result->getToolCalls(),
        ]);
        $prompt = "Summarize what happened and the outcome in 1-2 short sentences based on this JSON:\n{$payload}";

        // Prefer Apple FM (free, on-device)
        if ($this->appleFM !== null && $this->appleFM->isAvailable()) {
            try {
                return trim($this->appleFM->call($prompt, 256, 'progress'));
            } catch (\Throwable) {
                // Fall through to Anthropic
            }
        }

        // Fallback: Anthropic Haiku
        return $this->callAnthropic(
            'nano_summary_after',
            'You are a concise assistant. Summarize what happened and the outcome in 1-2 short sentences.',
            "Summarize the execution based on this JSON:\n{$payload}",
            256,
        );
    }

    public function summarizeIteration(string $text): string
    {
        $prompt = "Summarize this assistant message in 1 short sentence focused on intent or next action. Do not include chain-of-thought:\n{$text}";

        // Prefer Apple FM (free, on-device)
        if ($this->appleFM !== null && $this->appleFM->isAvailable()) {
            try {
                return trim($this->appleFM->call($prompt, 128, 'progress'));
            } catch (\Throwable) {
                // Fall through to Anthropic
            }
        }

        // Fallback: Anthropic Haiku
        return $this->callAnthropic(
            'nano_iteration_summary',
            'You are a concise assistant. Summarize the assistant message in 1 short sentence focused on intent or next action. Do not include chain-of-thought.',
            "Summarize this assistant message:\n{$text}",
            128,
        );
    }

    // -------------------------------------------------------------------------
    // Anthropic fallback
    // -------------------------------------------------------------------------

    private function callAnthropic(string $name, string $systemPrompt, string $prompt, int $maxTokens): string
    {
        try {
            $client = ($this->clientFactory)();

            $summaryAgent = Agent::create($client)
                ->withName($name)
                ->withSystemPrompt($systemPrompt)
                ->withModel($this->getPreferredModel())
                ->maxIterations(1)
                ->maxTokens($maxTokens)
                ->temperature(0.2);

            $result = $summaryAgent->run($prompt);
            $answer = trim((string) $result->getAnswer());

            // Record Anthropic usage in ledger
            $tokens = $result->getTokenUsage();
            $this->ledger?->record(
                'anthropic',
                'progress',
                $tokens['input'] ?? 0,
                $tokens['output'] ?? 0,
            );

            return $answer;
        } catch (\Throwable) {
            return '';
        }
    }

    private function getPreferredModel(): string
    {
        if ($this->fastModel !== '') {
            return $this->fastModel;
        }

        return $this->model;
    }
}
