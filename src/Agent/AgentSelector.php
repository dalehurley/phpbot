<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Agent;

class AgentSelector
{
    /**
     * Select the most appropriate agent type based on task analysis.
     */
    public function selectAgent(array $analysis): string
    {
        $complexity = $analysis['complexity'] ?? 'medium';
        $taskType = $analysis['task_type'] ?? 'general';
        $approach = $analysis['suggested_approach'] ?? 'direct';
        $steps = $analysis['estimated_steps'] ?? 1;
        $requiresPlanning = $analysis['requires_planning'] ?? false;
        $requiresReflection = $analysis['requires_reflection'] ?? false;

        // High complexity or multi-step tasks benefit from plan-execute
        if ($complexity === 'complex' || $steps > 5 || $requiresPlanning) {
            return 'plan_execute';
        }

        // Quality-critical tasks benefit from reflection
        if ($requiresReflection || $taskType === 'coding') {
            return 'reflection';
        }

        // Research or problem-solving benefits from chain of thought
        if ($taskType === 'research' || $taskType === 'problem_solving') {
            return 'chain_of_thought';
        }

        // Based on suggested approach
        return match ($approach) {
            'plan_execute' => 'plan_execute',
            'reflection' => 'reflection',
            'chain_of_thought' => 'chain_of_thought',
            default => 'react',
        };
    }

    /**
     * Get agent configuration based on type.
     */
    public function getAgentConfig(string $agentType): array
    {
        return match ($agentType) {
            'plan_execute' => [
                'allow_replan' => true,
                'max_retries' => 3,
            ],
            'reflection' => [
                'max_refinements' => 3,
                'quality_threshold' => 7,
            ],
            'chain_of_thought' => [
                'reasoning_steps' => true,
            ],
            'react' => [
                'observe_after_action' => true,
            ],
            default => [],
        };
    }

    /**
     * Determine if task should use streaming.
     */
    public function shouldStream(array $analysis): bool
    {
        $complexity = $analysis['complexity'] ?? 'medium';
        $steps = $analysis['estimated_steps'] ?? 1;

        // Stream for complex or multi-step tasks
        return $complexity === 'complex' || $steps > 3;
    }
}
