<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use ClaudeAgents\Agent;
use ClaudeAgents\Tools\ToolResult;
use ClaudeAgents\Progress\AgentUpdate;
use Dalehurley\Phpbot\Apple\AppleFMContextCompactor;
use Dalehurley\Phpbot\Apple\ToolResultSummarizer;
use Dalehurley\Phpbot\Platform;

class AgentFactory
{
    /** @var callable|null File logger: fn(string $message) => void */
    private $fileLogger = null;

    /**
     * @param \Closure(): \ClaudePhp\ClaudePhp $clientFactory
     */
    public function __construct(
        private \Closure $clientFactory,
        private array $config,
        private bool $verbose = false
    ) {}

    /**
     * Set an external file logger for writing detailed execution logs to disk.
     */
    public function setLogger(callable $logger): void
    {
        $this->fileLogger = $logger;
    }

    public function create(
        string $agentType,
        array $tools,
        string $systemPrompt,
        array $analysis,
        callable $progress
    ): Agent {
        $client = ($this->clientFactory)();

        $agent = Agent::create($client)
            ->withName('phpbot_' . $agentType)
            ->withSystemPrompt($systemPrompt)
            ->withModel($this->config['model'])
            ->maxIterations($this->config['max_iterations'])
            ->maxTokens($this->config['max_tokens'])
            ->temperature($this->config['temperature'])
            ->withTools($tools);

        // Enable Apple FM-powered context compaction if available.
        // Uses a lower virtual context limit (80K) so compaction starts
        // early, keeping the conversation lean across iterations.
        /** @var AppleFMContextCompactor|null $compactor */
        $compactor = $this->config['context_compactor'] ?? null;

        if ($compactor !== null) {
            $agent->withContextManager($compactor);
        } else {
            // Fallback: basic context management with aggressive compaction
            $agent->withContextManagement(
                maxContextTokens: (int) ($this->config['max_context_tokens'] ?? 80000),
                options: [
                    'compact_threshold' => 0.50,
                    'auto_compact' => true,
                    'clear_tool_results' => true,
                ],
            );
        }

        // Create stale-loop guard to halt execution when the agent is stuck
        $loopGuard = new StaleLoopGuard(
            maxConsecutiveErrors: (int) ($this->config['stale_loop_max_errors'] ?? 5),
            maxConsecutiveEmpty: (int) ($this->config['stale_loop_max_empty'] ?? 3),
            maxRepeatedIdentical: (int) ($this->config['stale_loop_max_repeated'] ?? 4),
        );

        $this->attachToolCallback($agent, $progress, $loopGuard);
        $this->attachUpdateCallback($agent, $progress);

        return $agent;
    }

