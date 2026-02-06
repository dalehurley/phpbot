<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use ClaudeAgents\Agent;
use ClaudeAgents\Skills\SkillManager;

class SkillAutoCreator
{
    /**
     * @param \Closure(): \ClaudePhp\ClaudePhp $clientFactory
     */
    public function __construct(
        private \Closure $clientFactory,
        private array $config,
        private ?SkillManager $skillManager = null
    ) {}

    public function autoCreate(
        string $input,
        array $analysis,
        $result,
        array $resolvedSkills,
        callable $progress
    ): void {
        if (!($result && $result->isSuccess())) {
            return;
        }

        if ($this->skillManager === null) {
            return;
        }

        if (!empty($resolvedSkills)) {
            return;
        }

        if (!$this->shouldCreateSkill($analysis)) {
            return;
        }

        $skillsPath = $this->config['skills_path'] ?? '';
        if (!is_string($skillsPath) || $skillsPath === '' || !is_dir($skillsPath)) {
            return;
        }

        $toolCalls = $result->getToolCalls();
        $scripts = $this->extractScriptsFromToolCalls($toolCalls);
        $recipe = $this->extractToolRecipe($toolCalls);

        $generalized = $this->generalizeSkillWithLLM($input, $analysis, $result->getAnswer() ?? '', $scripts, $recipe);

        $slug = $this->slugifySkillName($generalized['name']);
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

        $bundledScripts = $this->bundleSkillScripts($dir, $scripts);

        $skillMd = $this->buildSkillMarkdown($slug, $generalized, $input, $result->getAnswer() ?? '', $bundledScripts, $recipe);
        file_put_contents($dir . '/SKILL.md', $skillMd);

        $scriptCount = count($bundledScripts);
        $scriptNote = $scriptCount > 0 ? " with {$scriptCount} script(s)" : '';
        $progress('skills', "Created skill: {$slug}{$scriptNote}");
    }

    private function shouldCreateSkill(array $analysis): bool
    {
        $complexity = $analysis['complexity'] ?? 'medium';
        if ($complexity === 'simple') {
            return false;
        }

        $steps = (int) ($analysis['estimated_steps'] ?? 1);
        if ($steps < 3) {
            return false;
        }

        return true;
    }

    private function extractScriptsFromToolCalls(array $toolCalls): array
    {
        $scriptExtensions = ['py', 'sh', 'bash', 'js', 'ts', 'php', 'rb', 'pl'];
        $scripts = [];

        foreach ($toolCalls as $call) {
            if (!empty($call['is_error'])) {
                continue;
            }

            if ($call['tool'] === 'write_file') {
                $input = $call['input'] ?? [];
                if (is_string($input)) {
                    $input = json_decode($input, true) ?? [];
                }
                $path = (string) ($input['path'] ?? '');
                $content = (string) ($input['content'] ?? '');

                if ($path === '' || $content === '') {
                    continue;
                }

                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, $scriptExtensions, true)) {
                    $scripts[] = [
                        'original_path' => $path,
                        'filename' => basename($path),
                        'content' => $content,
                        'extension' => $ext,
                        'source' => 'write_file',
                    ];
                }
            }

