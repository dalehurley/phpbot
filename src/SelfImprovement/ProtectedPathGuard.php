<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\SelfImprovement;

/**
 * Enforces the risk-tier system for self-modification.
 *
 * Tiers (ascending risk):
 *   skill   - new or modified files under skills/
 *   tool    - new files only under src/Tools/
 *   core    - existing src/ files that are not security-related
 *   blocked - files that must NEVER be auto-modified
 *
 * The guard is applied twice: once by FeaturePipeline before branching
 * (to reject clearly unsafe proposals early) and once during review by
 * the distributed reviewer network to verify the PR stays within bounds.
 */
class ProtectedPathGuard
{
    /**
     * Paths (or path prefixes) that are permanently blocked from auto-modification.
     * These contain credential handling, key management, or the self-improvement
     * system itself (to prevent circular modification).
     */
    private const BLOCKED_PATHS = [
        'src/Security/',
        'src/Storage/KeyStore.php',
        'src/Storage/CheckpointManager.php',
        'src/CredentialPatterns.php',
        'src/SelfImprovement/',
        'config/',
        'bin/',
        'composer.json',
        'composer.lock',
        '.env',
        '.github/workflows/',
    ];

    /**
     * Paths that require the 'core' tier (highest human-reviewable tier).
     * Modifications here require a larger quorum and a maintainer flag.
     */
    private const CORE_PATHS = [
        'src/Bot.php',
        'src/AgentFactory.php',
        'src/TaskAnalyzer.php',
        'src/Router/',
        'src/Agent/',
        'src/Daemon/',
        'src/Listener/',
        'src/Parallel/',
        'src/Scheduler/',
        'src/Prompt/',
        'src/Realtime/',
    ];

    /** Paths that are in the 'tool' tier — new file creation only. */
    private const TOOL_PATHS = [
        'src/Tools/',
    ];

    /** Paths that are in the 'skill' tier — always the safest. */
    private const SKILL_PATHS = [
        'skills/',
    ];

    /**
     * Determine the risk tier for a proposed change.
     *
     * @param  string[] $affectedPaths Relative file paths that will be created/modified.
     * @param  bool     $isNewFileOnly True when all affected paths are new files (no edits).
     * @return string   'skill' | 'tool' | 'core' | 'blocked'
     */
    public static function classify(array $affectedPaths, bool $isNewFileOnly = true): string
    {
        $highestTier = 'skill';

        foreach ($affectedPaths as $path) {
            $tier = self::tierForPath($path, $isNewFileOnly);
            if ($tier === 'blocked') {
                return 'blocked';
            }
            $highestTier = self::higherTier($highestTier, $tier);
        }

        return $highestTier;
    }

    /** Determine the risk tier for a single path. */
    public static function tierForPath(string $path, bool $isNewFile = true): string
    {
        $path = ltrim($path, '/');

        if (self::matchesAny($path, self::BLOCKED_PATHS)) {
            return 'blocked';
        }

        if (self::matchesAny($path, self::CORE_PATHS)) {
            return 'core';
        }

        if (self::matchesAny($path, self::TOOL_PATHS)) {
            // Modifying an existing tool file escalates to core-level scrutiny.
            return $isNewFile ? 'tool' : 'core';
        }

        if (self::matchesAny($path, self::SKILL_PATHS)) {
            return 'skill';
        }

        // Anything not explicitly categorised under src/ is treated as core.
        if (str_starts_with($path, 'src/')) {
            return 'core';
        }

        return 'skill';
    }

    /**
     * Check whether a proposed change exceeds the configured maximum tier.
     *
     * @param  string $proposedTier  The tier determined by classify().
     * @param  string $maxAllowed    The maximum tier from config (skill|tool|core).
     * @return bool   True when the change is within the allowed limit.
     */
    public static function isWithinLimit(string $proposedTier, string $maxAllowed): bool
    {
        if ($proposedTier === 'blocked') {
            return false;
        }

        $order    = ['skill' => 0, 'tool' => 1, 'core' => 2];
        $proposed = $order[$proposedTier] ?? 99;
        $limit    = $order[$maxAllowed]   ?? 0;

        return $proposed <= $limit;
    }

    /** Return a human-readable explanation of why a path is blocked or restricted. */
    public static function explain(string $path): string
    {
        $path = ltrim($path, '/');

        if (self::matchesAny($path, self::BLOCKED_PATHS)) {
            return "'{$path}' is permanently blocked from automated modification "
                 . "(security-critical or self-improvement infrastructure).";
        }

        if (self::matchesAny($path, self::CORE_PATHS)) {
            return "'{$path}' is a core orchestration file. Changes require a "
                 . "larger quorum (5 of 7) and explicit maintainer approval.";
        }

        if (self::matchesAny($path, self::TOOL_PATHS)) {
            return "'{$path}' is a tool file. New tools are allowed at the 'tool' tier; "
                 . "editing existing tools escalates to 'core'.";
        }

        return "'{$path}' is treated as a skill-tier change.";
    }

    /**
     * Return the quorum required for a given tier.
     *
     * @return array{max_reviewers: int, quorum: int, maintainer_flag: bool}
     */
    public static function quorumForTier(string $tier): array
    {
        return match ($tier) {
            'skill'  => ['max_reviewers' => 3, 'quorum' => 2, 'maintainer_flag' => false],
            'tool'   => ['max_reviewers' => 5, 'quorum' => 3, 'maintainer_flag' => false],
            'core'   => ['max_reviewers' => 7, 'quorum' => 5, 'maintainer_flag' => true],
            default  => ['max_reviewers' => 0, 'quorum' => 0, 'maintainer_flag' => true],
        };
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function matchesAny(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix) || $path === rtrim($prefix, '/')) {
                return true;
            }
        }
        return false;
    }

    private static function higherTier(string $a, string $b): string
    {
        $order = ['skill' => 0, 'tool' => 1, 'core' => 2, 'blocked' => 3];
        return ($order[$a] ?? 0) >= ($order[$b] ?? 0) ? $a : $b;
    }
}
