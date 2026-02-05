<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

class BotResult
{
    public function __construct(
        private bool $success,
        private ?string $answer,
        private ?string $error,
        private int $iterations,
        private array $toolCalls,
        private array $tokenUsage,
        private array $analysis,
        private array $learnedAbilities = []
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getAnswer(): ?string
    {
        return $this->answer;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getIterations(): int
    {
        return $this->iterations;
    }

    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    public function getTokenUsage(): array
    {
        return $this->tokenUsage;
    }

    public function getAnalysis(): array
    {
        return $this->analysis;
    }

    public function getLearnedAbilities(): array
    {
        return $this->learnedAbilities;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'answer' => $this->answer,
            'error' => $this->error,
            'iterations' => $this->iterations,
            'tool_calls' => $this->toolCalls,
            'token_usage' => $this->tokenUsage,
            'analysis' => $this->analysis,
            'learned_abilities' => $this->learnedAbilities,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}