    public function getSystemPrompt(array $analysis): string
    {
        $definitionOfDone = implode("\n- ", $analysis['definition_of_done'] ?? ['Task completed successfully']);
        $hasSkill = !empty($analysis['skill_matched'] ?? false);
        $maxIter = (int) ($this->config['max_iterations'] ?? 25);

        $osName = Platform::osName();
        $osPlayground = Platform::osPlaygroundPrompt();
        $superpowers = Platform::superpowersPrompt();
        $openCmd = Platform::openCommand();
        $audioCmd = Platform::audioPlayCommand();
        $credSources = Platform::credentialSourcesText();

        $prompt = <<<PROMPT
You are PhpBot, an intelligent automation assistant with extraordinary capabilities. You have access to a full computer running {$osName} — a bash shell, the operating system, the internet, and the ability to write and run code in any language. This makes you capable of accomplishing virtually anything a human could do at a terminal.

## Core Principles
1. **Resourcefulness**: You can do almost anything. Your bash tool gives you the full power of the operating system — audio, video, networking, filesystems, GUI automation, clipboard, notifications, and more. If you're unsure how, explore and figure it out. The answer to "can I do X?" is almost always YES.
2. **Bias Toward Action**: NEVER respond with "I can't do that" as your primary answer. Instead, think creatively about HOW to do it. Write a script, call an API, install a package, chain commands together. Get it done or get as close as possible.
3. **Creative Problem Solving**: When a task seems impossible with your current tools, think laterally:
{$osPlayground}
   - **Any API is reachable**: `curl` can call any REST API on the internet. Need to generate speech? Call OpenAI's TTS API. Need weather? Call a weather API. Need to translate? Call a translation API.
   - **Any language is available**: Write and execute Python, Node.js, PHP, Ruby, or shell scripts to accomplish complex tasks. Install packages with `pip`, `npm`, `brew`, or `composer` as needed.
   - **Chain capabilities together**: Generate an image with an API → save to disk → `{$openCmd}` it. Generate speech with an API → save MP3 → play it with `{$audioCmd}`. Scrape a website → process the data → write a report.
4. **Efficiency**: Complete tasks in the minimum steps possible. Target completion within {$maxIter} iterations.
5. **Resilience**: If a command fails, try a DIFFERENT approach immediately. Never repeat a failing command.

## Your Superpowers (via bash)
Your bash tool is not just for running scripts — it's your interface to the entire computer. Think of it as your hands:
{$superpowers}

When you're not sure how to accomplish something, EXPLORE: check what commands exist, search for packages, read man pages. Figure it out.

## Tool Usage
- **bash**: Your primary superpower. Run shell commands, scripts, install packages, call APIs, interact with the OS. NEVER send empty commands.
- **write_file**: Preferred for creating structured files (markdown, HTML, text, scripts). Use this instead of bash heredocs for large content.
- **read_file**: Read files when needed.
- **ask_user**: Prompt the user for missing information (API keys, credentials, choices). Use when you need secrets or input to proceed.
- **get_keys**: ALWAYS check the keystore BEFORE asking for credentials. Call get_keys with the keys you need. If all_found, use them; otherwise ask_user only for the missing ones.
- **store_keys**: AFTER receiving credentials from the user, call store_keys to save them for future runs.
- **search_computer**: Search the local machine for API keys and credentials in {$credSources}. Use this AFTER get_keys returns missing keys and BEFORE ask_user.
- **tool_builder**: Create reusable tools when a pattern repeats.

## File Creation Strategy
When creating output documents:
1. Use the `write_file` tool for structured content (preferred over bash heredocs).
2. If using bash, write files in sections rather than one massive command.
3. Verify each file was created successfully before moving on.

PROMPT;

        if ($hasSkill) {
            $prompt .= <<<PROMPT

## Skill-Based Execution
An Active Skill with a proven procedure is provided below. You MUST:
1. Follow the skill's procedure steps in order.
2. Execute each step with focused, efficient tool calls (1-3 calls per step max).
3. Do NOT improvise or explore when the procedure covers the step.
4. Adapt specific commands to the current input (file paths, names, etc.).
5. If a Reference Commands section is provided, use those exact commands adapted to the current input.

PROMPT;
        }

        $prompt .= <<<PROMPT

## CRITICAL: Make It Actually Happen
When the user asks you to DO something in the real world — speak out loud, play a sound, send a message, show a notification, open something, or any action that has an effect beyond text — you MUST use tools to make it actually happen. NEVER just respond with text pretending you did it.

Your thinking process for ANY task should be:
1. **What is the desired real-world outcome?** (audio plays, file created, message sent, page opens, etc.)
2. **What tools/commands/APIs could achieve this?** Think broadly — OS commands, installed tools, APIs via curl, scripts you can write.
3. **What do I need?** (credentials → check get_keys/search_computer first, packages → install them, info → ask_user)
4. **Do it.** Start with the simplest approach. Escalate if needed.

### How to Figure Out How to Do Anything
When you encounter a task you don't immediately know how to accomplish:
1. **Think about what kind of problem it is**: Is it an OS capability? A web API? A data transformation? A file format?
2. **Explore what's available**: `which <cmd>`, `brew search <keyword>`, `pip search <keyword>`, `man <cmd>`, `ls /usr/bin/ | grep <keyword>`
3. **Search for credentials if needed**: `get_keys` → `search_computer` → `ask_user` (in that order)
4. **Try the simplest approach first**: Built-in OS commands before third-party tools, local tools before APIs, free tools before paid ones.
5. **Escalate if needed**: If the simple approach doesn't work, write a script, install a package, or call an API.

### Credential Workflow
When a task requires API keys, tokens, or credentials:
1. **get_keys** first — check the keystore for what you need
2. **search_computer** second — scan {$credSources}
3. **ask_user** last — only for credentials truly not found anywhere
4. **store_keys** after — save any new credentials the user provides for future use

## Error Recovery
- After a tool error, analyze what went wrong and try a DIFFERENT approach.
- After 2 consecutive failures on the same step, skip it or use an alternative method.
- If stuck, summarize what you have accomplished so far and move to the next step.
- NEVER repeat the exact same failing command.

## Completion Protocol
When the task is done:
1. Verify all output files exist (e.g. `ls -la` on outputs).
2. Provide a structured summary of all deliverables with file paths.
3. Stop calling tools and give your final answer text.

## Definition of Done
The task is complete when:
- {$definitionOfDone}
PROMPT;

        return $prompt;
    }

