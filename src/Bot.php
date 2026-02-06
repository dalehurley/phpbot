<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use ClaudeAgents\Agent;
use ClaudeAgents\Tools\ToolRegistry;
use ClaudeAgents\Progress\AgentUpdate;
use ClaudePhp\ClaudePhp;
use ClaudeAgents\Skills\SkillManager;
use ClaudeAgents\Skills\SkillPromptComposer;
use Dalehurley\Phpbot\Tools\BashTool;
use Dalehurley\Phpbot\Tools\ReadFileTool;
use Dalehurley\Phpbot\Tools\WriteFileTool;
use Dalehurley\Phpbot\Tools\EditFileTool;
use Dalehurley\Phpbot\Tools\SkillScriptTool;
use Dalehurley\Phpbot\Tools\AskUserTool;
use Dalehurley\Phpbot\Tools\ToolBuilderTool;
use Dalehurley\Phpbot\Tools\ToolPromoterTool;
use Dalehurley\Phpbot\Registry\PersistentToolRegistry;
use Dalehurley\Phpbot\Agent\AgentSelector;
use Dalehurley\Phpbot\Storage\KeyStore;

class Bot
{
    private ?ClaudePhp $client = null;
    private PersistentToolRegistry $toolRegistry;
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
        $this->agentSelector = new AgentSelector();

