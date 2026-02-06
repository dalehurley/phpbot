<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Skill;

/**
 * Decides whether a completed task warrants creating a reusable skill.
 *
 * Extracted from SkillAutoCreator so the assessment logic can be tested
 * and tuned independently.
 */
class SkillAssessor
{
    /**
     * Determine if a successfully-completed task warrants creating a reusable skill.
     */
    public static function shouldCreate(array $analysis): bool
    {
        $complexity = $analysis['complexity'] ?? 'medium';
        $steps = (int) ($analysis['estimated_steps'] ?? 1);

        if ($complexity !== 'simple') {
            return true;
        }

        if ($steps >= 2) {
            return true;
        }

        return false;
    }
}
