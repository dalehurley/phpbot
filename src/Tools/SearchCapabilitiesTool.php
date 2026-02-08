<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Skills\SkillManager;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Registry\PersistentToolRegistry;

/**
 * On-demand capability discovery tool.
 *
 * Allows the agent to search for available skills and tools at any point
 * during execution without having all definitions loaded upfront.
 * Returns compact search results (~50-100 tokens per match) rather
 * than full schemas, drastically reducing context usage.
 */
class SearchCapabilitiesTool implements ToolInterface
{
    use ToolDefinitionTrait;

    public function __construct(
        private ?SkillManager $skillManager = null,
        private ?PersistentToolRegistry $toolRegistry = null,
    ) {}

    public function getName(): string
    {
        return 'search_capabilities';
    }

    public function getDescription(): string
    {
        return 'Search available skills and tools by keyword. Returns compact matches. '
             . 'Use before saying you cannot do something. Optionally load full skill '
             . 'instructions or tool details for a specific item.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search keywords (e.g., "pdf", "email", "image", "clipboard")',
                ],
                'load_skill' => [
                    'type' => 'string',
                    'description' => 'Exact skill name to load full instructions for (e.g., "pdf", "send-email")',
                ],
                'load_tool_info' => [
                    'type' => 'string',
                    'description' => 'Exact tool name to load full schema/description for (e.g., "tool_builder")',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $query = trim((string) ($input['query'] ?? ''));
        $loadSkill = trim((string) ($input['load_skill'] ?? ''));
        $loadToolInfo = trim((string) ($input['load_tool_info'] ?? ''));

        // Load full skill instructions
        if ($loadSkill !== '') {
            return $this->loadSkillInstructions($loadSkill);
        }

        // Load full tool info
        if ($loadToolInfo !== '') {
            return $this->loadToolInfo($loadToolInfo);
        }

        if ($query === '') {
            return ToolResult::error('Please provide a search query.');
        }

        return $this->search($query);
    }

    /**
     * Search skills and tools by keyword.
     */
    private function search(string $query): ToolResultInterface
    {
        $result = '';

        // Search skills
        $skillMatches = [];
        if ($this->skillManager !== null) {
            try {
                $matches = $this->skillManager->search($query);
                foreach (array_slice($matches, 0, 8) as $skill) {
                    $skillMatches[] = $skill;
                }
            } catch (\Throwable) {
                // Ignore errors
            }
        }

        if (!empty($skillMatches)) {
            $result .= "## Matching Skills\n";
            foreach ($skillMatches as $skill) {
                $result .= "- **{$skill->getName()}**: {$skill->getDescription()}\n";
                $scripts = $skill->getScripts();
                if (!empty($scripts)) {
                    $result .= "  Scripts: " . implode(', ', $scripts) . "\n";
                }
            }
            $result .= "\n";
        }

        // Search tools
        $toolMatches = [];
        if ($this->toolRegistry !== null) {
            $queryLower = strtolower($query);
            $queryWords = array_filter(explode(' ', $queryLower), fn(string $w) => strlen($w) > 2);

            foreach ($this->toolRegistry->all() as $tool) {
                $name = strtolower($tool->getName());
                $desc = strtolower($tool->getDescription());

                $matched = false;
                if (stripos($name, $queryLower) !== false || stripos($desc, $queryLower) !== false) {
                    $matched = true;
                } else {
                    foreach ($queryWords as $word) {
                        if (str_contains($name, $word) || str_contains($desc, $word)) {
                            $matched = true;

                            break;
                        }
                    }
                }

                if ($matched) {
                    $toolMatches[] = $tool;
                }
            }
        }

        if (!empty($toolMatches)) {
            $result .= "## Matching Tools\n";
            foreach (array_slice($toolMatches, 0, 8) as $tool) {
                $shortDesc = mb_strlen($tool->getDescription()) > 120
                    ? mb_substr($tool->getDescription(), 0, 117) . '...'
                    : $tool->getDescription();
                $result .= "- **{$tool->getName()}**: {$shortDesc}\n";
            }
            $result .= "\n";
        }

        if ($result === '') {
            $result = "No skills or tools found matching \"{$query}\".\n";
            $result .= "Try broader keywords or check available categories.\n";
        }

        $result .= "To load full instructions, call again with load_skill or load_tool_info parameter.";

        return ToolResult::success($result);
    }

    /**
     * Load full instructions for a specific skill.
     */
    private function loadSkillInstructions(string $name): ToolResultInterface
    {
        if ($this->skillManager === null) {
            return ToolResult::error('Skill manager not available.');
        }

        try {
            $skill = $this->skillManager->get($name);
            $instructions = $skill->getInstructions();

            $result = "## Skill: {$skill->getName()}\n";
            $result .= "**Description:** {$skill->getDescription()}\n\n";
            $result .= $instructions;

            $scripts = $skill->getScripts();
            if (!empty($scripts)) {
                $result .= "\n\n**Available Scripts:**\n";
                foreach ($scripts as $script) {
                    $result .= "- {$script}\n";
                }
            }

            return ToolResult::success($result);
        } catch (\Throwable $e) {
            return ToolResult::error("Skill '{$name}' not found: {$e->getMessage()}");
        }
    }

    /**
     * Load full info for a specific tool.
     */
    private function loadToolInfo(string $name): ToolResultInterface
    {
        if ($this->toolRegistry === null) {
            return ToolResult::error('Tool registry not available.');
        }

        $tool = $this->toolRegistry->get($name);
        if ($tool === null) {
            return ToolResult::error("Tool '{$name}' not found.");
        }

        $schema = $tool->getInputSchema();

        $result = "## Tool: {$tool->getName()}\n";
        $result .= "**Description:** {$tool->getDescription()}\n\n";
        $result .= "**Input Schema:**\n```json\n" . json_encode($schema, JSON_PRETTY_PRINT) . "\n```\n";

        return ToolResult::success($result);
    }
}
