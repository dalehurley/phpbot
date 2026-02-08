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
use Dalehurley\Phpbot\Apple\AppleFMSkillFilter;
use Dalehurley\Phpbot\Apple\HaikuModelClient;
use Dalehurley\Phpbot\Apple\SmallModelClient;
use Dalehurley\Phpbot\Apple\ToolResultSummarizer;
use Dalehurley\Phpbot\Conversation\ConversationHistory;
use Dalehurley\Phpbot\Conversation\ConversationSummarizer;
use Dalehurley\Phpbot\Conversation\ConversationTurn;
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
    private ?AppleFMSkillFilter $skillFilter = null;
    private ?ConversationHistory $conversationHistory = null;
    private ?ConversationSummarizer $conversationSummarizer = null;

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

            $botResult = new BotResult(
                success: true,
                answer: $answer,
                error: null,
                iterations: 0,
                toolCalls: [],
                tokenUsage: ['input' => 0, 'output' => 0, 'total' => 0],
                analysis: ['tier' => $routeResult->tier, 'routed' => true],
                tokenLedger: $this->tokenLedger,
            );

            $this->recordConversationTurn($input, $botResult);

            return $botResult;
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

        // Register conversation_context tool when history has previous turns
        if ($this->conversationHistory !== null && !$this->conversationHistory->isEmpty()) {
            $tools[] = new Tools\ConversationContextTool($this->conversationHistory);
        }

        // Build system prompt — tiered when routed, full otherwise
        $systemPrompt = $this->buildSystemPrompt($input, $routeResult, $analysis, $resolvedSkills, $agentFactory);

        $this->log("🤖 Selected agent: {$agentType}");
        $this->log("🔧 Selected tools: " . implode(', ', array_map(fn($t) => $t->getName(), $tools)));
        $this->logJson('System prompt', ['length' => strlen($systemPrompt), 'prompt' => $systemPrompt]);
        $progress('selected', "Selected {$agentType} agent with " . count($tools) . " tools");

        // Create and run the agent
        $agent = $agentFactory->create($agentType, $tools, $systemPrompt, $analysis, $progress);
        $enhancedPrompt = $agentFactory->buildEnhancedPrompt($input, $analysis, $resolvedSkills);

        // Inject conversation context from previous turns into the enhanced prompt
        $enhancedPrompt = $this->injectConversationContext($enhancedPrompt);

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

        // Collect files created during the agent run
        $createdFiles = $this->collectCreatedFiles();

        $botResult = new BotResult(
            success: $result->isSuccess(),
            answer: $result->getAnswer(),
            error: $result->getError(),
            iterations: $result->getIterations(),
            toolCalls: $result->getToolCalls(),
            tokenUsage: $result->getTokenUsage(),
            analysis: $analysis,
            tokenLedger: $this->tokenLedger,
            rawMessages: $result->getMessages(),
            createdFiles: $createdFiles,
        );

        if (!empty($createdFiles)) {
            $this->log('📁 Files created: ' . implode(', ', $createdFiles));
        }

        $this->recordConversationTurn($input, $botResult);

        return $botResult;
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

    /**
     * Set the conversation history for multi-turn context.
     *
     * When set, previous turns are injected into the agent's prompt so
     * it can handle follow-up requests. A ConversationContextTool is
     * also registered so the agent can inspect or switch context layers.
     */
    public function setConversationHistory(ConversationHistory $history): void
    {
        $this->conversationHistory = $history;

        // Create the conversation summarizer for Layer 2 if we have a small model
        if ($this->appleFM !== null && $this->conversationSummarizer === null) {
            $this->conversationSummarizer = new ConversationSummarizer(
                $this->appleFM,
                $this->tokenLedger,
            );
            $this->conversationSummarizer->setLogger(fn(string $msg) => $this->log("💬 {$msg}"));
        }
    }

    public function getConversationHistory(): ?ConversationHistory
    {
        return $this->conversationHistory;
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
            'files_storage_path' => dirname(__DIR__) . '/storage/files',
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

        // Create skill relevance filter for semantic validation of keyword matches
        $this->skillFilter = new AppleFMSkillFilter($this->appleFM, $this->tokenLedger);
        $this->skillFilter->setLogger(fn(string $msg) => $this->log("🍎 {$msg}"));
        $this->log('🍎 Skill relevance filter enabled');

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
     * Skill instructions are included in full — Claude has ample context.
     * Optimization/condensing is only done for Apple FM calls (Phase 2a).
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

        // Compose with full skill instructions for the Claude agent.
        // No optimization here — Claude has ample context window for full
        // SKILL.md content. Optimization is only used when loading skills
        // into Apple FM's constrained context (handled in Phase 2a).
        if (!empty($resolvedSkills)) {
            $composer = new SkillPromptComposer();

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

            // Safety cap: never pass more than 3 skills through.
            // The matched skill(s) should be 1-2 at most; anything beyond
            // that inflates the prompt and triggers unnecessary optimization.
            if (count($candidates) > 3) {
                $this->log('⚠️ Capping resolved skills from ' . count($candidates) . ' to 3');
                $candidates = array_slice($candidates, 0, 3);
            }

            return $candidates;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Record a completed turn in the conversation history.
     *
     * Builds a ConversationTurn from the BotResult, generates a Layer 2
     * summary via the small model if available, and appends to history.
     */
    private function recordConversationTurn(string $input, BotResult $botResult): void
    {
        if ($this->conversationHistory === null) {
            return;
        }

        // Build the turn (Layer 1 + Layer 3 data)
        $turn = new ConversationTurn(
            userInput: $input,
            answer: $botResult->getAnswer(),
            error: $botResult->getError(),
            summary: null, // Will be filled by summarizer below
            toolCalls: $botResult->getToolCalls(),
            fullMessages: $botResult->getRawMessages(),
            metadata: [
                'iterations' => $botResult->getIterations(),
                'token_usage' => $botResult->getTokenUsage(),
                'analysis' => $botResult->getAnalysis(),
            ],
            timestamp: microtime(true),
        );

        // Generate Layer 2 summary via Apple FM / Haiku
        if ($this->conversationSummarizer !== null) {
            try {
                $summary = $this->conversationSummarizer->summarize($input, $botResult);
                if ($summary !== null) {
                    $turn = $turn->withSummary($summary);
                    $nextTurn = $this->conversationHistory->getTurnCount() + 1;
                    $this->log("💬 Conversation summary generated for turn #{$nextTurn}");
                }
            } catch (\Throwable $e) {
                $this->log("💬 Conversation summary failed: {$e->getMessage()}");
            }
        }

        $this->conversationHistory->addTurn($turn);
        $this->log("💬 Conversation turn #{$this->conversationHistory->getTurnCount()} recorded");
    }

    /**
     * Inject conversation context from previous turns into the enhanced prompt.
     *
     * Prepends a "## Previous Conversation" section so the agent can handle
     * follow-up requests. Returns the prompt unchanged when there is no history.
     */
    private function injectConversationContext(string $enhancedPrompt): string
    {
        if ($this->conversationHistory === null || $this->conversationHistory->isEmpty()) {
            return $enhancedPrompt;
        }

        $contextBlock = $this->conversationHistory->buildContextBlock();

        if ($contextBlock === '') {
            return $enhancedPrompt;
        }

        $turnCount = $this->conversationHistory->getTurnCount();
        $layer = $this->conversationHistory->getActiveLayer();
        $this->log("💬 Injecting conversation context ({$turnCount} turns, layer: {$layer->value})");

        // Prepend context before the enhanced prompt's task section
        return $contextBlock . "\n\n" . $enhancedPrompt;
    }

    /**
     * Collect files created by the WriteFileTool during the current run.
     *
     * Queries the tool registry for the write_file tool instance and
     * retrieves its tracked created files, then resets the tracker.
     *
     * @return array<string>
     */
    private function collectCreatedFiles(): array
    {
        if (!$this->toolRegistry->has('write_file')) {
            return [];
        }

        $writeTool = $this->toolRegistry->get('write_file');

        if (!$writeTool instanceof Tools\WriteFileTool) {
            return [];
        }

        $files = $writeTool->getCreatedFiles();
        $writeTool->resetCreatedFiles();

        return $files;
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