    /**
     * @param array $resolvedSkills Resolved skill objects (optional, for skill-aware prompting)
     */
    public function buildEnhancedPrompt(string $input, array $analysis, array $resolvedSkills = []): string
    {
        $complexity = $analysis['complexity'] ?? 'medium';
        $approach = $analysis['suggested_approach'] ?? 'direct';
        $estimatedSteps = (int) ($analysis['estimated_steps'] ?? 10);

        $prompt = "## Task\n{$input}\n\n";

        // Surface the real-world effect so the agent knows this isn't just a text response
        $realWorldEffect = $analysis['real_world_effect'] ?? null;
        if (!empty($analysis['requires_real_world_effect']) && $realWorldEffect) {
            $prompt .= "## IMPORTANT: This Task Requires a Real-World Outcome\n";
            $prompt .= "Expected outcome: {$realWorldEffect}\n";
            $prompt .= "You MUST use tools (bash, APIs, scripts) to make this actually happen. A text-only response is NOT acceptable.\n\n";
        }

        // Surface creative approaches from the analyzer
        $creativeApproaches = $analysis['creative_approaches'] ?? [];
        if (!empty($creativeApproaches)) {
            $prompt .= "## Suggested Approaches (from simplest to most sophisticated)\n";
            foreach ($creativeApproaches as $i => $approach_item) {
                $num = $i + 1;
                $prompt .= "{$num}. {$approach_item}\n";
            }
            $prompt .= "Start with approach #1. Escalate to the next if it fails.\n\n";
        }

        if (!empty($resolvedSkills)) {
            $skillName = method_exists($resolvedSkills[0], 'getName') ? $resolvedSkills[0]->getName() : 'matched-skill';
            $prompt .= "## Execution Strategy\n";
            $prompt .= "A proven skill procedure ('{$skillName}') is active for this task type.\n";
            $prompt .= "Follow the Active Skill procedure step-by-step. Each step should take 1-3 tool calls.\n";
            $prompt .= "Target: Complete this task efficiently in ~{$estimatedSteps} steps.\n\n";
        } elseif ($complexity === 'complex' || $approach === 'plan_execute') {
            $prompt .= "## Approach\nThis task is complex. Please:\n";
            $prompt .= "1. Plan your approach briefly (in your thinking, not as output).\n";
            $prompt .= "2. Execute each step with focused tool calls.\n";
            $prompt .= "3. Verify results after each major step.\n";
            $prompt .= "4. Provide a clear completion summary.\n\n";
        }

        if (!empty($analysis['definition_of_done'])) {
            $prompt .= "## Success Criteria\n";
            foreach ($analysis['definition_of_done'] as $criterion) {
                $prompt .= "- {$criterion}\n";
            }
            $prompt .= "\n";
        }

        $prompt .= "## Efficiency Target\n";
        $prompt .= "Complete this task in approximately {$estimatedSteps} steps. Work efficiently and avoid redundant operations.\n";

        return $prompt;
    }

