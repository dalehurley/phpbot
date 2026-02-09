<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Scheduler;

use Dalehurley\Phpbot\Bot;
use Dalehurley\Phpbot\Tools\AppleServicesTool;

/**
 * Scheduler tick loop.
 *
 * On each tick, loads due tasks from TaskStore, executes them via
 * Bot::run() or direct AppleServicesTool calls, marks them complete,
 * and reschedules recurring tasks.
 */
class Scheduler
{
    private TaskStore $taskStore;
    private CronMatcher $cronMatcher;
    private ?Bot $bot;
    private ?AppleServicesTool $appleTool;

    /** @var \Closure|null */
    private ?\Closure $logger;

    /** @var int Total tasks executed */
    private int $totalExecuted = 0;

    /** @var int Total tick cycles */
    private int $tickCount = 0;

    public function __construct(
        TaskStore $taskStore,
        ?Bot $bot = null,
        ?AppleServicesTool $appleTool = null,
    ) {
        $this->taskStore = $taskStore;
        $this->cronMatcher = new CronMatcher();
        $this->bot = $bot;
        $this->appleTool = $appleTool;
        $this->logger = null;
    }

    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    public function setBot(Bot $bot): void
    {
        $this->bot = $bot;
    }

    /**
     * Run a single tick: find and execute all due tasks.
     */
    public function tick(): void
    {
        $this->tickCount++;
        $now = new \DateTimeImmutable();

        $dueTasks = $this->taskStore->getDueTasks($now);

        if (empty($dueTasks)) {
            return;
        }

        $this->log("Tick #{$this->tickCount}: " . count($dueTasks) . " task(s) due");

        foreach ($dueTasks as $task) {
            $this->execute($task, $now);
        }

        // Periodically purge old completed tasks (every 100 ticks)
        if ($this->tickCount % 100 === 0) {
            $purgeOlderThan = $now->modify('-7 days');
            $purged = $this->taskStore->purgeCompleted($purgeOlderThan);
            if ($purged > 0) {
                $this->log("Purged {$purged} completed task(s) older than 7 days");
            }
        }
    }

    /**
     * Execute a single task.
     */
    private function execute(Task $task, \DateTimeImmutable $now): void
    {
        $this->log("Executing task '{$task->name}' (id: {$task->id}, type: {$task->type})");

        // Mark as running
        $task->status = 'running';
        $this->taskStore->save($task);

        try {
            $success = $this->runTask($task);

            // Mark executed
            $this->taskStore->markExecuted($task, $now);
            $this->totalExecuted++;

            // Reschedule if recurring
            if ($task->isRecurring()) {
                $nextRun = $task->computeNextRun($now, $this->cronMatcher);
                if ($nextRun !== null) {
                    $this->taskStore->scheduleNext($task, $nextRun);
                    $this->log("Rescheduled '{$task->name}' for {$nextRun->format('Y-m-d H:i')}");
                } else {
                    $this->log("Could not compute next run for '{$task->name}', marking completed");
                    $task->status = 'completed';
                    $this->taskStore->save($task);
                }
            }

            $status = $success ? 'success' : 'failed';
            $this->log("Task '{$task->name}' completed: {$status}");
        } catch (\Throwable $e) {
            $task->status = 'failed';
            $this->taskStore->save($task);
            $this->log("Task '{$task->name}' failed: {$e->getMessage()}");
        }
    }

    /**
     * Run a task's command via Bot::run() or a direct tool call.
     */
    private function runTask(Task $task): bool
    {
        // If the task has a simple Apple Services action in metadata, use the tool directly
        $appleAction = $task->metadata['apple_action'] ?? null;
        if ($appleAction !== null && $this->appleTool !== null) {
            $input = array_merge(['action' => $appleAction], $task->metadata['apple_params'] ?? []);
            $result = $this->appleTool->execute($input);

            return !str_contains($result->getContent(), '"success":false');
        }

        // Otherwise use Bot::run()
        if ($this->bot === null) {
            $this->log("No bot available to execute task '{$task->name}'");

            return false;
        }

        $result = $this->bot->run($task->command);

        return $result->isSuccess();
    }

    /**
     * Get the TaskStore for external access (CLI commands, etc.).
     */
    public function getTaskStore(): TaskStore
    {
        return $this->taskStore;
    }

    /**
     * Get the CronMatcher for external access.
     */
    public function getCronMatcher(): CronMatcher
    {
        return $this->cronMatcher;
    }

    /**
     * Get scheduler statistics.
     *
     * @return array{tick_count: int, total_executed: int, pending: int, tasks: array}
     */
    public function getStats(): array
    {
        $counts = $this->taskStore->countByStatus();

        return [
            'tick_count' => $this->tickCount,
            'total_executed' => $this->totalExecuted,
            'pending' => $counts['pending'] ?? 0,
            'running' => $counts['running'] ?? 0,
            'completed' => $counts['completed'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'total_tasks' => count($this->taskStore->all()),
        ];
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
