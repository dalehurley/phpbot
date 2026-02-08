<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Router;

/**
 * Value object representing a routing decision.
 *
 * Returned by CachedRouter::route() to indicate how a request should
 * be handled — from instant PHP answers (Tier 0) through to full
 * agent execution (Tier 2/3).
 */
class RouteResult
{
    public const TIER_INSTANT = 'instant';
    public const TIER_BASH = 'bash';
    public const TIER_CACHED = 'cached';
    public const TIER_CLASSIFIED = 'classified';

    /**
     * @param string        $tier          One of the TIER_* constants
     * @param string|null   $directAnswer  Pre-computed answer for Tier 0
     * @param string|null   $bashCommand   Single bash command for Tier 1
     * @param string[]      $tools         Tool names needed for Tier 2/3
     * @param string[]      $skills        Skill names matched for Tier 2/3
     * @param string        $agentType     Agent type for Tier 2/3 (react, plan_execute, etc.)
     * @param string        $promptTier    Prompt tier: minimal, standard, full
     * @param float         $confidence    Routing confidence (0.0-1.0)
     */
    public function __construct(
        public readonly string $tier,
        public readonly ?string $directAnswer = null,
        public readonly ?string $bashCommand = null,
        public readonly array $tools = [],
        public readonly array $skills = [],
        public readonly string $agentType = 'react',
        public readonly string $promptTier = 'minimal',
        public readonly float $confidence = 1.0,
    ) {}

    /**
     * Whether this route can be resolved without invoking the agent.
     */
    public function isEarlyExit(): bool
    {
        return $this->tier === self::TIER_INSTANT || $this->tier === self::TIER_BASH;
    }

    /**
     * Resolve the early-exit answer.
     *
     * For Tier 0: returns the directAnswer.
     * For Tier 1: executes the bash command and returns stdout.
     *
     * @return string The resolved answer
     * @throws \RuntimeException if not an early-exit route
     */
    public function resolve(): string
    {
        if ($this->tier === self::TIER_INSTANT) {
            return $this->directAnswer ?? '';
        }

        if ($this->tier === self::TIER_BASH) {
            return $this->executeBash();
        }

        throw new \RuntimeException('resolve() can only be called on early-exit routes (Tier 0/1)');
    }

    /**
     * Convert to the analysis array format expected by AgentSelector,
     * ToolRegistrar, and AgentFactory.
     *
     * Only meaningful for Tier 2/3 routes.
     *
     * @return array<string, mixed>
     */
    public function toAnalysis(): array
    {
        return [
            'task_type' => 'general',
            'complexity' => $this->promptTier === 'full' ? 'complex' : ($this->promptTier === 'standard' ? 'medium' : 'simple'),
            'requires_bash' => in_array('bash', $this->tools, true),
            'requires_file_ops' => !empty(array_intersect(['read_file', 'write_file', 'edit_file'], $this->tools)),
            'requires_tool_creation' => in_array('tool_builder', $this->tools, true),
            'requires_planning' => $this->agentType === 'plan_execute',
            'requires_reflection' => $this->agentType === 'reflection',
            'requires_real_world_effect' => false,
            'real_world_effect' => null,
            'creative_approaches' => [],
            'definition_of_done' => ['Task completed successfully'],
            'suggested_approach' => $this->agentType === 'react' ? 'direct' : $this->agentType,
            'estimated_steps' => $this->promptTier === 'full' ? 10 : ($this->promptTier === 'standard' ? 5 : 2),
            'potential_tools_needed' => $this->tools,
            'skill_matched' => !empty($this->skills),
        ];
    }

    /**
     * Create a Tier 0 (instant answer) result.
     */
    public static function instant(string $answer): self
    {
        return new self(
            tier: self::TIER_INSTANT,
            directAnswer: $answer,
            confidence: 1.0,
        );
    }

    /**
     * Create a Tier 1 (single bash command) result.
     */
    public static function bash(string $command): self
    {
        return new self(
            tier: self::TIER_BASH,
            bashCommand: $command,
            confidence: 1.0,
        );
    }

    /**
     * Create a Tier 2 (cached category match) result.
     *
     * @param string[] $tools
     * @param string[] $skills
     */
    public static function cached(
        array $tools,
        array $skills = [],
        string $agentType = 'react',
        string $promptTier = 'standard',
        float $confidence = 0.8,
    ): self {
        return new self(
            tier: self::TIER_CACHED,
            tools: $tools,
            skills: $skills,
            agentType: $agentType,
            promptTier: $promptTier,
            confidence: $confidence,
        );
    }

    /**
     * Create a Tier 3 (Haiku classified) result.
     *
     * @param string[] $tools
     * @param string[] $skills
     */
    public static function classified(
        array $tools,
        array $skills = [],
        string $agentType = 'react',
        string $promptTier = 'standard',
        float $confidence = 0.6,
    ): self {
        return new self(
            tier: self::TIER_CLASSIFIED,
            tools: $tools,
            skills: $skills,
            agentType: $agentType,
            promptTier: $promptTier,
            confidence: $confidence,
        );
    }

    /**
     * Execute a single bash command and return its stdout.
     */
    private function executeBash(): string
    {
        $command = $this->bashCommand ?? '';
        if ($command === '') {
            return '';
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, getcwd());
        if (!is_resource($process)) {
            return 'Error: Failed to execute command';
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 && $stderr !== '' && $stderr !== false) {
            return 'Error: ' . trim($stderr);
        }

        return trim($stdout !== false ? $stdout : '');
    }
}
