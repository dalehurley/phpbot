<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

/**
 * Detects when an agent is stuck in a stale loop (consecutive errors,
 * empty tool calls, or repeated identical calls) and throws a RuntimeException
 * to halt execution before burning through all iterations.
 */
class StaleLoopGuard
{
    private int $consecutiveErrors = 0;
    private int $consecutiveEmpty = 0;
    private array $recentSignatures = [];
    private int $totalCalls = 0;

    public function __construct(
        private int $maxConsecutiveErrors = 5,
        private int $maxConsecutiveEmpty = 3,
        private int $maxRepeatedIdentical = 4,
    ) {}

    /**
     * Record a tool call and check for stale-loop patterns.
     *
     * @throws \RuntimeException when a stale loop is detected
     */
    public function record(string $tool, array $input, bool $isError): void
    {
        $this->totalCalls++;

        $isEmpty = $this->isEmptyInput($tool, $input);

        // Track consecutive empty inputs
        if ($isEmpty) {
            $this->consecutiveEmpty++;
        } else {
            $this->consecutiveEmpty = 0;
        }

        // Track consecutive errors (errors OR empty count)
        if ($isError || $isEmpty) {
            $this->consecutiveErrors++;
        } else {
            $this->consecutiveErrors = 0;
        }

        // Track repeated identical calls
        $signature = $tool . ':' . md5(serialize($input));
        $this->recentSignatures[] = $signature;
        if (count($this->recentSignatures) > 20) {
            $this->recentSignatures = array_slice($this->recentSignatures, -20);
        }

        // --- Check thresholds ---

        if ($this->consecutiveEmpty >= $this->maxConsecutiveEmpty) {
            throw new \RuntimeException(
                "Stale loop detected: {$this->consecutiveEmpty} consecutive empty tool calls. " .
                "The agent is not producing valid commands. Halting to prevent wasted iterations."
            );
        }

        if ($this->consecutiveErrors >= $this->maxConsecutiveErrors) {
            throw new \RuntimeException(
                "Stale loop detected: {$this->consecutiveErrors} consecutive tool errors. " .
                "The agent appears stuck and unable to make progress. Halting execution."
            );
        }

        if ($this->hasRepeatedPattern()) {
            throw new \RuntimeException(
                "Stale loop detected: {$this->maxRepeatedIdentical} identical consecutive tool calls. " .
                "The agent is repeating the same action without progress. Halting execution."
            );
        }
    }

    public function getConsecutiveErrors(): int
    {
        return $this->consecutiveErrors;
    }

    public function getConsecutiveEmpty(): int
    {
        return $this->consecutiveEmpty;
    }

    public function getTotalCalls(): int
    {
        return $this->totalCalls;
    }

    private function isEmptyInput(string $tool, array $input): bool
    {
        if ($tool === 'bash') {
            $command = trim((string) ($input['command'] ?? ''));
            return $command === '';
        }

        if ($tool === 'write_file') {
            return empty($input['path'] ?? '') || empty($input['content'] ?? '');
        }

        return empty($input);
    }

    private function hasRepeatedPattern(): bool
    {
        $count = count($this->recentSignatures);
        if ($count < $this->maxRepeatedIdentical) {
            return false;
        }

        $recent = array_slice($this->recentSignatures, -$this->maxRepeatedIdentical);
        return count(array_unique($recent)) === 1;
    }
}
