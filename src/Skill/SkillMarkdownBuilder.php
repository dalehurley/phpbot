<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Skill;

use Dalehurley\Phpbot\CredentialPatterns;

/**
 * Assembles the SKILL.md file content from the LLM-generalised skill data
 * and bundled script metadata.
 *
 * Supports multiple skill archetypes:
 *  - Script-driven utilities  (clipboard, csv-tools, send-email)
 *  - Command-driven skills    (open-application, text-to-speech)
 *  - Reference/knowledge guides (pdf, brand-guidelines, internal-comms)
 *  - Creative workflow skills  (algorithmic-art, canvas-design)
 *  - Toolkit skills           (web-artifacts-builder, word-documents)
 *
 * All creative content (description, procedure, example, etc.) is produced
 * by the LLM in SkillGeneralizer. The builder formats it as markdown with
 * the right sections for the skill's archetype.
 */
class SkillMarkdownBuilder
{
    /**
     * Build the full SKILL.md markdown content.
     *
     * @param string $slug           Filesystem-safe skill name
     * @param array  $generalized    LLM-generated skill definition
     * @param array  $bundledScripts Script metadata for the Bundled Scripts section
     */
    public static function build(
        string $slug,
        array $generalized,
        array $bundledScripts = [],
    ): string {
        $description = str_replace('"', '\\"', $generalized['description']);
        $tags        = $generalized['tags'] ?? ['auto-generated'];
        $tagsYaml    = self::formatTagsYaml($tags);
        $license     = trim($generalized['license'] ?? '');
        $version     = trim($generalized['version'] ?? '0.1.0');

        $overview          = trim($generalized['overview'] ?? '');
        $whenToUse         = $generalized['when_to_use'];
        $procedure         = $generalized['procedure'];
        $requiredCreds     = $generalized['required_credentials'] ?? [];
        $setupNotes        = $generalized['setup_notes'] ?? [];
        $inputParams       = $generalized['input_parameters'] ?? [];
        $outputFormat      = trim($generalized['output_format'] ?? '');
        $exampleRequest    = CredentialPatterns::strip(trim($generalized['example_request'] ?? ''));
        $referenceCommands = CredentialPatterns::strip(trim($generalized['reference_commands'] ?? ''));
        $keywords          = $generalized['keywords'] ?? [];
        $notes             = $generalized['notes'] ?? [];
        $bundledResources  = $generalized['bundled_resources'] ?? [];

        // --- Assemble sections in order ---

        $md = self::buildFrontmatter($slug, $description, $tagsYaml, $license, $version);
        $md .= "\n\n# Skill: {$slug}\n\n";
        $md .= self::buildOverviewSection($overview);
        $md .= self::buildWhenToUseSection($whenToUse);
        $md .= self::buildCredentialsSection($requiredCreds, $setupNotes);
        $md .= self::buildInputParametersSection($inputParams);
        $md .= self::buildProcedureSection($procedure);
        $md .= self::buildOutputSection($outputFormat);
        $md .= self::buildScriptsSection($bundledScripts);
        $md .= self::buildBundledResourcesSection($bundledResources);
        $md .= self::buildReferenceCommandsSection($referenceCommands, $bundledScripts);
        $md .= self::buildExampleSection($exampleRequest);
        $md .= self::buildNotesSection($notes);
        $md .= self::buildKeywordsSection($keywords);

        return $md;
    }

    // =========================================================================
    // Frontmatter
    // =========================================================================

    private static function buildFrontmatter(
        string $slug,
        string $description,
        string $tagsYaml,
        string $license,
        string $version,
    ): string {
        $yaml = "---\n";
        $yaml .= "name: {$slug}\n";
        $yaml .= "description: \"{$description}\"\n";
        $yaml .= "tags: {$tagsYaml}\n";
        $yaml .= "version: {$version}\n";

        if ($license !== '') {
            $yaml .= "license: {$license}\n";
        }

        $yaml .= '---';

        return $yaml;
    }

    /**
     * Format tags as YAML — use inline array for short lists,
     * multi-line for longer lists or tags containing special chars.
     */
    private static function formatTagsYaml(array $tags): string
    {
        $needsMultiLine = count($tags) > 6
            || array_filter($tags, fn($t) => str_contains($t, ',') || str_contains($t, ':'));

        if ($needsMultiLine) {
            $lines = array_map(fn($t) => "  - {$t}", $tags);

            return "\n" . implode("\n", $lines);
        }

        return '[' . implode(', ', $tags) . ']';
    }

    // =========================================================================
    // Section builders
    // =========================================================================

    /**
     * Overview — a brief introductory paragraph providing context.
     * Inspired by: pdf ("## Overview"), brand-guidelines, theme-factory.
     */
    private static function buildOverviewSection(string $overview): string
    {
        if ($overview === '') {
            return '';
        }

        return "## Overview\n\n{$overview}\n\n";
    }

    /**
     * When to Use — ensures bullet-list format for consistent discoverability.
     * Inspired by: clipboard, csv-tools, text-to-speech, send-email.
     */
    private static function buildWhenToUseSection(string $whenToUse): string
    {
        $whenToUse = trim($whenToUse);

        // If the LLM already provided markdown with bullets, use as-is.
        // Otherwise, wrap in a clean format.
        if (str_contains($whenToUse, '- ') || str_contains($whenToUse, '* ')) {
            return "## When to Use\n\n{$whenToUse}\n\n";
        }

        // Legacy format: "Use this skill when the user asks to:\n..."
        // Ensure it reads cleanly even without bullets.
        return "## When to Use\n\n{$whenToUse}\n\n";
    }