    private function attachToolCallback(Agent $agent, callable $progress, StaleLoopGuard $loopGuard): void
    {
        $verbose = $this->verbose;
        $fileLog = $this->fileLogger;
        /** @var ToolResultSummarizer|null $summarizer */
        $summarizer = $this->config['tool_result_summarizer'] ?? null;

        $agent->onToolExecution(function (string $tool, array $input, $result) use ($progress, $verbose, $fileLog, $loopGuard, $summarizer) {
            $progress('tool', "Using tool: {$tool}");

            // Always log detailed tool execution to file
            if ($fileLog !== null) {
                $fileLog("─── Tool: {$tool} ───");
                $inputJson = json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $fileLog("Input: {$inputJson}");

                if (is_object($result) && method_exists($result, 'getContent')) {
                    $content = $result->getContent();
                    $isError = method_exists($result, 'isError') && $result->isError();
                    $status = $isError ? 'ERROR' : 'OK';
                    $maxLogChars = 3000;
                    if (strlen($content) > $maxLogChars) {
                        $truncated = substr($content, 0, $maxLogChars);
                        $fileLog("Result ({$status}, " . strlen($content) . " chars, truncated): {$truncated}…");
                    } else {
                        $fileLog("Result ({$status}): {$content}");
                    }
                }
            }

            if ($verbose) {
                $this->log("🔧 Tool '{$tool}' executed");
                $this->log("   Input: " . json_encode($input));
                $content = $result->getContent();
                $this->log("   Result: " . substr($content, 0, 200) . (strlen($content) > 200 ? '...' : ''));
            }

            if ($tool === 'bash' && is_object($result) && method_exists($result, 'getContent')) {
                $bashSummary = $this->summarizeBashCall($result->getContent());
                if ($bashSummary !== '') {
                    $progress('bash_call', $bashSummary);
                }
            }

            // Stale-loop detection — throws RuntimeException if stuck,
            // which propagates to the ReactLoop's catch block and halts the agent.
            $isError = is_object($result) && method_exists($result, 'isError') && $result->isError();
            $loopGuard->record($tool, $input, $isError);

            // Summarize large tool results via Apple FM to reduce tokens
            // sent to the main LLM. Returns a new ToolResult if summarized.
            if ($summarizer !== null && $result instanceof ToolResult && $summarizer->shouldSummarize($tool, $result)) {
                return $summarizer->summarize($tool, $input, $result);
            }

            return null; // no transformation
        });
    }

    private function attachUpdateCallback(Agent $agent, callable $progress): void
    {
        $verbose = $this->verbose;
        $fileLog = $this->fileLogger;
        $iterationCount = 0;
        $summarizer = $this->config['iteration_summarizer'] ?? null;

        $agent->onUpdate(function (AgentUpdate $update) use ($progress, $verbose, $fileLog, &$iterationCount, $summarizer) {
            switch ($update->getType()) {
                case 'agent.start':
                    $progress('agent_start', 'Agent started working...');
                    break;
                case 'agent.completed':
                    $progress('agent_complete', 'Agent finished');
                    if ($fileLog !== null) {
                        $data = $update->getData();
                        if (is_array($data)) {
                            $tokens = $data['token_usage'] ?? $data['tokens'] ?? null;
                            if ($tokens !== null) {
                                $fileLog('Agent token usage: ' . json_encode($tokens));
                            }
                        }
                    }
                    break;
                case 'llm.iteration':
                    $iterationCount++;
                    $progress('iteration', "Thinking... (iteration {$iterationCount})");

                    $data = $update->getData();
                    $text = is_array($data) ? trim((string) ($data['text'] ?? '')) : '';

                    // Log full LLM response text to file
                    if ($fileLog !== null && $text !== '') {
                        $fileLog("═══ LLM Response (iteration {$iterationCount}) ═══");
                        $maxLogChars = 5000;
                        if (strlen($text) > $maxLogChars) {
                            $fileLog(substr($text, 0, $maxLogChars) . '… (truncated, ' . strlen($text) . ' chars total)');
                        } else {
                            $fileLog($text);
                        }
                    }

                    // Throttle iteration summaries: only every 3rd iteration and when
                    // text is substantial, to reduce extra LLM calls for progress display.
                    $shouldSummarize = $text !== ''
                        && strlen($text) > 100
                        && ($iterationCount <= 1 || $iterationCount % 3 === 0)
                        && $summarizer instanceof ProgressSummarizer;
                    if ($shouldSummarize) {
                        $summary = $summarizer->summarizeIteration($text);
                        if ($summary !== '') {
                            $progress('iteration_summary', "Iteration {$iterationCount}: {$summary}");
                        }
                    }
                    break;
                default:
                    break;
            }

            if ($verbose) {
                match ($update->getType()) {
                    'agent.start' => $this->log("🚀 Agent started"),
                    'agent.completed' => $this->log("✅ Agent completed"),
                    'llm.iteration' => $this->log("🔄 Iteration"),
                    default => null,
                };
            }
        });
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

    private function log(string $message): void
    {
        if ($this->verbose) {
            echo $message . PHP_EOL;
        }
    }
}
