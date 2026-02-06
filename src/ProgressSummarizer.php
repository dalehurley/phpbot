<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use ClaudeAgents\Agent;

class ProgressSummarizer
{
    /**
     * @param \Closure(): \ClaudePhp\ClaudePhp $clientFactory
     */
    public function __construct(
        private \Closure $clientFactory,
        private string $model,
        private string $fastModel = ''
    ) {}

    public function summarizeBefore(string $input, array $analysis): string
    {
        try {
            $client = ($this->clientFactory)();

            $summaryAgent = Agent::create($client)
                ->withName('nano_summary_before')
                ->withSystemPrompt('You are a concise assistant. Summarize the intended plan in 1-2 short sentences.')
                ->withModel($this->getPreferredModel())
                ->maxIterations(1)
                ->maxTokens(256)
                ->temperature(0.2);

            $payload = [
                'input' => $input,
                'analysis' => $analysis,
            ];

            $result = $summaryAgent->run("Summarize the plan based on this JSON:\n" . json_encode($payload));
            return trim((string) $result->getAnswer());
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function summarizeAfter(string $input, array $analysis, $result): string
    {
        try {
            $client = ($this->clientFactory)();

            $summaryAgent = Agent::create($client)
                ->withName('nano_summary_after')
                ->withSystemPrompt('You are a concise assistant. Summarize what happened and the outcome in 1-2 short sentences.')
                ->withModel($this->getPreferredModel())
                ->maxIterations(1)
                ->maxTokens(256)
                ->temperature(0.2);

            $payload = [
                'input' => $input,
                'analysis' => $analysis,
                'success' => $result->isSuccess(),
                'answer' => $result->getAnswer(),
                'error' => $result->getError(),
                'iterations' => $result->getIterations(),
                'tool_calls' => $result->getToolCalls(),
            ];

            $prompt = "Summarize the execution based on this JSON:\n" . json_encode($payload);
            $summary = $summaryAgent->run($prompt);
            return trim((string) $summary->getAnswer());
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function summarizeIteration(string $text): string
    {
        try {
            $client = ($this->clientFactory)();

            $summaryAgent = Agent::create($client)
                ->withName('nano_iteration_summary')
                ->withSystemPrompt('You are a concise assistant. Summarize the assistant message in 1 short sentence focused on intent or next action. Do not include chain-of-thought.')
                ->withModel($this->getPreferredModel())
                ->maxIterations(1)
                ->maxTokens(128)
                ->temperature(0.2);

            $prompt = "Summarize this assistant message:\n" . $text;
            $summary = $summaryAgent->run($prompt);
            return trim((string) $summary->getAnswer());
        } catch (\Throwable $e) {
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
