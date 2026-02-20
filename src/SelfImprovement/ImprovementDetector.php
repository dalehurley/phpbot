<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\SelfImprovement;

use Dalehurley\Phpbot\BotResult;

/**
 * Passively detects capability gaps after tasks complete.
 *
 * Analyses the task input, analysis metadata, and BotResult to score
 * whether the task revealed something PHPBot could handle better with
 * a dedicated skill or tool. When the score exceeds the threshold a
 * suggestion is returned so the caller can surface it to the user.
 *
 * This class NEVER triggers the FeaturePipeline automatically — it only
 * produces a suggestion string. The user must confirm via /feature.
 */
class ImprovementDetector
{
    private const SCORE_THRESHOLD = 3;

    /**
     * Analyse a completed task and optionally return an improvement suggestion.
     *
     * Returns null when no suggestion is warranted.
     */
    public static function check(
        string $input,
        array $analysis,
        mixed $result,
        array $config = []
    ): ?string {
        $si = $config['self_improvement'] ?? [];

        if (empty($si['enabled'])) {
            return null;
        }

        if ($result === null || !($result instanceof BotResult) || !$result->isSuccess()) {
            return null;
        }

        $score = self::score($input, $analysis, $result);

        if ($score < self::SCORE_THRESHOLD) {
            return null;
        }

        $description = self::summarizeFeature($input, $analysis);

        return sprintf(
            "Tip: I noticed I could handle tasks like this more efficiently "
            . "with a dedicated capability. Type `/feature %s` to submit it "
            . "for community review.",
            $description
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function score(string $input, array $analysis, BotResult $result): int
    {
        $score = 0;

        $iterations = $result->getIterations();
        if ($iterations >= 10) {
            $score += 2;
        } elseif ($iterations >= 6) {
            $score += 1;
        }

        if (($analysis['complexity'] ?? '') === 'complex') {
            $score += 1;
        }

        if ((int) ($analysis['estimated_steps'] ?? 0) >= 5) {
            $score += 1;
        }

        if (empty($analysis['skill_matched'])) {
            $score += 1;
        }

        $repeatable = ['data_processing', 'api_integration', 'file_manipulation', 'automation'];
        if (in_array($analysis['task_type'] ?? '', $repeatable, true)) {
            $score += 1;
        }

        $toolCalls  = $result->getToolCalls();
        $errorCount = count(array_filter($toolCalls, fn($c) => !empty($c['is_error'])));
        if ($errorCount >= 2) {
            $score += 1;
        }

        return $score;
    }

    private static function summarizeFeature(string $input, array $analysis): string
    {
        $taskType = $analysis['task_type'] ?? '';
        $short    = self::truncate($input, 80);

        if ($taskType !== '') {
            return "{$taskType}: {$short}";
        }

        return $short;
    }

    private static function truncate(string $text, int $max): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;
        if (strlen($text) <= $max) {
            return $text;
        }
        return substr($text, 0, $max - 3) . '...';
    }
}
