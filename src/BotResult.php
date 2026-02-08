<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use Dalehurley\Phpbot\Stats\TokenLedger;

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
        private ?TokenLedger $tokenLedger = null,
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

    /**
     * Get the comprehensive token ledger (multi-provider tracking).
     *
     * Returns null if no ledger was provided (e.g. legacy callers).
     */
    public function getTokenLedger(): ?TokenLedger
    {
        return $this->tokenLedger;
    }

    public function toArray(): array
    {
        $data = [
            'success' => $this->success,
            'answer' => $this->answer,
            'error' => $this->error,
            'iterations' => $this->iterations,
            'tool_calls' => $this->toolCalls,
            'token_usage' => $this->tokenUsage,
            'analysis' => $this->analysis,
        ];

        if ($this->tokenLedger !== null) {
            $data['token_ledger'] = $this->tokenLedger->toArray();
        }

        return $data;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}
