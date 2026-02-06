<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use ClaudeAgents\Agent;

class TaskAnalyzer
{
    /**
     * @param \Closure(): \ClaudePhp\ClaudePhp $clientFactory
     */
    public function __construct(
        private \Closure $clientFactory,
        private string $model
    ) {}

    public function analyze(string $input): array
    {
        $client = ($this->clientFactory)();

        $analysisAgent = Agent::create($client)
            ->withName('task_analyzer')
            ->withSystemPrompt($this->getSystemPrompt())
            ->withModel($this->model)
            ->maxIterations(1)
            ->maxTokens(2048);

        $result = $analysisAgent->run("Analyze this task and respond with JSON only:\n\n{$input}");

        $analysis = json_decode($result->getAnswer(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->fallbackAnalysis($input);
        }

        return $analysis;
    }

    private function fallbackAnalysis(string $input): array
    {
        $lower = strtolower($input);

        return [
            'task_type' => 'general',
            'complexity' => 'medium',
            'requires_bash' => str_contains($lower, 'run') ||
                str_contains($lower, 'execute') ||
                str_contains($lower, 'command'),
            'requires_file_ops' => str_contains($lower, 'file') ||
                str_contains($lower, 'read') ||
                str_contains($lower, 'write'),
            'requires_tool_creation' => str_contains($lower, 'create tool') ||
                str_contains($lower, 'new tool') ||
                str_contains($lower, 'build tool'),
            'definition_of_done' => ['Task completed successfully'],
            'suggested_approach' => 'direct',
            'estimated_steps' => 1,
        ];
    }

    private function getSystemPrompt(): string
    {
        return <<<PROMPT
You are a task analyzer. Analyze the user's request and output a JSON object with the following structure:

{
    "task_type": "general|coding|research|automation|data_processing|problem_solving",
    "complexity": "simple|medium|complex",
    "requires_bash": true/false,
    "requires_file_ops": true/false,
    "requires_tool_creation": true/false,
    "requires_planning": true/false,
    "requires_reflection": true/false,
    "definition_of_done": ["list", "of", "completion", "criteria"],
    "suggested_approach": "direct|plan_execute|reflection|chain_of_thought",
    "estimated_steps": number,
    "potential_tools_needed": ["bash", "file_system", "custom_tool_name"]
}

Respond with ONLY the JSON object, no additional text.
PROMPT;
    }
}