        $this->registerCoreTools();
        $this->initSkills();
        $this->registerSkillScriptTools();
        $this->initKeyStore();
    }

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
            'timeout' => 120.0,
            'tools_storage_path' => dirname(__DIR__) . '/storage/tools',
            'skills_path' => dirname(__DIR__) . '/skills',
            'keys_storage_path' => dirname(__DIR__) . '/storage/keys.json',
        ];
    }

    private function registerCoreTools(): void
    {
        // Register the bash tool for command execution
        $this->toolRegistry->register(new BashTool($this->config));
        $this->toolRegistry->register(new ReadFileTool());
        $this->toolRegistry->register(new WriteFileTool());
        $this->toolRegistry->register(new EditFileTool());
        $this->toolRegistry->register(new AskUserTool());

        // Register the tool builder that allows creating new tools
        $this->toolRegistry->register(new ToolBuilderTool($this->toolRegistry));

        // Register the tool promoter that converts JSON tools to PHP classes
        $this->toolRegistry->register(new ToolPromoterTool($this->toolRegistry));

        // Load any persisted custom tools
        $this->toolRegistry->loadPersistedTools();

        // Register any promoted PHP tools
        $this->registerPromotedTools();
    }

    /**
     * @param callable|null $onProgress Callback for progress updates: fn(string $stage, string $message)
     */
    public function run(string $input, ?callable $onProgress = null): BotResult
    {
        $progress = $onProgress ?? fn($stage, $msg) => null;

        $this->log("📝 Received input: {$input}");
        $progress('start', 'Received input');

        // Analyze the input to determine definition of done
        $progress('analyzing', 'Analyzing task requirements...');
        $analysis = $this->analyzeTask($input);
        $this->log("🎯 Task analysis complete");
        $progress('analyzed', 'Task analysis complete');

        $beforeSummary = $this->summarizeBefore($input, $analysis);
        if ($beforeSummary !== '') {
            $progress('summary_before', "Summary: {$beforeSummary}");
        }

        $resolvedSkills = $this->resolveSkills($input);
        if (!empty($resolvedSkills)) {
            $progress('skills', 'Skills: ' . implode(', ', array_map(fn($s) => $s->getName(), $resolvedSkills)));
        }

        // Select appropriate agent and tools
        $agentType = $this->agentSelector->selectAgent($analysis);
        $tools = $this->selectTools($analysis);
        $systemPrompt = $this->composeSystemPrompt($analysis, $resolvedSkills);

        $this->log("🤖 Selected agent: {$agentType}");
        $this->log("🔧 Selected tools: " . implode(', ', array_map(fn($t) => $t->getName(), $tools)));
        $progress('selected', "Selected {$agentType} agent with " . count($tools) . " tools");

        // Create and configure the agent
        $agent = $this->createAgent($agentType, $tools, $systemPrompt, $analysis, $progress);

        // Build the enhanced prompt with definition of done
        $enhancedPrompt = $this->buildEnhancedPrompt($input, $analysis);

        // Execute the agent
        $progress('executing', 'Executing task...');
        $result = $agent->run($enhancedPrompt);

        if (! $result->isSuccess() && $this->isToolInputDictionaryError($result->getError())) {
            $progress('recovery', 'Tool input format error detected. Retrying with strict tool input guidance...');
            $this->log("⚠️ Tool input format error detected. Retrying once with stricter tool guidance.");

            $recoverySystemPrompt = $this->withToolInputGuard($systemPrompt);
            $recoveryPrompt = $this->buildRecoveryPrompt($input, $analysis);
            $recoveryAgent = $this->createAgent($agentType, $tools, $recoverySystemPrompt, $analysis, $progress);

            $result = $recoveryAgent->run($recoveryPrompt);
        }
        $progress('complete', 'Task execution complete');

        $afterSummary = $this->summarizeAfter($input, $analysis, $result);
        if ($afterSummary !== '') {
            $progress('summary_after', "Summary: {$afterSummary}");
        }

        $this->autoCreateSkill($input, $analysis, $result, $resolvedSkills, $progress);

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

    private function analyzeTask(string $input): array
    {
        $analysisAgent = Agent::create($this->getClient())
            ->withName('task_analyzer')
            ->withSystemPrompt($this->getAnalysisSystemPrompt())
            ->withModel($this->config['model'])
            ->maxIterations(1)
            ->maxTokens(2048);

        $result = $analysisAgent->run("Analyze this task and respond with JSON only:\n\n{$input}");

        $analysis = json_decode($result->getAnswer(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Fallback to basic analysis
            return [
                'task_type' => 'general',
                'complexity' => 'medium',
                'requires_bash' => str_contains(strtolower($input), 'run') ||
                    str_contains(strtolower($input), 'execute') ||
                    str_contains(strtolower($input), 'command'),
                'requires_file_ops' => str_contains(strtolower($input), 'file') ||
                    str_contains(strtolower($input), 'read') ||
                    str_contains(strtolower($input), 'write'),
                'requires_tool_creation' => str_contains(strtolower($input), 'create tool') ||
                    str_contains(strtolower($input), 'new tool') ||
                    str_contains(strtolower($input), 'build tool'),
                'definition_of_done' => ['Task completed successfully'],
                'suggested_approach' => 'direct',
                'estimated_steps' => 1,
            ];
        }

        return $analysis;
    }

    private function getAnalysisSystemPrompt(): string
    {
        return <<<PROMPT
You are a task analyzer. Analyze the user's request and output a JSON object with the following structure:

{
    "task_type": "general|coding|research|automation|data_processing|problem_solving",
    "complexity": "simple|medium|complex",
    "requires_bash": true/false,
    "requires_file_ops": true/false,
    "requires_tool_creation": true/false,
    "requires_planning": true/false,
    "requires_reflection": true/false,
    "definition_of_done": ["list", "of", "completion", "criteria"],
    "suggested_approach": "direct|plan_execute|reflection|chain_of_thought",
    "estimated_steps": number,
    "potential_tools_needed": ["bash", "file_system", "custom_tool_name"]
}

Respond with ONLY the JSON object, no additional text.
PROMPT;
    }

    private function selectTools(array $analysis): array
    {
        $tools = [];

        // Always include bash for flexibility
        if ($this->toolRegistry->has('bash')) {
            $tools[] = $this->toolRegistry->get('bash');
        }

        // Always include tool builder for evolution capability
        if ($this->toolRegistry->has('tool_builder')) {
            $tools[] = $this->toolRegistry->get('tool_builder');
        }

        // Always include tool promoter for stabilization capability
        if ($this->toolRegistry->has('tool_promoter')) {
            $tools[] = $this->toolRegistry->get('tool_promoter');
        }

        // Add any custom tools that might be relevant
        $customTools = $this->toolRegistry->getCustomTools();
        foreach ($customTools as $tool) {
            $tools[] = $tool;
        }

        // Add specific tools based on analysis
        if (!empty($analysis['potential_tools_needed'])) {
            foreach ($analysis['potential_tools_needed'] as $toolName) {
                if ($this->toolRegistry->has($toolName) && !in_array($toolName, ['bash', 'tool_builder'])) {
                    $tools[] = $this->toolRegistry->get($toolName);
                }
            }
        }

        return $tools;
    }

    private function createAgent(string $agentType, array $tools, string $systemPrompt, array $analysis, callable $progress): Agent
    {
        $agent = Agent::create($this->getClient())
            ->withName('phpbot_' . $agentType)
            ->withSystemPrompt($systemPrompt)
            ->withModel($this->config['model'])
            ->maxIterations($this->config['max_iterations'])
            ->maxTokens($this->config['max_tokens'])
            ->temperature($this->config['temperature'])
            ->withTools($tools)
            // Enable context management to prevent message structure corruption on long conversations
            ->withContextManagement(150000, [
                'clear_tool_results' => false,
                'preserve_system_prompt' => true,
            ]);

        // Always add progress callbacks for tool execution
        $agent->onToolExecution(function (string $tool, array $input, $result) use ($progress) {
            $progress('tool', "Using tool: {$tool}");
            if ($this->verbose) {
                $this->log("🔧 Tool '{$tool}' executed");
                $this->log("   Input: " . json_encode($input));
                $this->log("   Result: " . substr($result->getContent(), 0, 200) . (strlen($result->getContent()) > 200 ? '...' : ''));
            }

            if ($tool === 'bash' && is_object($result) && method_exists($result, 'getContent')) {
                $bashSummary = $this->summarizeBashCall($result->getContent());
                if ($bashSummary !== '') {
                    $progress('bash_call', $bashSummary);
                }
            }
        });

        $iterationCount = 0;
        $agent->onUpdate(function (AgentUpdate $update) use ($progress, &$iterationCount) {
            switch ($update->getType()) {
                case 'agent.start':
                    $progress('agent_start', 'Agent started working...');
                    break;
                case 'agent.completed':
                    $progress('agent_complete', 'Agent finished');
                    break;
                case 'llm.iteration':
                    $iterationCount++;
                    $progress('iteration', "Thinking... (iteration {$iterationCount})");

                    $data = $update->getData();
                    $text = is_array($data) ? trim((string) ($data['text'] ?? '')) : '';
                    if ($text !== '') {
                        $summary = $this->summarizeIteration($text);
                        if ($summary !== '') {
                            $progress('iteration_summary', "Iteration {$iterationCount}: {$summary}");
                        }
                    }
                    break;
                default:
                    break;
            }

            if ($this->verbose) {
                match ($update->getType()) {
                    'agent.start' => $this->log("🚀 Agent started"),
                    'agent.completed' => $this->log("✅ Agent completed"),
                    'llm.iteration' => $this->log("🔄 Iteration"),
                    default => null,
                };
            }
        });

        return $agent;
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

    private function composeSystemPrompt(array $analysis, array $resolvedSkills): string
    {
        $basePrompt = $this->getAgentSystemPrompt($analysis);
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

    private function autoCreateSkill(string $input, array $analysis, $result, array $resolvedSkills, callable $progress): void
    {
        if (!($result && $result->isSuccess())) {
            return;
        }

        if ($this->skillManager === null) {
            return;
        }

        if (!empty($resolvedSkills)) {
            return;
        }

        $skillsPath = $this->config['skills_path'] ?? dirname(__DIR__) . '/skills';
        if (!is_string($skillsPath) || $skillsPath === '' || !is_dir($skillsPath)) {
            return;
        }

        $slug = $this->slugifySkillName($input);
        if ($slug === '') {
            return;
        }

        $dir = $skillsPath . '/' . $slug;
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $description = $this->buildSkillDescription($input, $analysis);
        $skillMd = $this->buildSkillMarkdown($slug, $description, $input, $result->getAnswer() ?? '');
        file_put_contents($dir . '/SKILL.md', $skillMd);

        $this->initSkills();
        $progress('skills', "Created skill: {$slug}");
    }

    private function slugifySkillName(string $input): string
    {
        $input = strtolower(trim($input));
        if ($input === '') {
            return '';
        }

        $input = preg_replace('/[^a-z0-9]+/', '-', $input);
        $input = trim($input, '-');

        if (strlen($input) > 48) {
            $input = substr($input, 0, 48);
        }

        return $input;
    }

    private function buildSkillDescription(string $input, array $analysis): string
    {
        $type = $analysis['task_type'] ?? 'task';
        $summary = trim(preg_replace('/\s+/', ' ', $input));
        if (strlen($summary) > 120) {
            $summary = substr($summary, 0, 120) . '...';
        }

        return "Repeatable workflow for {$type}: {$summary}";
    }

    private function buildSkillMarkdown(string $name, string $description, string $input, string $answer): string
    {
        $safeAnswer = trim($answer);
        if (strlen($safeAnswer) > 800) {
            $safeAnswer = substr($safeAnswer, 0, 800) . "\n...";
        }

        $escapedInput = trim($input);
        $frontmatter = <<<YAML
---
name: {$name}
description: {$description}
tags: [auto-generated]
version: 0.1.0
---
YAML;

        return $frontmatter . "\n\n" . <<<MD
# Skill: {$name}

## When to Use
Use this skill to repeat the following request:

```
{$escapedInput}
```

## Procedure
1. Follow the same steps used to complete the task previously.
2. Re-run any required tools or scripts.
3. Validate the output.

## Last Result (truncated)
```
{$safeAnswer}
```
MD;
    }

    private function summarizeBefore(string $input, array $analysis): string
    {
        try {
            $summaryAgent = Agent::create($this->getClient())
                ->withName('nano_summary_before')
                ->withSystemPrompt('You are a concise assistant. Summarize the intended plan in 1-2 short sentences.')
                ->withModel($this->getFastModel())
                ->maxIterations(1)
                ->maxTokens(256)
                ->temperature(0.2);

            $payload = [
                'input' => $input,
                'analysis' => $analysis,
            ];

            $result = $summaryAgent->run("Summarize the plan based on this JSON:\n" . json_encode($payload));
            return trim((string) $result->getAnswer());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function summarizeAfter(string $input, array $analysis, $result): string
    {
        try {
            $summaryAgent = Agent::create($this->getClient())
                ->withName('nano_summary_after')
                ->withSystemPrompt('You are a concise assistant. Summarize what happened and the outcome in 1-2 short sentences.')
                ->withModel($this->getFastModel())
                ->maxIterations(1)
                ->maxTokens(256)
                ->temperature(0.2);

            $payload = [
                'input' => $input,
                'analysis' => $analysis,
                'success' => $result->isSuccess(),
                'answer' => $result->getAnswer(),
                'error' => $result->getError(),
                'iterations' => $result->getIterations(),
                'tool_calls' => $result->getToolCalls(),
            ];

            $prompt = "Summarize the execution based on this JSON:\n" . json_encode($payload);
            $summary = $summaryAgent->run($prompt);
            return trim((string) $summary->getAnswer());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function summarizeIteration(string $text): string
    {
        try {
            $summaryAgent = Agent::create($this->getClient())
                ->withName('nano_iteration_summary')
                ->withSystemPrompt('You are a concise assistant. Summarize the assistant message in 1 short sentence focused on intent or next action. Do not include chain-of-thought.')
                ->withModel($this->getFastModel())
                ->maxIterations(1)
                ->maxTokens(128)
                ->temperature(0.2);

            $prompt = "Summarize this assistant message:\n" . $text;
            $summary = $summaryAgent->run($prompt);
            return trim((string) $summary->getAnswer());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function summarizeBashCall(string $resultContent): string
    {
        $decoded = json_decode($resultContent, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return '';
        }

        if (!isset($decoded['command'])) {
            return '';
        }

        $command = (string) $decoded['command'];
        $exitCode = $decoded['exit_code'] ?? null;
        $summary = "bash: {$command}";

        if ($exitCode !== null) {
            $summary .= " (exit {$exitCode})";
        }

        return $summary;
    }

    private function getFastModel(): string
    {
        $fast = $this->config['fast_model'] ?? '';
        if (is_string($fast) && $fast !== '') {
            return $fast;
        }

        return $this->config['model'];
    }

    private function registerPromotedTools(): void
    {
        $promotedDir = dirname(__DIR__) . '/src/Tools/Promoted';
        if (!is_dir($promotedDir)) {
            return;
        }

        $files = glob($promotedDir . '/*.php') ?: [];
        foreach ($files as $file) {
            require_once $file;

            $className = pathinfo($file, PATHINFO_FILENAME);
            $fqcn = 'Dalehurley\\Phpbot\\Tools\\Promoted\\' . $className;

            if (!class_exists($fqcn)) {
                continue;
            }

            $tool = new $fqcn();
            if ($tool instanceof \ClaudeAgents\Contracts\ToolInterface) {
                $this->toolRegistry->register($tool);
            }
        }
    }

    private function registerSkillScriptTools(): void
    {
        $skillsPath = $this->config['skills_path'] ?? dirname(__DIR__) . '/skills';
        if (!is_string($skillsPath) || $skillsPath === '' || !is_dir($skillsPath)) {
            return;
        }

        $scriptFiles = $this->findSkillScripts($skillsPath);
        foreach ($scriptFiles as $scriptPath) {
            $tool = $this->createScriptTool($skillsPath, $scriptPath);
            if ($tool !== null && !$this->toolRegistry->has($tool->getName())) {
                $this->toolRegistry->register($tool);
            }
        }
    }

    private function findSkillScripts(string $skillsPath): array
    {
        $scripts = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($skillsPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (strpos($path, DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR) === false) {
                continue;
            }

            $extension = strtolower((string) $file->getExtension());
            if (!in_array($extension, ['py', 'sh', 'js', 'php'], true)) {
                continue;
            }

            $basename = $file->getBasename();
            if (str_starts_with($basename, '__')) {
                continue;
            }

            $scripts[] = $path;
        }

        return $scripts;
    }

    private function createScriptTool(string $skillsPath, string $scriptPath): ?SkillScriptTool
    {
        $extension = strtolower(pathinfo($scriptPath, PATHINFO_EXTENSION));
        $interpreter = match ($extension) {
            'py' => 'python3',
            'sh' => 'bash',
            'js' => 'node',
            'php' => 'php',
            default => null,
        };

        if ($interpreter === null) {
            return null;
        }

        $relative = ltrim(str_replace($skillsPath, '', $scriptPath), DIRECTORY_SEPARATOR);
        $parts = preg_split('/[\/\\\\]+/', $relative);
        $skillName = $parts[0] ?? 'skill';
        $scriptBase = pathinfo($scriptPath, PATHINFO_FILENAME);

        $toolName = $this->toToolName($skillName, $scriptBase);
        $description = "Run {$scriptBase} script for {$skillName} skill";

        return new SkillScriptTool($toolName, $description, $scriptPath, $interpreter);
    }

    private function toToolName(string $skillName, string $scriptBase): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $skillName . '_' . $scriptBase));
        $slug = trim($slug, '_');
        return 'skill_' . $slug;
    }

    private function getAgentSystemPrompt(array $analysis): string
    {
        $definitionOfDone = implode("\n- ", $analysis['definition_of_done'] ?? ['Task completed successfully']);

        return <<<PROMPT
You are PhpBot, an intelligent automation assistant that can solve problems, execute commands, and evolve by creating new tools.
You can always achieve the task by being creative: use bash for system operations and create new tools when needed to solve any problem.

## Your Capabilities
1. **Execute bash commands** using the `bash` tool to interact with the system
2. **Create new tools** using the `tool_builder` tool when you encounter problems that could benefit from a reusable solution
3. **Use any available tools** that have been previously created and registered

## Guidelines
- Break down complex tasks into smaller steps
- Use bash for system interactions, file operations, and command execution
- When you find yourself doing the same operation multiple times, consider creating a tool for it
- Always verify your work before marking a task complete
- If a tool doesn't exist but would be useful, create it using tool_builder

## Definition of Done for Current Task
The task is complete when:
- {$definitionOfDone}

## Tool Creation Philosophy
When you create a new tool:
1. Make it general enough to be reusable
2. Include clear parameter descriptions
3. Handle errors gracefully
4. The tool will be saved and available in future sessions

Think step by step. Verify your results. Create tools when they would help you or future tasks.
PROMPT;
    }

    private function buildEnhancedPrompt(string $input, array $analysis): string
    {
        $complexity = $analysis['complexity'] ?? 'medium';
        $approach = $analysis['suggested_approach'] ?? 'direct';

        $prompt = "## Task\n{$input}\n\n";

        if ($complexity === 'complex' || $approach === 'plan_execute') {
            $prompt .= "## Approach\nThis task is complex. Please:\n1. First, create a plan\n2. Execute each step\n3. Verify the results\n4. Report completion status\n\n";
        }

        if (!empty($analysis['definition_of_done'])) {
            $prompt .= "## Success Criteria\n";
            foreach ($analysis['definition_of_done'] as $criterion) {
                $prompt .= "- {$criterion}\n";
            }
        }

        return $prompt;
    }

    private function buildRecoveryPrompt(string $input, array $analysis): string
    {
        $prompt = $this->buildEnhancedPrompt($input, $analysis);
        $prompt .= "\n\n## Tool Input Requirements\n";
        $prompt .= "- For every tool call, the `input` must be a JSON object (dictionary).\n";
        $prompt .= "- Never pass a string, array, or null as `input`.\n";
        $prompt .= "- If the tool expects a single string, wrap it with the correct parameter name.\n";
        $prompt .= "- If no parameters are needed, use `{}`.\n";

        return $prompt;
    }

    private function withToolInputGuard(string $systemPrompt): string
    {
        return $systemPrompt . "\n\n## Tool Input Guard\n"
            . "- Every tool call must use an `input` JSON object (dictionary).\n"
            . "- Never emit a string, array, or null for `input`.\n"
            . "- Use `{}` when no arguments are required.\n";
    }

    private function isToolInputDictionaryError(?string $error): bool
    {
        if ($error === null || $error === '') {
            return false;
        }

        $lower = strtolower($error);

        return str_contains($lower, 'tool_use.input')
            || str_contains($lower, 'input should be a valid dictionary')
            || (str_contains($lower, 'tool_use') && str_contains($lower, 'dictionary'));
    }

    private function log(string $message): void
    {
        if ($this->verbose) {
            echo $message . PHP_EOL;
        }
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
        $skillsPath = $this->config['skills_path'] ?? dirname(__DIR__) . '/skills';
        if (!is_string($skillsPath) || $skillsPath === '' || !is_dir($skillsPath)) {
            return [];
        }

        $scripts = $this->findSkillScripts($skillsPath);
        $tools = [];

        foreach ($scripts as $scriptPath) {
            $tool = $this->createScriptTool($skillsPath, $scriptPath);
            if ($tool !== null) {
                $tools[] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'script' => $scriptPath,
                ];
            }
        }

        return $tools;
    }
}
