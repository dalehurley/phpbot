<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use ClaudePhp\ClaudePhp;
use ClaudeAgents\Skills\SkillManager;
use ClaudeAgents\Skills\SkillPromptComposer;
use Dalehurley\Phpbot\Registry\PersistentToolRegistry;
use Dalehurley\Phpbot\Agent\AgentSelector;
use Dalehurley\Phpbot\Storage\KeyStore;

class Bot
{
    private ?ClaudePhp $client = null;
    private PersistentToolRegistry $toolRegistry;
    private ToolRegistrar $toolRegistrar;
    private AgentSelector $agentSelector;
    private array $config;
    private bool $verbose;
    private ?SkillManager $skillManager = null;
    private ?KeyStore $keyStore = null;

    public function __construct(array $config = [], bool $verbose = false)
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->verbose = $verbose;

        $this->toolRegistry = new PersistentToolRegistry($this->config['tools_storage_path']);
        $this->toolRegistrar = new ToolRegistrar($this->toolRegistry, $this->config);
        $this->agentSelector = new AgentSelector();

        $this->toolRegistrar->registerCoreTools();
        $this->initSkills();
        $this->toolRegistrar->registerSkillScriptTools($this->config['skills_path'] ?? '');
        $this->initKeyStore();
    }

    /**
     * @param callable|null $onProgress Callback for progress updates: fn(string $stage, string $message)
     */
    public function run(string $input, ?callable $onProgress = null): BotResult
    {
        $progress = $onProgress ?? fn($stage, $msg) => null;
        $clientFactory = fn() => $this->getClient();

        $summarizer = new ProgressSummarizer($clientFactory, $this->config['model'], $this->getFastModel());
        $skillAutoCreator = new SkillAutoCreator($clientFactory, $this->config, $this->skillManager);

        $this->log("📝 Received input: {$input}");
        $progress('start', 'Received input');

        // 1. Resolve skills FIRST (cheap keyword matching, no LLM call)
        $resolvedSkills = $this->resolveSkills($input);
        if (!empty($resolvedSkills)) {
            $progress('skills', 'Skills: ' . implode(', ', array_map(fn($s) => $s->getName(), $resolvedSkills)));
        }

        // 2. Determine if we can use the skill fast-path
        $skillFastPath = $this->shouldUseSkillFastPath($resolvedSkills, $input);

        // 3. Analyze the task (or use fast-path to skip the LLM analysis call)
        $progress('analyzing', 'Analyzing task requirements...');
        if ($skillFastPath) {
            $analysis = $this->buildSkillFastPathAnalysis($resolvedSkills[0]);
            $this->log("🎯 Task analysis complete (skill fast-path)");
        } else {
            $taskAnalyzer = new TaskAnalyzer($clientFactory, $this->config['model']);
            $analysis = $taskAnalyzer->analyze($input);
            $this->log("🎯 Task analysis complete");
        }
        $progress('analyzed', 'Task analysis complete');

        // 4. Build dynamic config based on analysis & skills
        $dynamicConfig = $this->buildDynamicConfig($analysis, $resolvedSkills);
        $effectiveConfig = array_merge($this->config, $dynamicConfig, [
            'iteration_summarizer' => $summarizer,
        ]);
        $agentFactory = new AgentFactory($clientFactory, $effectiveConfig, $this->verbose);

        // 5. Summarize plan (skip on fast-path to save time/cost)
        if (!$skillFastPath) {
            $beforeSummary = $summarizer->summarizeBefore($input, $analysis);
            if ($beforeSummary !== '') {
                $progress('summary_before', "Summary: {$beforeSummary}");
            }
        }

        // 6. Select agent and tools
        $agentType = $this->agentSelector->selectAgent($analysis);
        $tools = $this->toolRegistrar->selectTools($analysis);
        $systemPrompt = $this->composeSystemPrompt($analysis, $resolvedSkills, $agentFactory);

        $this->log("🤖 Selected agent: {$agentType}");
        $this->log("🔧 Selected tools: " . implode(', ', array_map(fn($t) => $t->getName(), $tools)));
        $progress('selected', "Selected {$agentType} agent with " . count($tools) . " tools");

        // 7. Create and run the agent
        $agent = $agentFactory->create($agentType, $tools, $systemPrompt, $analysis, $progress);
        $enhancedPrompt = $agentFactory->buildEnhancedPrompt($input, $analysis, $resolvedSkills);

        $progress('executing', 'Executing task...');
        $result = $agent->run($enhancedPrompt);
        $progress('complete', 'Task execution complete');

        // 8. Summarize after execution
        $afterSummary = $summarizer->summarizeAfter($input, $analysis, $result);
        if ($afterSummary !== '') {
            $progress('summary_after', "Summary: {$afterSummary}");
        }

        // 9. Auto-create skill if appropriate
        $skillAutoCreator->autoCreate($input, $analysis, $result, $resolvedSkills, $progress);
        if ($this->skillManager !== null) {
            $this->initSkills(); // Re-discover skills after potential creation
        }

        return new BotResult(
            success: $result->isSuccess(),
            answer: $result->getAnswer(),
            error: $result->getError(),
            iterations: $result->getIterations(),
            toolCalls: $result->getToolCalls(),
            tokenUsage: $result->getTokenUsage(),
            analysis: $analysis
        );
    }

    public function getToolRegistry(): PersistentToolRegistry
    {
        return $this->toolRegistry;
    }

    public function listTools(): array
    {
        return $this->toolRegistry->names();
    }

    public function listSkills(): array
    {
        if ($this->skillManager === null) {
            return [];
        }

        try {
            return array_values($this->skillManager->summaries());
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function listSkillScripts(): array
    {
        return $this->toolRegistrar->listSkillScripts($this->config['skills_path'] ?? '');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function getClient(): ClaudePhp
    {
        if ($this->client === null) {
            $apiKey = $this->config['api_key'] ?? '';
            if ($apiKey === '' && $this->keyStore !== null) {
                $apiKey = $this->keyStore->get('anthropic_api_key') ?? '';
            }

            if ($apiKey === '') {
                throw new \RuntimeException('ANTHROPIC_API_KEY is required. Set it via environment variable or config.');
            }
            $timeout = (float) ($this->config['timeout'] ?? 30.0);
            $this->client = new ClaudePhp(apiKey: $apiKey, timeout: $timeout);
        }
        return $this->client;
    }

    private function getDefaultConfig(): array
    {
        return [
            'api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
            'fast_model' => 'claude-haiku-4-5',
            'model' => 'claude-sonnet-4-5',
            'super_model' => 'claude-opus-4-5',
            'max_iterations' => 20,
            'max_tokens' => 4096,
            'temperature' => 0.7,
            'timeout' => 300.0,
            'tools_storage_path' => dirname(__DIR__) . '/storage/tools',
            'skills_path' => dirname(__DIR__) . '/skills',
            'keys_storage_path' => dirname(__DIR__) . '/storage/keys.json',
        ];
    }

    private function getFastModel(): string
    {
        $fast = $this->config['fast_model'] ?? '';
        if (is_string($fast) && $fast !== '') {
            return $fast;
        }

        return $this->config['model'];
    }

    private function initSkills(): void
    {
        $skillsPath = $this->config['skills_path'] ?? dirname(__DIR__) . '/skills';
        if (!is_string($skillsPath) || $skillsPath === '' || !is_dir($skillsPath)) {
            return;
        }

        $this->skillManager = new SkillManager($skillsPath);
        $this->skillManager->discover();
    }

    private function initKeyStore(): void
    {
        $path = $this->config['keys_storage_path'] ?? '';
        if (!is_string($path) || $path === '') {
            return;
        }

        $this->keyStore = new KeyStore($path);
    }

    /**
     * Determine if a high-confidence skill match allows us to skip
     * the full LLM-based task analysis and use a fast-path instead.
     */
    private function shouldUseSkillFastPath(array $resolvedSkills, string $input): bool
    {
        if (empty($resolvedSkills)) {
            return false;
        }

        $topSkill = $resolvedSkills[0];

        // relevanceScore() is on the concrete Skill class
        if (!method_exists($topSkill, 'relevanceScore')) {
            return false;
        }

        return $topSkill->relevanceScore($input) >= 0.5;
    }

    /**
     * Build a lightweight analysis array from the matched skill,
     * skipping the LLM TaskAnalyzer call entirely.
     */
    private function buildSkillFastPathAnalysis($skill): array
    {
        $name = method_exists($skill, 'getName') ? $skill->getName() : 'unknown';

        return [
            'task_type' => 'automation',
            'complexity' => 'medium',
            'requires_bash' => true,
            'requires_file_ops' => true,
            'requires_tool_creation' => false,
            'requires_planning' => false,
            'requires_reflection' => false,
            'definition_of_done' => [
                'Task completed following the established skill procedure',
                'All output files created and verified',
                'Final summary provided with deliverables list',
            ],
            'suggested_approach' => 'direct',
            'estimated_steps' => 10,
            'potential_tools_needed' => ['bash', 'write_file', 'read_file'],
            'skill_matched' => true,
            'skill_name' => $name,
        ];
    }

    /**
     * Build dynamic config overrides based on task analysis and skill context.
     * Adjusts max_iterations and max_tokens for optimal performance.
     */
    private function buildDynamicConfig(array $analysis, array $resolvedSkills): array
    {
        $overrides = [];
        $complexity = $analysis['complexity'] ?? 'medium';
        $hasSkill = !empty($resolvedSkills);
        $baseMaxIter = (int) ($this->config['max_iterations'] ?? 25);
        $baseMaxTokens = (int) ($this->config['max_tokens'] ?? 4096);

        // Dynamic max_iterations: lower when we have a skill guide
        if ($hasSkill) {
            $overrides['max_iterations'] = min(15, $baseMaxIter);
        } elseif ($complexity === 'simple') {
            $overrides['max_iterations'] = min(10, $baseMaxIter);
        }
        // complex without skill: use default (25)

        // Dynamic max_tokens: increase for tasks that generate documents
        // to prevent mid-tool-call truncation (the primary cause of empty commands)
        $needsLargeOutput = ($analysis['requires_file_ops'] ?? false)
            || $complexity === 'complex'
            || str_contains(strtolower($analysis['task_type'] ?? ''), 'data_processing');

        if ($needsLargeOutput) {
            $overrides['max_tokens'] = max(8192, $baseMaxTokens);
        }

        return $overrides;
    }

    private function resolveSkills(string $input): array
    {
        if ($this->skillManager === null) {
            return [];
        }

        try {
            return $this->skillManager->resolve($input);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function composeSystemPrompt(array $analysis, array $resolvedSkills, AgentFactory $agentFactory): string
    {
        $basePrompt = $agentFactory->getSystemPrompt($analysis);
        if ($this->skillManager === null) {
            return $basePrompt;
        }

        try {
            $composer = new SkillPromptComposer();
            $summaries = $this->skillManager->summaries();
            return $composer->composeWithDiscovery($basePrompt, $resolvedSkills, $summaries);
        } catch (\Throwable $e) {
            return $basePrompt;
        }
    }

    private function log(string $message): void
    {
        if ($this->verbose) {
            echo $message . PHP_EOL;
        }
    }
}