    /**
     * Required Credentials — with optional per-service setup subsections.
     * Inspired by: send-email ("### Gmail Setup"), text-to-speech (OpenAI escalation).
     */
    private static function buildCredentialsSection(array $requiredCreds, array $setupNotes = []): string
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

        $md .= "\n";

        // Append per-service setup guidance (e.g., "Gmail Setup", "Twilio Setup")
        foreach ($setupNotes as $note) {
            $service      = $note['service'] ?? 'Service';
            $instructions = trim($note['instructions'] ?? '');
            if ($instructions !== '') {
                $md .= "### {$service} Setup\n\n{$instructions}\n\n";
            }
        }

        return $md;
    }

    /**
     * Input Parameters — table of required/optional params.
     */
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

    /**
     * Procedure — numbered steps. Ensures proper markdown formatting.
     * Inline code references in steps are preserved.
     */
    private static function buildProcedureSection(string $procedure): string
    {
        $procedure = trim($procedure);
        if ($procedure === '') {
            return '';
        }

        return "## Procedure\n\n{$procedure}\n\n";
    }

    /**
     * Output — what the skill produces/delivers.
     * Inspired by: algorithmic-art ("### OUTPUT FORMAT"), canvas-design output files,
     * financial-analysis ("## Last Result"), create-educational-podcast deliverables.
     */
    private static function buildOutputSection(string $outputFormat): string
    {
        if ($outputFormat === '') {
            return '';
        }

        return "## Output\n\n{$outputFormat}\n\n";
    }

    /**
     * Bundled Scripts — table + usage examples for scripts bundled with the skill.
     */
    private static function buildScriptsSection(array $bundledScripts): string
    {
        if (empty($bundledScripts)) {
            return '';
        }

        $md = "## Bundled Scripts\n\n";
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

        $md .= "Credentials in scripts use environment variables. Set them via `get_keys` before running.\n\n";

        return $md;
    }

    /**
     * Bundled Resources — non-script files shipped with the skill.
     * Inspired by: algorithmic-art (templates/viewer.html, templates/generator_template.js),
     * canvas-design (canvas-fonts/), internal-comms (examples/), theme-factory (themes/).
     */
    private static function buildBundledResourcesSection(array $bundledResources): string
    {
        if (empty($bundledResources)) {
            return '';
        }

        $md = "## Resources\n\n";
        $md .= "This skill includes the following reference files:\n\n";

        foreach ($bundledResources as $resource) {
            $path = $resource['path'] ?? '';
            $desc = $resource['description'] ?? '';
            if ($path !== '') {
                $md .= "- **`{$path}`**";
                if ($desc !== '') {
                    $md .= " — {$desc}";
                }
                $md .= "\n";
            }
        }

        return $md . "\n";
    }

    /**
     * Reference Commands — shell commands generalized with placeholders.
     * Skipped when auto-generated scripts already cover the workflow.
     */
    private static function buildReferenceCommandsSection(string $referenceCommands, array $bundledScripts): string
    {
        $hasAutoScripts = !empty(array_filter(
            $bundledScripts,
            fn($s) => ($s['source'] ?? '') === 'auto_generated'
        ));

        if ($referenceCommands === '' || $hasAutoScripts) {
            return '';
        }

        $md = "## Reference Commands\n\n";
        $md .= "Commands for executing this skill (adapt to actual inputs):\n\n";
        $md .= "```bash\n{$referenceCommands}\n```\n\n";
        $md .= "Replace `{{PLACEHOLDER}}` values with actual credentials from the key store.\n\n";

        return $md;
    }

    /**
     * Example — trigger phrases that should match this skill.
     * Supports both a single string and an array of examples.
     */
    private static function buildExampleSection(string $exampleRequest): string
    {
        if ($exampleRequest === '') {
            return '';
        }

        $md = "## Example\n\n";
        $md .= "Example requests that trigger this skill:\n\n";
        $md .= "```\n{$exampleRequest}\n```\n";

        return $md;
    }

    /**
     * Notes — tips, gotchas, and important caveats.
     * Inspired by: pdf ("## Next Steps"), web-artifacts-builder ("VERY IMPORTANT"),
     * canvas-design ("## FINAL STEP"), algorithmic-art ("### ESSENTIAL PRINCIPLES").
     */
    private static function buildNotesSection(array $notes): string
    {
        if (empty($notes)) {
            return '';
        }

        $md = "\n## Notes\n\n";
        foreach ($notes as $note) {
            $md .= "- {$note}\n";
        }

        return $md . "\n";
    }

    /**
     * Keywords — explicit trigger keywords to aid skill discovery/matching.
     * Inspired by: brand-guidelines ("**Keywords**: branding, corporate identity, ..."),
     * internal-comms ("## Keywords").
     */
    private static function buildKeywordsSection(array $keywords): string
    {
        if (empty($keywords)) {
            return '';
        }

        return "\n## Keywords\n\n" . implode(', ', $keywords) . "\n";
    }
}
