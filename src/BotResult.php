<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use Dalehurley\Phpbot\Stats\TokenLedger;

class BotResult
{
    /**
     * @param array<string> $createdFiles Paths of files created during this run
     */
    public function __construct(
        private bool $success,
        private ?string $answer,
        private ?string $error,
        private int $iterations,
        private array $toolCalls,
        private array $tokenUsage,
        private array $analysis,
        private ?TokenLedger $tokenLedger = null,
        private array $rawMessages = [],
        private array $createdFiles = [],
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

    /**
     * Get the raw agent messages from the execution (for Layer 3 conversation context).
     *
     * @return array<array<string, mixed>>
     */
    public function getRawMessages(): array
    {
        return $this->rawMessages;
    }

    /**
     * Get the list of files created during this run.
     *
     * @return array<string>
     */
    public function getCreatedFiles(): array
    {
        return $this->createdFiles;
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
            'created_files' => $this->createdFiles,
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
