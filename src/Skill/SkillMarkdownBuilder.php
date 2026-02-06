<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Skill;

use Dalehurley\Phpbot\CredentialPatterns;

/**
 * Assembles the SKILL.md file content from the generalised skill data,
 * bundled scripts, and supporting metadata.
 */
class SkillMarkdownBuilder
{
    /**
     * Build the full SKILL.md markdown content.
     */
    public static function build(
        string $slug,
        array $generalized,
        string $originalInput,
        string $answer,
        array $bundledScripts = [],
        string $sanitizedRecipe = '',
        array $credentialReport = []
    ): string {
        $safeAnswer = self::truncateAnswer($answer);
        $safeAnswer = CredentialPatterns::strip($safeAnswer);

        $description = str_replace('"', '\\"', $generalized['description']);
        $tags        = $generalized['tags'] ?? ['auto-generated'];
        $tagsYaml    = '[' . implode(', ', $tags) . ']';

        $whenToUse       = $generalized['when_to_use'];
        $procedure        = $generalized['procedure'];
        $requiredCreds    = $generalized['required_credentials'] ?? [];
        $inputParams      = $generalized['input_parameters'] ?? [];

        $sanitizedExample = SkillTextUtils::sanitizeInput(trim($originalInput));
        $sanitizedExample = CredentialPatterns::strip($sanitizedExample);

        // --- Assemble ---

        $md = self::buildFrontmatter($slug, $description, $tagsYaml);
        $md .= "\n\n# Skill: {$slug}\n\n";
        $md .= "## When to Use\n{$whenToUse}\n\n";
        $md .= self::buildCredentialsSection($requiredCreds);
        $md .= self::buildInputParametersSection($inputParams);
        $md .= "## Procedure\n{$procedure}\n";
        $md .= self::buildScriptsSection($bundledScripts);
        $md .= self::buildReferenceCommandsSection($sanitizedRecipe, $bundledScripts);
        $md .= "\n## Example\n\n";
        $md .= "Example requests that trigger this skill:\n\n";
        $md .= "```\n{$sanitizedExample}\n```\n";

        return $md;
    }

    // =========================================================================
    // Section builders
    // =========================================================================

    private static function buildFrontmatter(string $slug, string $description, string $tagsYaml): string
    {
        return <<<YAML
---
name: {$slug}
description: "{$description}"
tags: {$tagsYaml}
version: 0.1.0
---
YAML;
    }

    private static function buildCredentialsSection(array $requiredCreds): string
    {
        if (empty($requiredCreds)) {
            return '';
        }

        $md = "## Required Credentials\n\n";
        $md .= "Retrieve these via the `get_keys` tool before executing:\n\n";
        $md .= "| Key Store Key | Environment Variable | Description |\n";
        $md .= "|---------------|---------------------|-------------|\n";

        foreach ($requiredCreds as $cred) {
            $key  = $cred['key_store_key'] ?? '';
            $env  = $cred['env_var'] ?? strtoupper($key);
            $desc = $cred['description'] ?? '';
            $md  .= "| `{$key}` | `{$env}` | {$desc} |\n";
        }

        return $md . "\n";
    }

    private static function buildInputParametersSection(array $inputParams): string
    {
        if (empty($inputParams)) {
            return '';
        }

        $md = "## Input Parameters\n\n";
        $md .= "| Parameter | Required | Description | Example |\n";
        $md .= "|-----------|----------|-------------|---------|\n";

        foreach ($inputParams as $param) {
            $name     = $param['name'] ?? '';
            $required = !empty($param['required']) ? 'Yes' : 'No';
            $desc     = $param['description'] ?? '';
            $example  = CredentialPatterns::strip($param['example'] ?? '');
            $md      .= "| `{$name}` | {$required} | {$desc} | {$example} |\n";
        }

        return $md . "\n";
    }

    private static function buildScriptsSection(array $bundledScripts): string
    {
        if (empty($bundledScripts)) {
            return '';
        }

        $md = "\n## Bundled Scripts\n\n";
        $md .= "| Script | Type | Description |\n";
        $md .= "|--------|------|-------------|\n";

        foreach ($bundledScripts as $script) {
            $ext  = strtoupper($script['extension']);
            $desc = $script['description'] ?? 'Auto-captured from task execution';
            $md  .= "| `{$script['path']}` | {$ext} | {$desc} |\n";
        }
        $md .= "\n";

        // Show usage for auto-generated scripts
        $autoScripts = array_filter($bundledScripts, fn($s) => ($s['source'] ?? '') === 'auto_generated');
        if (!empty($autoScripts)) {
            $md .= "### Script Usage\n\n";
            foreach ($autoScripts as $script) {
                $params = $script['parameters'] ?? [];
                if (!empty($params)) {
                    $argNames = array_map(fn($p) => '<' . strtolower($p['name']) . '>', $params);
                    $md .= "```bash\n";
                    $md .= "# Set credentials as environment variables (from get_keys), then:\n";
                    $md .= "bash {$script['path']} " . implode(' ', $argNames) . "\n";
                    $md .= "```\n\n";
                } else {
                    $md .= "```bash\nbash {$script['path']}\n```\n\n";
                }
            }
        }

        $md .= "Credentials in scripts use environment variables. Set them via `get_keys` before running.\n";

        return $md;
    }

    private static function buildReferenceCommandsSection(string $sanitizedRecipe, array $bundledScripts): string
    {
        $hasAutoScripts = !empty(array_filter(
            $bundledScripts,
            fn($s) => ($s['source'] ?? '') === 'auto_generated'
        ));

        if ($sanitizedRecipe === '' || $hasAutoScripts) {
            return '';
        }

        $md = "\n## Reference Commands\n\n";
        $md .= "Commands from a successful execution (adapt to actual inputs):\n\n";
        $md .= "```bash\n{$sanitizedRecipe}\n```\n\n";
        $md .= "Replace `{{PLACEHOLDER}}` values with actual credentials from the key store.\n";

        return $md;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private static function truncateAnswer(string $answer): string
    {
        $safe = trim($answer);
        if (strlen($safe) > 800) {
            $safe = substr($safe, 0, 800) . "\n...";
        }
        return $safe;
    }
}
