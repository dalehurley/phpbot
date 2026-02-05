<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;

class AskUserTool implements ToolInterface
{
    public function getName(): string
    {
        return 'ask_user';
    }

    public function getDescription(): string
    {
        return 'Prompt the user for missing information during a run. Use when required data is not available.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'Question to ask the user',
                ],
                'default' => [
                    'type' => 'string',
                    'description' => 'Optional default value if user enters nothing',
                ],
            ],
            'required' => ['question'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $question = trim((string) ($input['question'] ?? ''));
        $default = (string) ($input['default'] ?? '');

        if ($question === '') {
            return ToolResult::error('Question is required.');
        }

        $prompt = $default !== '' ? "{$question} [{$default}]: " : "{$question}: ";
        $answer = $this->prompt($prompt);

        if ($answer === false) {
            return ToolResult::error('User input not available.');
        }

        $answer = trim($answer);
        if ($answer === '' && $default !== '') {
            $answer = $default;
        }

        return ToolResult::success(json_encode([
            'question' => $question,
            'answer' => $answer,
        ]));
    }

    public function toDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'input_schema' => $this->getInputSchema(),
        ];
    }

    private function prompt(string $prompt): string|false
    {
        if (function_exists('readline')) {
            $line = readline($prompt);
            if ($line !== false) {
                readline_add_history($line);
            }
            return $line;
        }

        echo $prompt;
        return fgets(STDIN);
    }
}
