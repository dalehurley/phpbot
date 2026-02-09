<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Scheduler;

use Cron\CronExpression;

/**
 * Thin wrapper around dragonmantank/cron-expression for cron matching.
 */
class CronMatcher
{
    /**
     * Check if a cron expression is due at the given time.
     */
    public function isDue(string $expression, \DateTimeImmutable $now): bool
    {
        try {
            $cron = new CronExpression($expression);

            return $cron->isDue($now);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the next run date after the given time.
     */
    public function getNextRunDate(string $expression, \DateTimeImmutable $after): ?\DateTimeImmutable
    {
        try {
            $cron = new CronExpression($expression);
            $next = $cron->getNextRunDate($after);

            return \DateTimeImmutable::createFromMutable($next);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the previous run date before the given time.
     */
    public function getPreviousRunDate(string $expression, \DateTimeImmutable $before): ?\DateTimeImmutable
    {
        try {
            $cron = new CronExpression($expression);
            $prev = $cron->getPreviousRunDate($before);

            return \DateTimeImmutable::createFromMutable($prev);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Validate a cron expression.
     */
    public function isValid(string $expression): bool
    {
        return CronExpression::isValidExpression($expression);
    }

    /**
     * Get a human-readable description of a cron schedule.
     */
    public function describe(string $expression): string
    {
        $presets = [
            '* * * * *' => 'Every minute',
            '*/5 * * * *' => 'Every 5 minutes',
            '*/15 * * * *' => 'Every 15 minutes',
            '*/30 * * * *' => 'Every 30 minutes',
            '0 * * * *' => 'Every hour',
            '0 */2 * * *' => 'Every 2 hours',
            '0 */6 * * *' => 'Every 6 hours',
            '0 */12 * * *' => 'Every 12 hours',
            '0 0 * * *' => 'Daily at midnight',
            '0 8 * * *' => 'Daily at 8:00 AM',
            '0 9 * * *' => 'Daily at 9:00 AM',
            '0 9 * * 1-5' => 'Weekdays at 9:00 AM',
            '0 0 * * 1' => 'Weekly on Monday at midnight',
            '0 0 1 * *' => 'Monthly on the 1st at midnight',
        ];

        return $presets[$expression] ?? "Cron: {$expression}";
    }
}
