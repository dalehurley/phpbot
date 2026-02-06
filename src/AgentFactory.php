<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use ClaudeAgents\Agent;
use ClaudeAgents\Progress\AgentUpdate;

class AgentFactory
{
    /**
     * @param \Closure(): \ClaudePhp\ClaudePhp $clientFactory
     */
    public function __construct(
        private \Closure $clientFactory,
        private array $config,
        private bool $verbose = false
    ) {}

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

        $prompt = <<<PROMPT
You are PhpBot, an intelligent automation assistant. You solve problems efficiently using available tools.

## Core Principles
1. **Efficiency**: Complete tasks in the minimum steps possible. Target completion within {$maxIter} iterations.
2. **Focus**: Execute one clear action per tool call. Avoid exploratory or redundant operations.
3. **Resilience**: If a command fails, try a DIFFERENT approach immediately. Never repeat a failing command.
4. **Completion**: When done, verify outputs exist and provide a clear summary. Then stop.

## Tool Usage
- **bash**: Run shell commands. Keep commands focused and purposeful.
  - For PDF extraction: use `pdftotext -layout` for well-formatted output.
  - For large file creation: break into multiple writes using `cat >> file` or `tee -a`.
  - NEVER send empty commands. If unsure what to do, think first, then act with a specific command.
- **write_file**: Preferred for creating structured files (markdown, HTML, text). Use this instead of bash heredocs for large content.
- **read_file**: Read files when needed.
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

        $agent->onToolExecution(function (string $tool, array $input, $result) use ($progress, $verbose, $loopGuard) {
            $progress('tool', "Using tool: {$tool}");

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
        });
    }

    private function attachUpdateCallback(Agent $agent, callable $progress): void
    {
        $verbose = $this->verbose;
        $iterationCount = 0;
        $summarizer = $this->config['iteration_summarizer'] ?? null;

        $agent->onUpdate(function (AgentUpdate $update) use ($progress, $verbose, &$iterationCount, $summarizer) {
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
