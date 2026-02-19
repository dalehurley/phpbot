<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Parallel;

/**
 * Executes independent callables in parallel using pcntl_fork().
 *
 * Falls back to sequential execution when pcntl is unavailable.
 * Results are collected via serialized temp files to avoid IPC complexity.
 */
class ParallelExecutor
{
    private int $maxConcurrent;

    public function __construct(int $maxConcurrent = 4)
    {
        $this->maxConcurrent = max(1, $maxConcurrent);
    }

    /**
     * Execute all callables, potentially in parallel.
     *
     * Each callable receives its integer index and must return a serializable value.
     * Returns results indexed by the same integer keys.
     *
     * @param array<int|string, callable> $tasks
     * @return array<int|string, mixed>
     */
    public function executeAll(array $tasks): array
    {
        if (!$this->isPcntlAvailable() || count($tasks) <= 1) {
            return $this->executeSequential($tasks);
        }

        return $this->executeParallel($tasks);
    }

    /**
     * Check if parallel execution is available on this system.
     */
    public function isPcntlAvailable(): bool
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_wait');
    }

    /**
     * Execute tasks sequentially (fallback).
     *
     * @param array<int|string, callable> $tasks
     * @return array<int|string, mixed>
     */
    private function executeSequential(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $key => $task) {
            try {
                $results[$key] = $task($key);
            } catch (\Throwable $e) {
                $results[$key] = ['error' => $e->getMessage()];
            }
        }
        return $results;
    }

    /**
     * Execute tasks in parallel using fork.
     *
     * @param array<int|string, callable> $tasks
     * @return array<int|string, mixed>
     */
    private function executeParallel(array $tasks): array
    {
        $keys = array_keys($tasks);
        $results = [];
        $tmpFiles = [];
        $pids = [];
        $activeSlots = 0;
        $taskQueue = $tasks;

        // Reset to allow iteration with index tracking
        reset($taskQueue);

        while (!empty($taskQueue) || $activeSlots > 0) {
            // Launch tasks up to the concurrency limit
            while ($activeSlots < $this->maxConcurrent && !empty($taskQueue)) {
                $key = key($taskQueue);
                $task = current($taskQueue);
                next($taskQueue);
                unset($taskQueue[$key]);

                $tmpFile = tempnam(sys_get_temp_dir(), 'phpbot_parallel_');
                $tmpFiles[$key] = $tmpFile;

                $pid = pcntl_fork();

                if ($pid === -1) {
                    // Fork failed — run sequentially for this task
                    try {
                        $results[$key] = $task($key);
                    } catch (\Throwable $e) {
                        $results[$key] = ['error' => $e->getMessage()];
                    }
                    @unlink($tmpFile);
                    continue;
                }

                if ($pid === 0) {
                    // Child process
                    try {
                        $result = $task($key);
                        file_put_contents($tmpFile, serialize($result));
                    } catch (\Throwable $e) {
                        file_put_contents($tmpFile, serialize(['error' => $e->getMessage()]));
                    }
                    exit(0);
                }

                // Parent
                $pids[$pid] = $key;
                $activeSlots++;
            }

            // Wait for at least one child to finish
            if ($activeSlots > 0) {
                $childPid = pcntl_wait($status);

                if ($childPid > 0 && isset($pids[$childPid])) {
                    $key = $pids[$childPid];
                    unset($pids[$childPid]);
                    $activeSlots--;

                    $tmpFile = $tmpFiles[$key] ?? null;
                    if ($tmpFile !== null && is_file($tmpFile)) {
                        $serialized = file_get_contents($tmpFile);
                        $results[$key] = $serialized !== false ? unserialize($serialized) : null;
                        @unlink($tmpFile);
                    }
                }
            }
        }

        return $results;
    }
}
