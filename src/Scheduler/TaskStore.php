<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Scheduler;

/**
 * JSON-backed persistence for scheduled tasks.
 *
 * Stores tasks in a JSON file with atomic writes (temp + rename)
 * for crash safety.
 */
class TaskStore
{
    /** @var array<string, Task> Indexed by task ID */
    private array $tasks = [];

    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->load();
    }

    /**
     * Get all tasks that are due for execution.
     *
     * @return array<Task>
     */
    public function getDueTasks(\DateTimeImmutable $now): array
    {
        $due = [];

        foreach ($this->tasks as $task) {
            if ($task->status !== 'pending') {
                continue;
            }

            if ($task->nextRunAt <= $now) {
                $due[] = $task;
            }
        }

        return $due;
    }

    /**
     * Save or update a task.
     */
    public function save(Task $task): void
    {
        $this->tasks[$task->id] = $task;
        $this->persist();
    }

    /**
     * Remove a task by ID.
     */
    public function remove(string $id): bool
    {
        if (!isset($this->tasks[$id])) {
            return false;
        }

        unset($this->tasks[$id]);
        $this->persist();

        return true;
    }

    /**
     * Mark a task as executed and update its lastRunAt.
     */
    public function markExecuted(Task $task, \DateTimeImmutable $now): void
    {
        $task->status = $task->isRecurring() ? 'pending' : 'completed';
        $task->lastRunAt = $now;

        $this->tasks[$task->id] = $task;
        $this->persist();
    }

    /**
     * Reschedule a recurring task to its next run time.
     */
    public function scheduleNext(Task $task, \DateTimeImmutable $nextRunAt): void
    {
        $task->nextRunAt = $nextRunAt;
        $task->status = 'pending';

        $this->tasks[$task->id] = $task;
        $this->persist();
    }

    /**
     * Get all tasks.
     *
     * @return array<Task>
     */
    public function all(): array
    {
        return array_values($this->tasks);
    }

    /**
     * Find a task by ID.
     */
    public function findById(string $id): ?Task
    {
        return $this->tasks[$id] ?? null;
    }

    /**
     * Get count of tasks by status.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $counts = [];

        foreach ($this->tasks as $task) {
            $counts[$task->status] = ($counts[$task->status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Purge completed one-time tasks older than the given age.
     */
    public function purgeCompleted(\DateTimeImmutable $olderThan): int
    {
        $purged = 0;

        foreach ($this->tasks as $id => $task) {
            if ($task->status === 'completed'
                && $task->type === 'once'
                && $task->lastRunAt !== null
                && $task->lastRunAt < $olderThan) {
                unset($this->tasks[$id]);
                $purged++;
            }
        }

        if ($purged > 0) {
            $this->persist();
        }

        return $purged;
    }

    /**
     * Load tasks from disk.
     */
    private function load(): void
    {
        if (!is_file($this->path)) {
            $this->tasks = [];

            return;
        }

        $json = file_get_contents($this->path);
        if ($json === false) {
            $this->tasks = [];

            return;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['tasks'])) {
            $this->tasks = [];

            return;
        }

        $this->tasks = [];
        foreach ($decoded['tasks'] as $taskData) {
            if (!is_array($taskData) || !isset($taskData['id'])) {
                continue;
            }
            try {
                $task = Task::fromArray($taskData);
                $this->tasks[$task->id] = $task;
            } catch (\Throwable) {
                // Skip malformed task entries
            }
        }
    }

    /**
     * Persist tasks to disk (atomic write).
     */
    private function persist(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $data = [
            'tasks' => array_map(fn(Task $t) => $t->toArray(), array_values($this->tasks)),
            '_meta' => [
                'last_saved' => (new \DateTimeImmutable())->format('c'),
                'count' => count($this->tasks),
            ],
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Atomic write
        $tmpFile = $this->path . '.tmp.' . getmypid();
        if (file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            rename($tmpFile, $this->path);
        } else {
            @unlink($tmpFile);
        }
    }
}
