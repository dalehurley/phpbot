<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use ClaudePhp\ClaudePhp;
use ClaudeAgents\Skills\SkillManager;
use ClaudeAgents\Skills\SkillPromptComposer;
use Dalehurley\Phpbot\Registry\PersistentToolRegistry;
use Dalehurley\Phpbot\Agent\AgentSelector;
use Dalehurley\Phpbot\Apple\AppleFMClient;
use Dalehurley\Phpbot\Apple\AppleFMContextCompactor;
use Dalehurley\Phpbot\Apple\AppleFMSimpleAgent;
use Dalehurley\Phpbot\Apple\AppleFMSkillFilter;
use Dalehurley\Phpbot\Apple\HaikuModelClient;
use Dalehurley\Phpbot\Apple\SkillPromptOptimizer;
use Dalehurley\Phpbot\Apple\SmallModelClient;
use Dalehurley\Phpbot\Apple\ToolResultSummarizer;
use Dalehurley\Phpbot\Prompt\TieredPromptBuilder;
use Dalehurley\Phpbot\Router\CachedRouter;
use Dalehurley\Phpbot\Router\ClassifierClient;
use Dalehurley\Phpbot\Router\RouteResult;
use Dalehurley\Phpbot\Router\RouterCache;
use Dalehurley\Phpbot\Stats\TokenLedger;
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
    private ?RouterCache $routerCache = null;
    private ?CachedRouter $router = null;
    private TokenLedger $tokenLedger;
    private ?SmallModelClient $appleFM = null;
    private ?ToolResultSummarizer $toolSummarizer = null;
    private ?AppleFMContextCompactor $contextCompactor = null;
    private ?AppleFMSimpleAgent $simpleAgent = null;
    private ?AppleFMSkillFilter $skillFilter = null;
    private ?SkillPromptOptimizer $skillOptimizer = null;

    /** @var callable|null External logger: fn(string $message) => void */
    private $fileLogger = null;

    public function __construct(array $config = [], bool $verbose = false)
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->verbose = $verbose;

        // Initialize token ledger and Apple FM early so all components can use them
        $this->tokenLedger = new TokenLedger(
            $this->config['model'] ?? 'claude-sonnet-4-5',
            $this->config['pricing'] ?? [],
        );
        $this->initAppleFM();

        $this->toolRegistry = new PersistentToolRegistry($this->config['tools_storage_path']);
        $this->toolRegistrar = new ToolRegistrar($this->toolRegistry, $this->config);
        $this->agentSelector = new AgentSelector();

        $this->toolRegistrar->registerCoreTools();
        $this->initSkills();
        $this->toolRegistrar->registerSearchCapabilitiesTool($this->skillManager);
        $this->toolRegistrar->registerSkillScriptTools($this->config['skills_path'] ?? '');
        $this->initKeyStore();
        $this->initRouter();
    }

    /**
     * @param callable|null $onProgress Callback for progress updates: fn(string $stage, string $message)
     */
    public function run(string $input, ?callable $onProgress = null): BotResult
    {
        $progress = $onProgress ?? fn($stage, $msg) => null;
        $clientFactory = fn() => $this->getClient();

        $this->log("📝 Received input: {$input}");
        $progress('start', 'Received input');

        // =====================================================================
        // Phase 1: Router — try to resolve without the agent
        // =====================================================================

        $routeResult = $this->router !== null
            ? $this->router->route($input)
            : null;

        // Tier 0/1: Early exit — answer directly without any LLM call
        if ($routeResult !== null && $routeResult->isEarlyExit()) {
            $this->log("⚡ Router early-exit (tier: {$routeResult->tier})");
            $progress('routed', "Resolved via router ({$routeResult->tier}) — 0 tokens");

            $answer = $routeResult->resolve();

            return new BotResult(
                success: true,
                answer: $answer,
                error: null,
                iterations: 0,
                toolCalls: [],
                tokenUsage: ['input' => 0, 'output' => 0, 'total' => 0],
                analysis: ['tier' => $routeResult->tier, 'routed' => true],
                tokenLedger: $this->tokenLedger,
            );
        }

        // =====================================================================
        // Phase 2: Agent execution — Tier 2/3 or legacy path
        // =====================================================================

        $summarizer = new ProgressSummarizer(
            $clientFactory,
            $this->config['model'],
            $this->getFastModel(),
            $this->appleFM,
            $this->tokenLedger,
        );
        $skillAutoCreator = new SkillAutoCreator($clientFactory, $this->config, $this->skillManager);

        // Resolve skills (cheap keyword matching, no LLM call)
        $resolvedSkills = $this->resolveSkills($input);
        if (!empty($resolvedSkills)) {
            $progress('skills', 'Skills: ' . implode(', ', array_map(fn($s) => $s->getName(), $resolvedSkills)));
        }

        // Build analysis — from route result or skill fast-path or TaskAnalyzer
        $progress('analyzing', 'Analyzing task requirements...');
        $analysis = $this->buildAnalysis($input, $routeResult, $resolvedSkills, $clientFactory);
        $progress('analyzed', 'Task analysis complete');

        // =====================================================================
        // Phase 2a: Apple FM Simple Agent — try on-device for simple tasks
        // =====================================================================

        $complexity = $analysis['complexity'] ?? 'medium';
        $requiredTools = $analysis['potential_tools_needed'] ?? [];

        if (
            $this->simpleAgent !== null
            && $this->simpleAgent->canHandle($requiredTools, $complexity)
        ) {
            // Try skill-aware on-device execution first (when a skill is matched).
            // The skill instructions tell Apple FM exactly what command to run,
            // making it much more accurate. Large output (e.g. 14K weather data)
            // is summarized before formatting to fit Apple FM's context window.
            if (!empty($resolvedSkills) && $this->skillOptimizer !== null) {
                $topSkill = $resolvedSkills[0];
                $optimizedInstructions = $this->skillOptimizer->optimize($input, $topSkill, $complexity);

                $this->log("🍎 Attempting on-device skill execution: {$topSkill->getName()}");
                $progress('apple_fm', "Trying on-device agent with skill: {$topSkill->getName()}...");

                $onDeviceAnswer = $this->simpleAgent->executeWithSkill(
                    $input,
                    $optimizedInstructions,
                    $topSkill->getName(),
                );

                if ($onDeviceAnswer !== null) {
                    $this->log('🍎 Skill task completed on-device (zero Claude tokens)');
                    $progress('routed', 'Resolved via Apple FM — 0 Claude tokens');

                    return new BotResult(
                        success: true,
                        answer: $onDeviceAnswer,
                        error: null,
                        iterations: 0,
                        toolCalls: [],
                        tokenUsage: ['input' => 0, 'output' => 0, 'total' => 0],
                        analysis: array_merge($analysis, [
                            'tier' => 'apple_fm_skill_agent',
                            'on_device' => true,
                            'skill_name' => $topSkill->getName(),
                        ]),
                        tokenLedger: $this->tokenLedger,
                    );
                }

                $this->log('🍎 On-device skill execution fell back to Claude');
            } else {
                // No skill matched — try generic on-device execution
                $this->log('🍎 Attempting on-device execution (simple bash task)');
                $progress('apple_fm', 'Trying on-device agent...');

                $onDeviceAnswer = $this->simpleAgent->execute($input);

                if ($onDeviceAnswer !== null) {
                    $this->log('🍎 Task completed on-device (zero Claude tokens)');
                    $progress('routed', 'Resolved via Apple FM — 0 Claude tokens');

                    return new BotResult(
                        success: true,
                        answer: $onDeviceAnswer,
                        error: null,
                        iterations: 0,
                        toolCalls: [],
                        tokenUsage: ['input' => 0, 'output' => 0, 'total' => 0],
                        analysis: array_merge($analysis, ['tier' => 'apple_fm_agent', 'on_device' => true]),
                        tokenLedger: $this->tokenLedger,
                    );
                }

                $this->log('🍎 On-device execution returned to Claude');
            }
        }

        // =====================================================================
        // Phase 2b: Full Claude agent execution
        // =====================================================================

        // Merge any skill names from the route result into resolved skills.
        // IMPORTANT: Filter router candidates through Apple FM to prevent
        // unfiltered skills from bypassing the semantic validation done in
        // resolveSkills(). Without this, the router's keyword-matched skills
        // would inflate the system prompt with irrelevant skill instructions.
        if ($routeResult !== null && !empty($routeResult->skills) && $this->skillManager !== null) {
            $routerCandidates = [];
            foreach ($routeResult->skills as $skillName) {
                try {
                    $skill = $this->skillManager->get($skillName);
                    $alreadyResolved = false;
                    foreach ($resolvedSkills as $rs) {
                        if ($rs->getName() === $skillName) {
                            $alreadyResolved = true;

                            break;
                        }
                    }
                    if (!$alreadyResolved) {
                        $routerCandidates[] = $skill;
                    }
                } catch (\Throwable) {
                    // Skill not found, skip
                }
            }

            // Apply Apple FM semantic filter (same as resolveSkills) to prevent
            // false positives from keyword matching in the router cache
            if (!empty($routerCandidates) && $this->skillFilter !== null) {
                $routerCandidates = $this->skillFilter->filter($input, $routerCandidates);
            }

            foreach ($routerCandidates as $skill) {
                $resolvedSkills[] = $skill;
            }
        }

        // Build dynamic config
        $dynamicConfig = $this->buildDynamicConfig($analysis, $resolvedSkills);
        $effectiveConfig = array_merge($this->config, $dynamicConfig, [
            'iteration_summarizer' => $summarizer,
            'tool_result_summarizer' => $this->toolSummarizer,
            'context_compactor' => $this->contextCompactor,
        ]);
        $agentFactory = new AgentFactory($clientFactory, $effectiveConfig, $this->verbose);

        // Share the file logger with AgentFactory for detailed tool/iteration logging
        if ($this->fileLogger !== null) {
            $agentFactory->setLogger($this->fileLogger);
        }

        // Log detailed analysis to file
        $this->logJson('Task analysis', $analysis);

        // Summarize plan (skip for routed or skill fast-path)
        $isRoutedOrFastPath = $routeResult !== null || $this->shouldUseSkillFastPath($resolvedSkills, $input);
        if (!$isRoutedOrFastPath) {
            $beforeSummary = $summarizer->summarizeBefore($input, $analysis);
            if ($beforeSummary !== '') {
                $progress('summary_before', "Summary: {$beforeSummary}");
            }
        }

        // Select agent type and tools — using route result for selective loading
        $agentType = $routeResult !== null
            ? ($routeResult->agentType ?: $this->agentSelector->selectAgent($analysis))
            : $this->agentSelector->selectAgent($analysis);

        $tools = $this->toolRegistrar->selectTools($analysis, $routeResult);

        // Build system prompt — tiered when routed, full otherwise
        $systemPrompt = $this->buildSystemPrompt($input, $routeResult, $analysis, $resolvedSkills, $agentFactory);

        $this->log("🤖 Selected agent: {$agentType}");
        $this->log("🔧 Selected tools: " . implode(', ', array_map(fn($t) => $t->getName(), $tools)));
        $this->logJson('System prompt', ['length' => strlen($systemPrompt), 'prompt' => $systemPrompt]);
        $progress('selected', "Selected {$agentType} agent with " . count($tools) . " tools");

        // Create and run the agent
        $agent = $agentFactory->create($agentType, $tools, $systemPrompt, $analysis, $progress);
        $enhancedPrompt = $agentFactory->buildEnhancedPrompt($input, $analysis, $resolvedSkills);

        $this->logJson('Enhanced prompt (user message)', ['prompt' => $enhancedPrompt]);

        $progress('executing', 'Executing task...');
        $result = $agent->run($enhancedPrompt);
        $progress('complete', 'Task execution complete');

        // Summarize after execution
        $afterSummary = $summarizer->summarizeAfter($input, $analysis, $result);
        if ($afterSummary !== '') {
            $progress('summary_after', "Summary: {$afterSummary}");
        }

        // Record Anthropic agent token usage in the ledger
        $agentTokens = $result->getTokenUsage();
        $this->tokenLedger->record(
            'anthropic',
            'agent',
            $agentTokens['input'] ?? 0,
            $agentTokens['output'] ?? 0,
        );

        // Log final result details
        $this->logJson('Final result', [
            'success' => $result->isSuccess(),
            'iterations' => $result->getIterations(),
            'token_usage' => $result->getTokenUsage(),
            'tool_calls' => array_map(fn($tc) => [
                'tool' => $tc['tool'] ?? 'unknown',
                'input_preview' => substr(json_encode($tc['input'] ?? []), 0, 200),
            ], $result->getToolCalls()),
            'error' => $result->getError(),
        ]);

        // Auto-create skill and append to router cache
        $skillAutoCreator->autoCreate($input, $analysis, $result, $resolvedSkills, $progress);
        if ($this->skillManager !== null) {
            $this->initSkills(); // Re-discover skills after potential creation
            // Append any new skills/tools to the router cache
            $this->syncRouterCache();
        }

        return new BotResult(
            success: $result->isSuccess(),
            answer: $result->getAnswer(),
            error: $result->getError(),
            iterations: $result->getIterations(),
            toolCalls: $result->getToolCalls(),
            tokenUsage: $result->getTokenUsage(),
            analysis: $analysis,
            tokenLedger: $this->tokenLedger,
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
     * Initialize the small model client and all its consumers.
     *
     * Prefers Apple FM (free, on-device) when available on macOS 26+.
     * Falls back to Claude Haiku (cheap cloud model) on other platforms.
     * This ensures skill filtering, summarization, context compaction,
     * and the simple agent all work regardless of platform.
     */
    private function initAppleFM(): void
    {
        $appleFMConfig = $this->config['apple_fm'] ?? [];
        $enabled = (bool) ($appleFMConfig['enabled'] ?? true);

        if (!$enabled) {
            $this->log('🍎 Small model disabled via config');

            return;
        }

        // Try Apple FM first (free, on-device, private)
        $binDir = $this->config['classifier']['bin_path'] ?? (dirname(__DIR__) . '/bin');
        $appleFMCandidate = new AppleFMClient($binDir, $this->tokenLedger);
        $appleFMCandidate->setLogger(fn(string $msg) => $this->log("🍎 {$msg}"));

        if ($appleFMCandidate->isAvailable()) {
            $this->appleFM = $appleFMCandidate;
            $this->log('🍎 Apple FM available (on-device intelligence)');
        } else {
            // Fall back to Claude Haiku (cheap cloud model)
            $this->log('🍎 Apple FM not available — falling back to ' . $this->getFastModel());
            $this->appleFM = new HaikuModelClient(
                fn() => $this->getClient(),
                $this->getFastModel(),
                $this->tokenLedger,
            );
            $this->appleFM->setLogger(fn(string $msg) => $this->log("🤏 {$msg}"));
        }

        // Create tool result summarizer if enabled
        $summarizeEnabled = (bool) ($appleFMConfig['summarize_tool_results'] ?? true);

        if ($summarizeEnabled) {
            $this->toolSummarizer = new ToolResultSummarizer(
                $this->appleFM,
                $this->tokenLedger,
                [
                    'skip_threshold' => (int) ($appleFMConfig['skip_threshold'] ?? 500),
                    'summarize_threshold' => (int) ($appleFMConfig['summarize_threshold'] ?? 800),
                ],
            );
            $this->toolSummarizer->setLogger(fn(string $msg) => $this->log("📝 {$msg}"));
            $this->log('🍎 Tool result summarization enabled');
        }

        // Create context compactor for intelligent conversation compaction
        $this->contextCompactor = new AppleFMContextCompactor(
            $this->appleFM,
            $this->tokenLedger,
            maxContextTokens: 80000,
        );
        $this->log('🍎 Context compaction enabled');

        // Create simple agent for handling bash-only tasks
        $this->simpleAgent = new AppleFMSimpleAgent($this->appleFM, $this->tokenLedger);
        $this->simpleAgent->setLogger(fn(string $msg) => $this->log("🍎 {$msg}"));
        $this->log('🍎 Simple agent enabled');

        // Create skill relevance filter for semantic validation of keyword matches
        $this->skillFilter = new AppleFMSkillFilter($this->appleFM, $this->tokenLedger);
        $this->skillFilter->setLogger(fn(string $msg) => $this->log("🍎 {$msg}"));
        $this->log('🍎 Skill relevance filter enabled');

        // Create skill prompt optimizer for condensing instructions on simple tasks
        $this->skillOptimizer = new SkillPromptOptimizer($this->appleFM, $this->tokenLedger);
        $this->skillOptimizer->setLogger(fn(string $msg) => $this->log("🍎 {$msg}"));
        $this->log('🍎 Skill prompt optimizer enabled');
    }

    /**
     * Initialize the router cache and router.
     *
     * On first boot (no cache file), generates the manifest using the
     * classifier client (auto-detects best available LLM provider).
     * On subsequent boots, loads from disk and appends any new items.
     */
    private function initRouter(): void
    {
        $storagePath = dirname($this->config['tools_storage_path'] ?? dirname(__DIR__) . '/storage/tools');
        $this->routerCache = new RouterCache($storagePath);

        // Create the classifier client (shared by cache generation + routing)
        $classifier = new ClassifierClient(
            config: $this->config['classifier'] ?? [],
            providerOverride: $this->config['classifier_provider'] ?? 'auto',
            clientFactory: fn() => $this->getClient(),
            fastModel: $this->getFastModel(),
        );
        $classifier->setLogger(fn(string $msg) => $this->log("🧠 {$msg}"));
        $classifier->setAppleFMClient($this->appleFM);
        $classifier->setTokenLedger($this->tokenLedger);

        if (!$this->routerCache->load()) {
            // First boot — generate the full manifest
            try {
                $this->log('🔄 Generating router cache (first boot)...');
                $this->routerCache->generate(
                    $classifier,
                    $this->skillManager ?? new SkillManager(dirname(__DIR__) . '/skills'),
                    $this->toolRegistry,
                );
                $this->log('✅ Router cache generated');
            } catch (\Throwable $e) {
                $this->log('⚠️ Router cache generation failed: ' . $e->getMessage());
                // Router will be null — falls back to legacy path
                $this->routerCache = null;
                $this->router = null;

                return;
            }
        } else {
            // Sync any new skills/tools that were added since last cache
            $this->syncRouterCache();
        }

        $this->router = new CachedRouter(
            $this->routerCache,
            $classifier,
            $this->skillManager,
        );
        $this->router->setLogger(fn(string $msg) => $this->log("🧠 {$msg}"));
    }

    /**
     * Sync the router cache with current skills/tools (incremental append).
     */
    private function syncRouterCache(): void
    {
        if ($this->routerCache === null || $this->skillManager === null) {
            return;
        }

        if ($this->routerCache->isStale($this->skillManager, $this->toolRegistry)) {
            $this->routerCache->sync($this->skillManager, $this->toolRegistry);
            $this->log('🔄 Router cache synced with new skills/tools');
        }
    }

    /**
     * Build the analysis array from the best available source.
     *
     * Priority: RouteResult > Skill fast-path > TaskAnalyzer LLM call
     */
    private function buildAnalysis(string $input, ?RouteResult $routeResult, array $resolvedSkills, \Closure $clientFactory): array
    {
        // If the router matched a category, use its analysis
        if ($routeResult !== null) {
            $analysis = $routeResult->toAnalysis();
            // Override skill_matched with actual resolved skills (post Apple FM filter).
            // RouteResult sets this based on category skill lists, but the resolver +
            // filter may have pruned all candidates as irrelevant.
            $analysis['skill_matched'] = !empty($resolvedSkills);
            $this->log("🎯 Task analysis complete (router tier: {$routeResult->tier})");

            return $analysis;
        }

        // Skill fast-path
        if ($this->shouldUseSkillFastPath($resolvedSkills, $input)) {
            $analysis = $this->buildSkillFastPathAnalysis($resolvedSkills[0]);
            $this->log('🎯 Task analysis complete (skill fast-path)');

            return $analysis;
        }

        // Fallback: LLM-based analysis
        $taskAnalyzer = new TaskAnalyzer($clientFactory, $this->config['model']);
        $analysis = $taskAnalyzer->analyze($input);
        $this->log('🎯 Task analysis complete (LLM)');

        return $analysis;
    }

    /**
     * Build the system prompt using tiered prompts when routed,
     * or the full legacy prompt when not.
     *
     * When the skill prompt optimizer is available and the task is simple,
     * skill instructions are condensed to the minimum steps needed for
     * the specific request, dramatically reducing prompt token usage.
     */
    private function buildSystemPrompt(
        string $input,
        ?RouteResult $routeResult,
        array $analysis,
        array $resolvedSkills,
        AgentFactory $agentFactory,
    ): string {
        $promptBuilder = new TieredPromptBuilder();
        $maxIter = (int) ($this->config['max_iterations'] ?? 25);

        if ($routeResult !== null) {
            // Use the tiered prompt from the route result
            $basePrompt = $promptBuilder->build($routeResult->promptTier, $analysis, $maxIter);
        } else {
            // Legacy: use the full prompt from AgentFactory
            $basePrompt = $agentFactory->getSystemPrompt($analysis);
        }

        // Compose with skill instructions (loaded skills get full instructions,
        // unloaded skills are no longer listed in the prompt — they're available
        // via search_capabilities instead)
        if (!empty($resolvedSkills)) {
            // Optimize skill instructions for simple/medium tasks using Apple FM.
            // This condenses full SKILL.md content into concise, task-specific steps,
            // reducing prompt size significantly (e.g. 2K chars -> 100 chars).
            $complexity = $analysis['complexity'] ?? 'medium';
            if ($this->skillOptimizer !== null && $complexity !== 'complex') {
                $optimizedInstructions = $this->skillOptimizer->optimizeAll($input, $resolvedSkills, $complexity);

                // Build the skills section manually with optimized instructions
                $sections = [$basePrompt];
                $skillLines = ["## Active Skills\n"];

                foreach ($resolvedSkills as $skill) {
                    $name = $skill->getName();
                    $skillLines[] = "### Skill: {$name}";
                    $skillLines[] = "**Description:** {$skill->getDescription()}\n";

                    // Use optimized instructions if available, fall back to original
                    $instructions = $optimizedInstructions[$name] ?? $skill->getInstructions();
                    if (!empty($instructions)) {
                        $skillLines[] = $instructions;
                    }

                    $skillLines[] = ''; // Blank line between skills
                }

                $sections[] = implode("\n", $skillLines);

                return implode("\n\n", array_filter($sections));
            }

            $composer = new SkillPromptComposer();

            // Only include loaded skills, NOT the full summaries index.
            // The search_capabilities tool replaces the need for summaries in the prompt.
            return $composer->compose($basePrompt, $resolvedSkills);
        }

        return $basePrompt;
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
            $candidates = $this->skillManager->resolve($input);

            // Apple FM semantic validation: filter out false positives
            if (!empty($candidates) && $this->skillFilter !== null) {
                $candidates = $this->skillFilter->filter($input, $candidates);
            }

            return $candidates;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Set an external file logger for writing log messages to disk.
     *
     * @param callable $logger fn(string $message): void
     */
    public function setLogger(callable $logger): void
    {
        $this->fileLogger = $logger;
    }

    private function log(string $message): void
    {
        if ($this->verbose) {
            echo $message . PHP_EOL;
        }

        if ($this->fileLogger !== null) {
            ($this->fileLogger)($message);
        }
    }

    /**
     * Log a labelled JSON structure to the file logger only (not stdout).
     */
    private function logJson(string $label, array $data): void
    {
        if ($this->fileLogger === null) {
            return;
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        ($this->fileLogger)("{$label}: {$encoded}");
    }
}
