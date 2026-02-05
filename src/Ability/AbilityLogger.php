<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Ability;

use ClaudeAgents\Agent;
use ClaudePhp\ClaudePhp;
use Dalehurley\Phpbot\Storage\AbilityStore;

/**
 * Sub-agent that analyzes completed execution traces to identify new abilities.
 *
 * After the bot completes a request, this logger examines what happened:
 * - Did the bot encounter obstacles?
 * - Did it try something that failed, then adapt and succeed?
 * - Did it discover a new strategy or workaround?
 *
 * Like a child learning to use a step stool to reach something high,
 * the bot records these problem-solving patterns as "abilities" for future use.
 */
class AbilityLogger
{
    private ClaudePhp $client;
    private AbilityStore $store;
    private string $model;

    public function __construct(ClaudePhp $client, AbilityStore $store, string $model)
    {
        $this->client = $client;
        $this->store = $store;
        $this->model = $model;
    }

    /**
     * Analyze execution results and log any new abilities discovered.
     *
     * @return array List of newly logged abilities (may be empty)
     */
    public function analyze(string $originalInput, array $analysis, $agentResult): array
    {
        $executionTrace = $this->buildExecutionTrace($originalInput, $analysis, $agentResult);

        $existingSummaries = $this->store->summaries();
        $existingContext = '';
        if (!empty($existingSummaries)) {
            $existingContext = "\n\n## Existing Abilities (do NOT duplicate these)\n";
            foreach ($existingSummaries as $summary) {
                $tags = implode(', ', $summary['tags'] ?? []);
                $existingContext .= "- [{$summary['id']}] {$summary['title']}: {$summary['description']}";
                if ($tags !== '') {
                    $existingContext .= " (tags: {$tags})";
                }
                $existingContext .= "\n";
            }
        }

        $loggerAgent = Agent::create($this->client)
            ->withName('ability_logger')
            ->withSystemPrompt($this->getSystemPrompt())
            ->withModel($this->model)
            ->maxIterations(1)
            ->maxTokens(2048)
            ->temperature(0.3);

        $prompt = "Analyze this execution trace for new abilities learned:\n\n"
            . $executionTrace
            . $existingContext
            . "\n\nRespond with ONLY a JSON array of new abilities, or an empty array [] if none were learned.";

        try {
            $result = $loggerAgent->run($prompt);
            $answer = trim((string) $result->getAnswer());

            return $this->parseAndStore($answer);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function buildExecutionTrace(string $input, array $analysis, $agentResult): string
    {
        $trace = "## Original Request\n{$input}\n\n";

        $trace .= "## Task Analysis\n";
        $trace .= "- Type: " . ($analysis['task_type'] ?? 'unknown') . "\n";
        $trace .= "- Complexity: " . ($analysis['complexity'] ?? 'unknown') . "\n";
        $trace .= "- Steps estimated: " . ($analysis['estimated_steps'] ?? '?') . "\n\n";

        $trace .= "## Execution Result\n";
        $trace .= "- Success: " . ($agentResult->isSuccess() ? 'yes' : 'no') . "\n";
        $trace .= "- Iterations used: " . $agentResult->getIterations() . "\n";

        $toolCalls = $agentResult->getToolCalls();
        if (!empty($toolCalls)) {
            $trace .= "- Tools called: " . implode(', ', array_unique(array_column($toolCalls, 'tool'))) . "\n";
            $trace .= "\n## Tool Call Sequence\n";
            foreach ($toolCalls as $i => $call) {
                $toolName = $call['tool'] ?? 'unknown';
                $input_data = $call['input'] ?? [];
                $inputSummary = is_array($input_data) ? json_encode($input_data) : (string) $input_data;
                if (strlen($inputSummary) > 300) {
                    $inputSummary = substr($inputSummary, 0, 300) . '...';
                }
                $resultContent = $call['result'] ?? '';
                if (is_string($resultContent) && strlen($resultContent) > 300) {
                    $resultContent = substr($resultContent, 0, 300) . '...';
                }
                $trace .= ($i + 1) . ". **{$toolName}** input: {$inputSummary}";
                if ($resultContent !== '') {
                    $trace .= " → result: {$resultContent}";
                }
                $trace .= "\n";
            }
        }

        $answer = $agentResult->getAnswer();
        if ($answer !== null && $answer !== '') {
            $truncatedAnswer = strlen($answer) > 500 ? substr($answer, 0, 500) . '...' : $answer;
            $trace .= "\n## Final Answer (truncated)\n{$truncatedAnswer}\n";
        }

        $error = $agentResult->getError();
        if ($error !== null && $error !== '') {
            $trace .= "\n## Error\n{$error}\n";
        }

        return $trace;
    }

    private function parseAndStore(string $answer): array
    {
        // Extract JSON array from response (handle markdown code blocks)
        $json = $answer;
        if (preg_match('/```(?:json)?\s*(\[[\s\S]*?\])\s*```/', $answer, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\[[\s\S]*\])/', $answer, $matches)) {
            $json = $matches[1];
        }

        $abilities = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($abilities)) {
            return [];
        }

        $stored = [];
        foreach ($abilities as $ability) {
            if (!is_array($ability) || empty($ability['title'])) {
                continue;
            }

            $record = [
                'title' => (string) $ability['title'],
                'description' => (string) ($ability['description'] ?? ''),
                'obstacle' => (string) ($ability['obstacle'] ?? ''),
                'strategy' => (string) ($ability['strategy'] ?? ''),
                'outcome' => (string) ($ability['outcome'] ?? ''),
                'tags' => array_map('strval', (array) ($ability['tags'] ?? [])),
            ];

            $id = $this->store->save($record);
            $record['id'] = $id;
            $stored[] = $record;
        }

        return $stored;
    }

    private function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an ability extraction agent. Your job is to analyze execution traces and identify NEW problem-solving abilities that were learned during task execution.

An "ability" is a reusable problem-solving pattern where:
1. The bot encountered an obstacle or challenge
2. It tried an approach (which may or may not have worked initially)
3. It adapted, found a workaround, or discovered a better method
4. It succeeded through this new approach

Think of it like a child learning: reaching for something high, failing, then getting a step stool and succeeding. The learned ability is "use a step stool for high things."

## What qualifies as an ability:
- A workaround discovered for a common problem
- A multi-step strategy that overcame an obstacle
- A tool combination that solved something non-obvious
- An error recovery pattern (tried X, got error, fixed by doing Y)
- A technique for handling a specific type of task

## What does NOT qualify:
- Simple, straightforward task completions with no obstacles
- Tasks that completed on the first try with no adaptation
- Generic knowledge that any developer would know
- Abilities that already exist in the existing abilities list

## Output Format
Respond with ONLY a JSON array. Each ability object should have:
```json
[
    {
        "title": "Short descriptive title of the ability",
        "description": "What this ability lets the bot do",
        "obstacle": "What problem or challenge was encountered",
        "strategy": "The approach or technique that worked",
        "outcome": "What the successful result was",
        "tags": ["relevant", "category", "tags"]
    }
]
```

If no new abilities were learned (straightforward execution, no obstacles overcome), respond with: []

Be selective. Only log genuinely useful, reusable problem-solving patterns. Quality over quantity.
PROMPT;
    }
}
