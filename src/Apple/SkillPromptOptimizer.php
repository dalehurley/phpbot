<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Apple;

use ClaudeAgents\Contracts\SkillInterface;
use Dalehurley\Phpbot\Stats\TokenLedger;

/**
 * Condenses skill instructions using the small model (Apple FM or Haiku).
 *
 * When a skill is matched for a simple task, the full SKILL.md instructions
 * may be unnecessarily verbose. This optimizer asks the small model to
 * produce a concise, task-specific instruction that replaces the full
 * instructions in the system prompt.
 *
 * Example:
 *   Input: "what is the weather in Perth?"
 *   Skill: get-weather-forecast (full SKILL.md ~2K chars)
 *   Optimized: "Run `curl -s wttr.in/Perth` and summarize the weather data
 *              including current conditions and 3-day forecast." (~100 chars)
 *
 * All calls use the on-device Apple FM (free) when available, falling back
 * to Claude Haiku (cheap). Optimization is skipped for complex tasks or when
 * the skill instructions are already compact.
 */
class SkillPromptOptimizer
{
    /** Don't bother optimizing skills shorter than this (in chars). */
    private const MIN_INSTRUCTION_LENGTH = 1500;

    /** Maximum tokens for the optimized instruction. */
    private const MAX_OPTIMIZED_TOKENS = 4000;

    /** Optional logging callback. */
    private ?\Closure $logger = null;

    public function __construct(
        private SmallModelClient $appleFM,
        private ?TokenLedger $ledger = null,
    ) {}

    /**
     * Set an optional logger.
     */
    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Optimize skill instructions for a specific task.
     *
     * Returns the original instructions if:
     * - The task is complex (not simple/medium)
     * - The instructions are already compact
     * - The small model fails or returns a longer result
     *
     * @param string $input The user's request
     * @param SkillInterface $skill The matched skill
     * @param string $complexity Task complexity from analysis (simple, medium, complex)
     * @return string Optimized or original instructions
     */
    public function optimize(string $input, SkillInterface $skill, string $complexity = 'simple'): string
    {
        $instructions = $skill->getInstructions();
        $originalLength = strlen($instructions);

        // Skip optimization for complex tasks — they need full instructions
        if ($complexity === 'complex') {
            $this->log("Skipping optimization for complex task (skill: {$skill->getName()})");

            return $instructions;
        }

        // Skip if instructions are already compact
        if ($originalLength < self::MIN_INSTRUCTION_LENGTH) {
            $this->log("Skill '{$skill->getName()}' instructions already compact ({$originalLength} chars)");

            return $instructions;
        }

        try {
            $prompt = $this->buildOptimizationPrompt($input, $skill, $instructions);
            $optimized = $this->appleFM->call(
                $prompt,
                self::MAX_OPTIMIZED_TOKENS,
                'skill_optimization',
                'You condense skill instructions into concise, task-specific steps. Output only the essential steps needed for the specific task. Be direct and actionable.',
            );

            $optimized = trim($optimized);
            $optimizedLength = strlen($optimized);

            // Only use the optimized version if it's actually shorter
            if ($optimizedLength >= $originalLength || $optimizedLength === 0) {
                $this->log("Optimization did not reduce size for '{$skill->getName()}' ({$optimizedLength} >= {$originalLength})");

                return $instructions;
            }

            $saved = $originalLength - $optimizedLength;

            // Record savings in the token ledger
            $this->ledger?->record(
                'apple_fm',
                'skill_optimization',
                (int) ceil($originalLength / 4),
                (int) ceil($optimizedLength / 4),
                0.0,
                $saved,
            );

            $this->log(sprintf(
                'Optimized skill "%s" instructions: %s chars -> %s chars (saved %s)',
                $skill->getName(),
                number_format($originalLength),
                number_format($optimizedLength),
                number_format($saved),
            ));

            return $optimized;
        } catch (\Throwable $e) {
            $this->log("Skill optimization failed for '{$skill->getName()}': {$e->getMessage()}");

            return $instructions;
        }
    }

    /**
     * Build the prompt that asks the small model to condense instructions.
     */
    private function buildOptimizationPrompt(string $input, SkillInterface $skill, string $instructions): string
    {
        return <<<PROMPT
User request: "{$input}"
Matched skill: {$skill->getName()}

Full skill instructions:
{$instructions}

Condense the above instructions into the minimum steps needed to complete this specific request. Include only the relevant procedure steps and any exact commands to run (adapted to the user's input). Omit "When to Use", "Input Parameters" tables, examples, and any steps not needed for this request. Output just the condensed steps.
PROMPT;
    }

    /**
     * Log a message via the optional logger.
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
