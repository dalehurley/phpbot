<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Scheduler;

/**
 * Value object representing a scheduled task.
 *
 * Tasks can be:
 *  - 'once': Single execution at a future date
 *  - 'recurring': Repeats on a cron schedule
 *  - 'interval': Repeats every N minutes
 */
class Task
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** The prompt/instruction to pass to Bot::run() */
        public readonly string $command,
        /** Task type: once, recurring, interval */
        public readonly string $type,
        /** When this task should next execute */
        public \DateTimeImmutable $nextRunAt,
        /** Current status: pending, running, completed, failed, paused */
        public string $status = 'pending',
        /** Cron expression for recurring tasks (e.g. "0 9 * * *") */
        public readonly ?string $cronExpression = null,
        /** Interval in minutes for interval tasks */
        public readonly ?int $intervalMinutes = null,
        /** Last time this task was executed */
        public ?\DateTimeImmutable $lastRunAt = null,
        /** Optional: run a specific skill instead of Bot::run() */
        public readonly ?string $skillName = null,
        /** Additional metadata */
        public readonly array $metadata = [],
        /** When the task was created */
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {}

    /**
     * Whether this task recurs (recurring or interval type).
     */
    public function isRecurring(): bool
    {
        return $this->type === 'recurring' || $this->type === 'interval';
    }

    /**
     * Compute the next run time after execution.
     *
     * For 'recurring' tasks: uses the cron expression.
     * For 'interval' tasks: adds intervalMinutes to now.
     * For 'once' tasks: returns null (no next run).
     */
    public function computeNextRun(\DateTimeImmutable $now, ?CronMatcher $cronMatcher = null): ?\DateTimeImmutable
    {
        return match ($this->type) {
            'recurring' => $this->cronExpression !== null && $cronMatcher !== null
                ? $cronMatcher->getNextRunDate($this->cronExpression, $now)
                : null,
            'interval' => $this->intervalMinutes !== null
                ? $now->modify("+{$this->intervalMinutes} minutes")
                : null,
            default => null,
        };
    }

    /**
     * Serialize to array for JSON storage.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'command' => $this->command,
            'type' => $this->type,
            'next_run_at' => $this->nextRunAt->format('c'),
            'status' => $this->status,
            'cron_expression' => $this->cronExpression,
            'interval_minutes' => $this->intervalMinutes,
            'last_run_at' => $this->lastRunAt?->format('c'),
            'skill_name' => $this->skillName,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt->format('c'),
        ];
    }

    /**
     * Create a Task from a stored array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            name: $data['name'],
            command: $data['command'],
            type: $data['type'],
            nextRunAt: new \DateTimeImmutable($data['next_run_at']),
            status: $data['status'] ?? 'pending',
            cronExpression: $data['cron_expression'] ?? null,
            intervalMinutes: isset($data['interval_minutes']) ? (int) $data['interval_minutes'] : null,
            lastRunAt: isset($data['last_run_at']) ? new \DateTimeImmutable($data['last_run_at']) : null,
            skillName: $data['skill_name'] ?? null,
            metadata: $data['metadata'] ?? [],
            createdAt: isset($data['created_at']) ? new \DateTimeImmutable($data['created_at']) : new \DateTimeImmutable(),
        );
    }
}
