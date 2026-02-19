<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\DryRun;

/**
 * Singleton flag that signals all tools to simulate execution instead of
 * making real changes. Activate before running the agent in dry-run mode.
 */
class DryRunContext
{
    private static bool $active = false;

    /** @var array<array{tool: string, action: string, details: array<string,mixed>}> */
    private static array $log = [];

    public static function activate(): void
    {
        self::$active = true;
        self::$log = [];
    }

    public static function deactivate(): void
    {
        self::$active = false;
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /**
     * Record a simulated action for display in the dry-run summary.
     *
     * @param array<string,mixed> $details
     */
    public static function record(string $tool, string $action, array $details = []): void
    {
        self::$log[] = [
            'tool' => $tool,
            'action' => $action,
            'details' => $details,
        ];
    }

    /**
     * Return all recorded simulated actions.
     *
     * @return array<array{tool: string, action: string, details: array<string,mixed>}>
     */
    public static function getLog(): array
    {
        return self::$log;
    }

    /**
     * Format the dry-run log as a human-readable execution plan.
     */
    public static function formatPlan(): string
    {
        if (empty(self::$log)) {
            return "[DRY-RUN] No actions would be taken.\n";
        }

        $lines = ["[DRY-RUN] Execution plan (" . count(self::$log) . " actions):\n"];
        foreach (self::$log as $i => $entry) {
            $n = $i + 1;
            $lines[] = "  {$n}. [{$entry['tool']}] {$entry['action']}";
            foreach ($entry['details'] as $key => $value) {
                $display = is_string($value) ? (strlen($value) > 80 ? substr($value, 0, 80) . '…' : $value) : json_encode($value);
                $lines[] = "     {$key}: {$display}";
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
