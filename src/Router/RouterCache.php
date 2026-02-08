<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Router;

use ClaudeAgents\Skills\SkillManager;
use Dalehurley\Phpbot\Registry\PersistentToolRegistry;

/**
 * Cached routing manifest with incremental updates.
 *
 * Stores a JSON manifest at storage/router_cache.json that maps user
 * intent patterns to instant answers, bash commands, and agent categories.
 * The manifest is generated once on first boot using Haiku, then updated
 * incrementally as new skills/tools are created.
 */
class RouterCache
{
    private array $manifest = [];
    private string $storagePath;

    public function __construct(string $storagePath)
    {
        $this->storagePath = rtrim($storagePath, '/') . '/router_cache.json';
    }

    /**
     * Load the manifest from disk.
     *
     * @return bool True if loaded successfully
     */
    public function load(): bool
    {
        if (!file_exists($this->storagePath)) {
            return false;
        }

        $json = file_get_contents($this->storagePath);
        if ($json === false) {
            return false;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return false;
        }

        $this->manifest = $data;

        return true;
    }

    /**
     * Save the manifest to disk.
     */
    public function save(): void
    {
        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->storagePath,
            json_encode($this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Check if the manifest exists and has been loaded.
     */
    public function isLoaded(): bool
    {
        return !empty($this->manifest);
    }

    /**
     * Get instant answers map.
     *
     * @return array<string, string> Pattern => PHP expression
     */
    public function getInstantAnswers(): array
    {
        return $this->manifest['instant_answers'] ?? [];
    }

    /**
     * Get bash commands map.
     *
     * @return array<string, string> Pattern => bash command
     */
    public function getBashCommands(): array
    {
        return $this->manifest['bash_commands'] ?? [];
    }

    /**
     * Get categories for Tier 2 matching.
     *
     * @return array<int, array{id: string, patterns: string[], tools: string[], skills: string[], agent_type: string, prompt_tier: string}>
     */
    public function getCategories(): array
    {
        return $this->manifest['categories'] ?? [];
    }

    /**
     * Get the tool index.
     *
     * @return array<string, string> Tool name => one-line description
     */
    public function getToolIndex(): array
    {
        return $this->manifest['tool_index'] ?? [];
    }

    /**
     * Get the skill index.
     *
     * @return array<string, string> Skill name => one-line description
     */
    public function getSkillIndex(): array
    {
        return $this->manifest['skill_index'] ?? [];
    }

    /**
     * Check if the cache is stale relative to current skills and tools.
     */
    public function isStale(SkillManager $skillManager, PersistentToolRegistry $toolRegistry): bool
    {
        if (!$this->isLoaded()) {
            return true;
        }

        $cachedSkills = array_keys($this->manifest['skill_index'] ?? []);
        $cachedTools = array_keys($this->manifest['tool_index'] ?? []);

        $currentSkills = array_keys($skillManager->summaries());
        $currentTools = $toolRegistry->names();

        // Check for new items not in cache
        $newSkills = array_diff($currentSkills, $cachedSkills);
        $newTools = array_diff($currentTools, $cachedTools);

        return !empty($newSkills) || !empty($newTools);
    }

    /**
     * Sync the cache with current skills and tools by appending new items.
     *
     * Does NOT regenerate the full manifest. Only adds missing entries.
     */
    public function sync(SkillManager $skillManager, PersistentToolRegistry $toolRegistry): void
    {
        $cachedSkills = array_keys($this->manifest['skill_index'] ?? []);
        $cachedTools = array_keys($this->manifest['tool_index'] ?? []);

        // Append new skills
        foreach ($skillManager->summaries() as $name => $summary) {
            if (!in_array($name, $cachedSkills, true)) {
                $this->appendSkill($name, $summary['description'] ?? '', []);
            }
        }

        // Append new tools
        foreach ($toolRegistry->all() as $tool) {
            if (!in_array($tool->getName(), $cachedTools, true)) {
                $this->appendTool($tool->getName(), $tool->getDescription());
            }
        }
    }

    /**
     * Append a new skill to the cache index.
     */
    public function appendSkill(string $name, string $description, array $tags = []): void
    {
        if (!isset($this->manifest['skill_index'])) {
            $this->manifest['skill_index'] = [];
        }

        $this->manifest['skill_index'][$name] = $description;

        // Try to assign to an existing category based on keywords
        $this->assignToCategory($name, $description, $tags, 'skill');

        $this->bumpVersion();
        $this->save();
    }

    /**
     * Append a new tool to the cache index.
     */
    public function appendTool(string $name, string $description): void
    {
        if (!isset($this->manifest['tool_index'])) {
            $this->manifest['tool_index'] = [];
        }

        $this->manifest['tool_index'][$name] = $description;

        $this->bumpVersion();
        $this->save();
    }

    /**
     * Append a new Tier 1 bash command shortcut.
     */
    public function appendBashCommand(string $pattern, string $command): void
    {
        if (!isset($this->manifest['bash_commands'])) {
            $this->manifest['bash_commands'] = [];
        }

        $this->manifest['bash_commands'][$pattern] = $command;

        $this->bumpVersion();
        $this->save();
    }

    /**
     * Generate the full manifest from scratch using the classifier client.
     *
     * This is called once on first boot when no cache file exists.
     */
    public function generate(
        ClassifierClient $classifier,
        SkillManager $skillManager,
        PersistentToolRegistry $toolRegistry,
    ): void {
        // Gather current state
        $skillSummaries = $skillManager->summaries();
        $toolNames = $toolRegistry->names();

        // Build tool index
        $toolIndex = [];
        foreach ($toolRegistry->all() as $tool) {
            $toolIndex[$tool->getName()] = $this->truncate($tool->getDescription(), 100);
        }

        // Build skill index
        $skillIndex = [];
        foreach ($skillSummaries as $name => $summary) {
            $skillIndex[$name] = $this->truncate($summary['description'] ?? '', 100);
        }

        // Generate categories via classifier client
        $categories = $this->generateCategories($classifier, $skillIndex, $toolIndex);

        $this->manifest = [
            'version' => 1,
            'generated_at' => date('c'),
            'instant_answers' => $this->getDefaultInstantAnswers(),
            'bash_commands' => $this->getDefaultBashCommands(),
            'categories' => $categories,
            'tool_index' => $toolIndex,
            'skill_index' => $skillIndex,
        ];

        $this->save();
    }

    /**
     * Get the full manifest array.
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Default instant answers that can be computed in PHP.
     *
     * @return array<string, string>
     */
    /**
     * Default instant answers.
     *
     * NOTE: These are stored in the cache manifest but actual Tier 0 matching
     * uses strict regex patterns defined in CachedRouter::INSTANT_ANSWER_PATTERNS.
     * These entries serve as documentation and for potential future use.
     *
     * @return array<string, string>
     */
    private function getDefaultInstantAnswers(): array
    {
        return [
            'what time|current time|what\'s the time|time now|time here' => 'time',
            'today\'s date|what day|what is the date|current date' => 'date',
            'hello|hi|hey|good morning|good afternoon|good evening' => 'greeting',
            'what can you do|your capabilities|help|what are you' => 'capabilities',
        ];
    }

    /**
     * Default bash commands for Tier 1 shortcuts.
     *
     * @return array<string, string>
     */
    private function getDefaultBashCommands(): array
    {
        return [
            'uptime|how long running|system uptime' => 'uptime',
            'whoami|who am i|current user|my username' => 'whoami',
            'hostname|computer name|machine name' => 'hostname',
            'disk space|disk usage|storage space|how much space' => 'df -h',
            'my ip|ip address|what is my ip|public ip' => 'curl -s ifconfig.me',
            'memory|ram usage|free memory' => 'vm_stat | head -5',
            'cpu|processor|cpu info' => 'sysctl -n machdep.cpu.brand_string',
            'pwd|current directory|where am i' => 'pwd',
            'os version|macos version|system version' => 'sw_vers',
        ];
    }

    /**
     * Use the classifier client to generate task categories from skills and tools.
     *
     * @param array<string, string> $skillIndex
     * @param array<string, string> $toolIndex
     * @return array<int, array<string, mixed>>
     */
    private function generateCategories(
        ClassifierClient $classifier,
        array $skillIndex,
        array $toolIndex,
    ): array {
        $skillList = '';
        foreach ($skillIndex as $name => $desc) {
            $skillList .= "- {$name}: {$desc}\n";
        }

        $toolList = '';
        foreach ($toolIndex as $name => $desc) {
            $toolList .= "- {$name}: {$desc}\n";
        }

        $prompt = <<<PROMPT
You are generating a routing manifest for an AI agent. Given these available skills and tools, create task categories that map user intent patterns to the minimum set of tools and skills needed.

## Available Skills
{$skillList}

## Available Tools
{$toolList}

## Instructions
Create 10-20 categories. Each category should have:
- id: short snake_case identifier
- patterns: array of 3-8 keyword patterns that would match this category (lowercase, pipe-separated alternatives within each pattern are OK)
- tools: array of tool names needed (ALWAYS include "bash" and "search_capabilities")
- skills: array of skill names relevant to this category (can be empty)
- agent_type: "react" for simple tasks, "plan_execute" for complex multi-step tasks
- prompt_tier: "minimal" for simple, "standard" for medium, "full" for complex

IMPORTANT: The "search_capabilities" tool should ALWAYS be in every category's tools list, as it allows the agent to discover additional skills/tools at runtime.

Respond with ONLY a JSON array of categories, no other text.
PROMPT;

        try {
            $answer = $classifier->classify($prompt, 4096);

            $categories = json_decode($answer, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($categories)) {
                return $categories;
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        // Fallback to default categories
        return $this->getDefaultCategories();
    }

    /**
     * Fallback categories if Haiku generation fails.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultCategories(): array
    {
        return [
            [
                'id' => 'file_operations',
                'patterns' => ['read file', 'write file', 'create file', 'edit file', 'save file', 'open file', 'delete file'],
                'tools' => ['bash', 'read_file', 'write_file', 'edit_file', 'search_capabilities'],
                'skills' => [],
                'agent_type' => 'react',
                'prompt_tier' => 'standard',
            ],
            [
                'id' => 'document_creation',
                'patterns' => ['pdf', 'docx', 'document', 'pptx', 'powerpoint', 'spreadsheet', 'xlsx', 'word document'],
                'tools' => ['bash', 'write_file', 'read_file', 'search_capabilities'],
                'skills' => ['pdf', 'docx', 'pptx', 'xlsx'],
                'agent_type' => 'plan_execute',
                'prompt_tier' => 'full',
            ],
            [
                'id' => 'communication',
                'patterns' => ['send email', 'send sms', 'send message', 'email', 'text message', 'slack', 'notify'],
                'tools' => ['bash', 'get_keys', 'store_keys', 'search_computer', 'search_capabilities'],
                'skills' => ['send-email', 'send-sms', 'internal-comms'],
                'agent_type' => 'react',
                'prompt_tier' => 'standard',
            ],
            [
                'id' => 'media_image',
                'patterns' => ['image', 'photo', 'picture', 'screenshot', 'qr code', 'design', 'canvas', 'art', 'logo'],
                'tools' => ['bash', 'write_file', 'search_capabilities'],
                'skills' => ['image-tools', 'screenshot', 'qr-code', 'canvas-design', 'algorithmic-art'],
                'agent_type' => 'plan_execute',
                'prompt_tier' => 'standard',
            ],
            [
                'id' => 'web_scraping',
                'patterns' => ['scrape', 'web scraper', 'fetch url', 'download page', 'crawl', 'extract from website'],
                'tools' => ['bash', 'write_file', 'search_capabilities'],
                'skills' => ['web-scraper'],
                'agent_type' => 'react',
                'prompt_tier' => 'standard',
            ],
            [
                'id' => 'audio_speech',
                'patterns' => ['say', 'speak', 'text to speech', 'read aloud', 'audio', 'voice', 'pronounce', 'tts'],
                'tools' => ['bash', 'search_capabilities'],
                'skills' => ['text-to-speech', 'summarize-pdf-aloud'],
                'agent_type' => 'react',
                'prompt_tier' => 'minimal',
            ],
            [
                'id' => 'system_control',
                'patterns' => ['open app', 'launch', 'clipboard', 'copy', 'paste', 'install', 'brew', 'homebrew', 'desktop'],
                'tools' => ['bash', 'brew', 'search_capabilities'],
                'skills' => ['open-application', 'clipboard', 'homebrew', 'desktop-control'],
                'agent_type' => 'react',
                'prompt_tier' => 'minimal',
            ],
            [
                'id' => 'data_processing',
                'patterns' => ['csv', 'json', 'data', 'parse', 'transform', 'convert', 'analyze data', 'compress', 'zip', 'unzip'],
                'tools' => ['bash', 'read_file', 'write_file', 'search_capabilities'],
                'skills' => ['csv-tools', 'file-compress'],
                'agent_type' => 'react',
                'prompt_tier' => 'standard',
            ],
            [
                'id' => 'coding_development',
                'patterns' => ['code', 'script', 'program', 'function', 'debug', 'refactor', 'build tool', 'create tool', 'mcp'],
                'tools' => ['bash', 'read_file', 'write_file', 'edit_file', 'tool_builder', 'search_capabilities'],
                'skills' => ['mcp-builder'],
                'agent_type' => 'plan_execute',
                'prompt_tier' => 'full',
            ],
            [
                'id' => 'translation',
                'patterns' => ['translate', 'translation', 'language', 'french', 'spanish', 'german', 'chinese', 'japanese'],
                'tools' => ['bash', 'search_capabilities'],
                'skills' => ['translate'],
                'agent_type' => 'react',
                'prompt_tier' => 'minimal',
            ],
            [
                'id' => 'video_youtube',
                'patterns' => ['youtube', 'video', 'download video', 'youtube-dl', 'yt-dlp'],
                'tools' => ['bash', 'search_capabilities'],
                'skills' => ['youtube-dl'],
                'agent_type' => 'react',
                'prompt_tier' => 'standard',
            ],
            [
                'id' => 'general_knowledge',
                'patterns' => ['explain', 'what is', 'how does', 'tell me about', 'describe', 'define'],
                'tools' => ['bash', 'search_capabilities'],
                'skills' => [],
                'agent_type' => 'react',
                'prompt_tier' => 'minimal',
            ],
        ];
    }

    /**
     * Try to assign a new skill to an existing category based on keyword overlap.
     */
    private function assignToCategory(string $name, string $description, array $tags, string $type): void
    {
        if (!isset($this->manifest['categories']) || !is_array($this->manifest['categories'])) {
            return;
        }

        $searchTerms = array_merge(
            explode('-', $name),
            explode(' ', strtolower($description)),
            array_map('strtolower', $tags)
        );
        $searchTerms = array_filter(array_unique($searchTerms), fn(string $t) => strlen($t) > 2);

        foreach ($this->manifest['categories'] as &$category) {
            $patterns = implode(' ', $category['patterns'] ?? []);
            $categoryId = $category['id'] ?? '';

            $matchCount = 0;
            foreach ($searchTerms as $term) {
                if (stripos($patterns, $term) !== false || stripos($categoryId, $term) !== false) {
                    $matchCount++;
                }
            }

            // If at least 2 terms match, assign to this category
            if ($matchCount >= 2 && $type === 'skill') {
                if (!in_array($name, $category['skills'] ?? [], true)) {
                    $category['skills'][] = $name;
                }

                return;
            }
        }
        unset($category);
    }

    /**
     * Increment the version number.
     */
    private function bumpVersion(): void
    {
        $this->manifest['version'] = ($this->manifest['version'] ?? 0) + 1;
    }

    /**
     * Truncate a string to a maximum length.
     */
    private function truncate(string $text, int $maxLen): string
    {
        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }

        return mb_substr($text, 0, $maxLen - 3) . '...';
    }
}
