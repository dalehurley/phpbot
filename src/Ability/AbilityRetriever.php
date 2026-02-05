<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Ability;

use ClaudeAgents\Agent;
use ClaudePhp\ClaudePhp;
use Dalehurley\Phpbot\Storage\AbilityStore;

/**
 * Sub-agent that searches the abilities database for relevant knowledge.
 *
 * Before the main agent starts working on a task, or when it gets stuck,
 * this retriever searches the learned abilities for anything relevant.
 * Only matching ability details are returned to the main agent, keeping
 * the full abilities database out of the main context window.
 */
class AbilityRetriever
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
     * Search for abilities relevant to the given task.
     *
     * @return string Formatted guidance text from relevant abilities, or empty string
     */
    public function retrieve(string $taskDescription, array $analysis = []): string
    {
        $summaries = $this->store->summaries();

        if (empty($summaries)) {
            return '';
        }

        $relevantIds = $this->findRelevantAbilities($taskDescription, $analysis, $summaries);

        if (empty($relevantIds)) {
            return '';
        }

        $abilities = $this->store->getMany($relevantIds);

        if (empty($abilities)) {
            return '';
        }

        return $this->formatGuidance($abilities);
    }

    /**
     * Use a sub-agent to identify which abilities are relevant to the current task.
     */
    private function findRelevantAbilities(string $taskDescription, array $analysis, array $summaries): array
    {
        $summaryText = '';
        foreach ($summaries as $summary) {
            $tags = implode(', ', $summary['tags'] ?? []);
            $summaryText .= "- ID: {$summary['id']}\n";
            $summaryText .= "  Title: {$summary['title']}\n";
            $summaryText .= "  Description: {$summary['description']}\n";
            if ($tags !== '') {
                $summaryText .= "  Tags: {$tags}\n";
            }
            $summaryText .= "\n";
        }

        $analysisContext = '';
        if (!empty($analysis)) {
            $analysisContext = "\n## Task Analysis\n";
            $analysisContext .= "- Type: " . ($analysis['task_type'] ?? 'unknown') . "\n";
            $analysisContext .= "- Complexity: " . ($analysis['complexity'] ?? 'unknown') . "\n";
            if (!empty($analysis['potential_tools_needed'])) {
                $analysisContext .= "- Tools needed: " . implode(', ', $analysis['potential_tools_needed']) . "\n";
            }
        }

        $retrieverAgent = Agent::create($this->client)
            ->withName('ability_retriever')
            ->withSystemPrompt($this->getSystemPrompt())
            ->withModel($this->model)
            ->maxIterations(1)
            ->maxTokens(1024)
            ->temperature(0.1);

        $prompt = "## Current Task\n{$taskDescription}\n"
            . $analysisContext
            . "\n## Available Abilities\n{$summaryText}"
            . "\nRespond with ONLY a JSON array of relevant ability IDs, or [] if none are relevant.";

        try {
            $result = $retrieverAgent->run($prompt);
            $answer = trim((string) $result->getAnswer());

            return $this->parseIds($answer);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function parseIds(string $answer): array
    {
        // Extract JSON array from response
        $json = $answer;
        if (preg_match('/```(?:json)?\s*(\[[\s\S]*?\])\s*```/', $answer, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\[[\s\S]*\])/', $answer, $matches)) {
            $json = $matches[1];
        }

        $ids = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($ids)) {
            return [];
        }

        // Filter to only string IDs
        return array_values(array_filter($ids, 'is_string'));
    }

    private function formatGuidance(array $abilities): string
    {
        $guidance = "## Previously Learned Abilities (relevant to this task)\n\n";
        $guidance .= "The following strategies were learned from past experience and may help:\n\n";

        foreach ($abilities as $ability) {
            $guidance .= "### {$ability['title']}\n";

            if (!empty($ability['obstacle'])) {
                $guidance .= "**Obstacle encountered:** {$ability['obstacle']}\n";
            }
            if (!empty($ability['strategy'])) {
                $guidance .= "**Strategy that worked:** {$ability['strategy']}\n";
            }
            if (!empty($ability['outcome'])) {
                $guidance .= "**Outcome:** {$ability['outcome']}\n";
            }
            if (!empty($ability['description'])) {
                $guidance .= "{$ability['description']}\n";
            }

            $guidance .= "\n";
        }

        return $guidance;
    }

    private function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an ability retrieval agent. Your job is to match a current task against a library of previously learned abilities and return the IDs of any abilities that are relevant.

An ability is relevant if:
- The current task involves a similar type of problem
- The obstacle described in the ability might be encountered during this task
- The strategy in the ability could help with the current task
- The tags overlap with what the current task requires

Be selective: only return abilities that are genuinely likely to help. Don't return abilities just because they share a vague topic. The abilities will be injected into the main agent's context, so false positives waste context space.

Respond with ONLY a JSON array of ability ID strings. Example: ["ability_20250101_120000_abc123", "ability_20250102_130000_def456"]

If no abilities are relevant, respond with: []
PROMPT;
    }
}
