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

COMPLEXITY GUIDELINES:
- "simple": Single-action tasks that don't involve external services, file I/O, or user interaction (e.g., "echo hello", "list files in current directory")
- "medium": Tasks that require multiple steps, external tool integration, APIs, or user interaction (e.g., "send an SMS", "fetch weather data", "create a file from template")
- "complex": Multi-stage workflows, data processing, complex logic, multiple integrations (e.g., "build a PDF report from CSV", "analyze and categorize 100 images")

ESTIMATED_STEPS should reflect the actual number of discrete tool calls needed, including:
- Checking for prerequisites/credentials (get_keys)
- Gathering user input (ask_user)
- Executing main task (bash, write_file, etc.)
- Verification/finalization steps

For example, "send SMS to +1234567890 saying hello" should be estimated_steps: 3-4 (check credentials, ask for input if missing, call API, verify).

Respond with ONLY the JSON object, no additional text.
PROMPT;
    }
}