            if ($call['tool'] === 'bash') {
                $input = $call['input'] ?? [];
                if (is_string($input)) {
                    $input = json_decode($input, true) ?? [];
                }
                $command = (string) ($input['command'] ?? '');

                if (preg_match('/(?:cat|tee)\s+>\s*([^\s<]+\.(?:' . implode('|', $scriptExtensions) . '))\s*<<\s*[\'"]?(\w+)[\'"]?\n(.*?)\n\2/s', $command, $m)) {
                    $scripts[] = [
                        'original_path' => $m[1],
                        'filename' => basename($m[1]),
                        'content' => $m[3],
                        'extension' => strtolower(pathinfo($m[1], PATHINFO_EXTENSION)),
                        'source' => 'bash_heredoc',
                    ];
                }
            }
        }

        $unique = [];
        foreach ($scripts as $script) {
            $unique[$script['filename']] = $script;
        }

        return array_values($unique);
    }

    private function bundleSkillScripts(string $skillDir, array $scripts): array
    {
        if (empty($scripts)) {
            return [];
        }

        $scriptsDir = $skillDir . '/scripts';
        if (!is_dir($scriptsDir) && !mkdir($scriptsDir, 0755, true)) {
            return [];
        }

        $bundled = [];
        foreach ($scripts as $script) {
            $targetPath = $scriptsDir . '/' . $script['filename'];
            $content = $this->sanitizeScriptContent($script['content']);

            if (file_put_contents($targetPath, $content) !== false) {
                if (in_array($script['extension'], ['sh', 'bash'], true)) {
                    chmod($targetPath, 0755);
                }
                $bundled[] = [
                    'filename' => $script['filename'],
                    'path' => 'scripts/' . $script['filename'],
                    'extension' => $script['extension'],
                    'original_path' => $script['original_path'],
                ];
            }
        }

        return $bundled;
    }

    private function sanitizeScriptContent(string $content): string
    {
        $content = preg_replace('#/Users/[^/\s"\']+#', '$HOME', $content);
        $content = preg_replace('#/home/[^/\s"\']+#', '$HOME', $content);

        return $content;
    }

    private function generalizeSkillWithLLM(string $input, array $analysis, string $answer, array $scripts = [], string $recipe = ''): array
    {
        $sanitized = $this->sanitizeInput($input);
        $taskType = $analysis['task_type'] ?? 'general';

        $fallback = [
            'name' => $sanitized,
            'description' => "Repeatable workflow to {$sanitized}. Use when asked to perform similar tasks.",
            'when_to_use' => "Use this skill when asked to: {$sanitized}",
            'procedure' => implode("\n", [
                '1. Identify the required input files or data from the user.',
                '2. Follow the established workflow for this task type.',
                '3. Generate the output in the appropriate format.',
                '4. Validate the results and present to the user.',
            ]),
        ];

        try {
            $client = ($this->clientFactory)();

            $agent = Agent::create($client)
                ->withName('skill_generalizer')
                ->withSystemPrompt($this->getGeneralizerPrompt())
                ->withModel($this->getFastModel())
                ->maxIterations(1)
                ->maxTokens(1024)
                ->temperature(0.3);

            $safeAnswer = strlen($answer) > 1500 ? substr($answer, 0, 1500) . '...' : $answer;
            $scriptNames = array_map(fn($s) => $s['filename'], $scripts);
            $payload = json_encode([
                'user_request' => $input,
                'sanitized_request' => $sanitized,
                'task_type' => $taskType,
                'result_summary' => $safeAnswer,
                'bundled_scripts' => $scriptNames,
                'tool_recipe' => $recipe,
            ]);

            $result = $agent->run("Generalize this completed task into a reusable skill. Respond with JSON only:\n\n{$payload}");
            $data = json_decode($result->getAnswer(), true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $fallback;
            }

            return [
                'name' => !empty($data['name']) ? (string) $data['name'] : $sanitized,
                'description' => !empty($data['description']) ? (string) $data['description'] : $fallback['description'],
                'when_to_use' => !empty($data['when_to_use']) ? (string) $data['when_to_use'] : $fallback['when_to_use'],
                'procedure' => !empty($data['procedure']) ? (string) $data['procedure'] : $fallback['procedure'],
            ];
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function getGeneralizerPrompt(): string
    {
        return <<<'PROMPT'
You generalize completed tasks into reusable, generic skills. Given a specific task that was completed successfully, extract a CONCRETE, ACTIONABLE workflow that an LLM agent can follow efficiently on similar future requests.

CRITICAL RULES:
- Remove ALL specific file paths, usernames, directory names, URLs, and personal identifiers.
- Replace specific references with placeholders: $INPUT_FILE, $OUTPUT_DIR, $HOME, etc.
- The name should be short (2-4 words), kebab-case, action-oriented, and generic.
- The description must explain WHAT the skill does and WHEN to use it, with trigger keywords.
- The procedure MUST include SPECIFIC commands from the tool_recipe when provided.
- The when_to_use should describe trigger contexts and example phrases.
- If bundled_scripts are provided, reference them by filename in the procedure.

BAD example name: "analyze-users-dalehurley-financial-report"
GOOD example name: "financial-analysis"

BAD procedure (too vague, wastes iterations on exploration):
  1. Identify the source document
  2. Extract content
  3. Analyze the data
  4. Generate output

GOOD procedure (concrete commands, the agent can execute directly):
  1. Extract text from the source PDF preserving layout: `pdftotext -layout "$INPUT_FILE" "$OUTPUT_DIR/extracted.txt"`
  2. Find key sections in the extracted text: `grep -n "Statement of\|Balance Sheet\|Income" "$OUTPUT_DIR/extracted.txt"`
  3. Parse financial data from the relevant sections using sed/awk to extract numbers and labels.
  4. Calculate key metrics: revenue growth, profit margins, liquidity ratios, etc.
  5. Write the analysis report using write_file tool to $OUTPUT_DIR/Analysis.md
  6. Write an executive summary to $OUTPUT_DIR/Executive_Summary.txt
  7. Verify all output files: `ls -la $OUTPUT_DIR/*.md $OUTPUT_DIR/*.txt`

If a tool_recipe is provided, use it to create SPECIFIC procedure steps.
The recipe shows the exact commands that worked — generalize them with placeholder paths.
The goal is an agent can follow the procedure in 5-10 tool calls, not 30+.

Respond with ONLY a JSON object:
{
    "name": "short-kebab-case-name",
    "description": "What this skill does and when to trigger it. Include trigger keywords.",
    "when_to_use": "Use this skill when the user asks to...\n\nExample triggers:\n- trigger phrase 1\n- trigger phrase 2\n- trigger phrase 3",
    "procedure": "1. Concrete step with `command`\n2. Next step with `command`\n3. ..."
}
PROMPT;
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
            $input = preg_replace('/-[^-]*$/', '', $input) ?: $input;
        }

        return $input;
    }

    private function buildSkillMarkdown(string $slug, array $generalized, string $originalInput, string $answer, array $bundledScripts = [], string $recipe = ''): string
    {
        $safeAnswer = trim($answer);
        if (strlen($safeAnswer) > 800) {
            $safeAnswer = substr($safeAnswer, 0, 800) . "\n...";
        }

        $description = str_replace('"', '\\"', $generalized['description']);
        $whenToUse = $generalized['when_to_use'];
        $procedure = $generalized['procedure'];
        $sanitizedExample = $this->sanitizeInput(trim($originalInput));

        $frontmatter = <<<YAML
---
name: {$slug}
description: "{$description}"
tags: [auto-generated]
version: 0.1.0
---
YAML;

        $md = <<<MD
# Skill: {$slug}

## When to Use
{$whenToUse}

## Procedure
{$procedure}
MD;

        if (!empty($bundledScripts)) {
            $md .= "\n\n## Bundled Scripts\n\n";
            $md .= "The following scripts are bundled with this skill in the `scripts/` directory:\n\n";
            $md .= "| Script | Type | Description |\n";
            $md .= "|--------|------|-------------|\n";
            foreach ($bundledScripts as $script) {
                $ext = strtoupper($script['extension']);
                $md .= "| `{$script['path']}` | {$ext} | Auto-captured from task execution |\n";
            }
            $md .= "\nRun scripts from the skill directory. Paths inside scripts use `\$HOME` instead of hardcoded user directories.\n";
        }

        if ($recipe !== '') {
            $md .= "\n\n## Reference Commands\n\n";
            $md .= "Commands from a successful execution (adapt paths to actual inputs):\n\n";
            $md .= "```bash\n{$recipe}\n```\n";
            $md .= "\nAdapt file paths and names to match the actual input provided by the user.\n";
        }

        $md .= <<<MD


## Example
An example of a request that uses this skill:

```
{$sanitizedExample}
```

## Last Result (truncated)
```
{$safeAnswer}
```
MD;

        return $frontmatter . "\n\n" . $md;
    }

    private function sanitizeInput(string $input): string
    {
        $fileTypeMap = [
            'pdf' => 'a PDF file',
            'docx' => 'a Word document',
            'doc' => 'a Word document',
            'xlsx' => 'a spreadsheet',
            'xls' => 'a spreadsheet',
            'csv' => 'a CSV file',
            'pptx' => 'a presentation',
            'ppt' => 'a presentation',
            'txt' => 'a text file',
            'json' => 'a JSON file',
            'xml' => 'an XML file',
            'html' => 'an HTML file',
            'htm' => 'an HTML file',
            'md' => 'a Markdown file',
            'png' => 'an image',
            'jpg' => 'an image',
            'jpeg' => 'an image',
            'gif' => 'an image',
            'svg' => 'an image',
            'mp4' => 'a video file',
            'mp3' => 'an audio file',
            'wav' => 'an audio file',
            'zip' => 'an archive',
            'tar' => 'an archive',
        ];

        $sanitized = preg_replace_callback(
            '#(?:/(?:Users|home|var|tmp|opt|etc|mnt|srv|Volumes)/[^\s,;)}\]]*|~/[^\s,;)}\]]*|[A-Z]:\\\\[^\s,;)}\]]*)#i',
            function ($match) use ($fileTypeMap) {
                $path = rtrim($match[0], '.');
                if (preg_match('/\.(\w+)$/', $path, $extMatch)) {
                    $ext = strtolower($extMatch[1]);
                    return $fileTypeMap[$ext] ?? 'a file';
                }
                return 'a file on the user\'s computer';
            },
            $input
        );

        $sanitized = preg_replace('/\s+/', ' ', trim($sanitized));

        return $sanitized;
    }

    private function getFastModel(): string
    {
        $fast = $this->config['fast_model'] ?? '';
        if (is_string($fast) && $fast !== '') {
            return $fast;
        }

        return $this->config['model'];
    }

    /**
     * Extract a concise recipe of successful tool calls that shows
     * the actual commands used. This gets embedded in the skill so
     * future runs can follow concrete steps instead of exploring.
     */
    private function extractToolRecipe(array $toolCalls): string
    {
        $commands = [];

        foreach ($toolCalls as $call) {
            // Skip errored calls — we only want the successful pattern
            if (!empty($call['is_error'])) {
                continue;
            }

            $tool = $call['tool'] ?? '';
            $input = $call['input'] ?? [];
            if (is_string($input)) {
                $input = json_decode($input, true) ?? [];
            }

            if ($tool === 'bash') {
                $command = trim((string) ($input['command'] ?? ''));
                if ($command === '') {
                    continue;
                }
                $sanitized = $this->sanitizeRecipeCommand($command);
                if ($sanitized !== '' && !in_array($sanitized, $commands, true)) {
                    $commands[] = $sanitized;
                }
            } elseif ($tool === 'write_file') {
                $path = $this->sanitizeRecipePath((string) ($input['path'] ?? ''));
                if ($path !== '') {
                    $cmd = "# write_file: {$path}";
                    if (!in_array($cmd, $commands, true)) {
                        $commands[] = $cmd;
                    }
                }
            }
        }

        // Keep at most 20 commands to avoid bloating the skill
        if (count($commands) > 20) {
            $commands = array_slice($commands, 0, 20);
            $commands[] = '# ... additional commands omitted';
        }

        return implode("\n", $commands);
    }

    /**
     * Sanitize a bash command for inclusion in a skill recipe.
     * Replaces user-specific paths with placeholders and truncates heredocs.
     */
    private function sanitizeRecipeCommand(string $command): string
    {
        // Replace user-specific paths with placeholders
        $command = preg_replace('#/Users/[^/\s"\']+(/[^\s"\']*)?#', '$HOME$1', $command);
        $command = preg_replace('#/home/[^/\s"\']+(/[^\s"\']*)?#', '$HOME$1', $command);

        // Truncate very long commands (heredocs, large echoes)
        if (strlen($command) > 300) {
            // For file creation via heredoc, just note the target file
            if (preg_match('/(?:cat|tee)\s*>+\s*([^\s<]+)/', $command, $m)) {
                return '# Create file via heredoc: ' . $this->sanitizeRecipePath($m[1]);
            }
            return substr($command, 0, 250) . ' # ... (truncated)';
        }

        return $command;
    }

    /**
     * Sanitize a file path for the recipe, replacing user directories.
     */
    private function sanitizeRecipePath(string $path): string
    {
        $path = preg_replace('#/Users/[^/\s"\']+#', '$HOME', $path);
        $path = preg_replace('#/home/[^/\s"\']+#', '$HOME', $path);
        return $path;
    }
}
