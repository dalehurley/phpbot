<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Skill;

use Dalehurley\Phpbot\CredentialPatterns;

/**
 * Assembles the SKILL.md file content from the LLM-generalised skill data
 * and bundled script metadata.
 *
 * Output follows the Anthropic skill-creator conventions:
 *  - Frontmatter contains ONLY `name` and `description` (the two fields
 *    the runtime reads for skill matching). No tags, version, or extras.
 *  - The `description` carries all "when to use" and keyword information;
 *    there is no ## When to Use or ## Keywords body section, as those are
 *    never loaded at trigger time and waste context once loaded.
 *  - The body focuses on executable, non-obvious guidance: credentials,
 *    parameters, procedure, scripts, commands, examples, and notes.
 *
 * Supported skill archetypes:
 *  - Script-driven utilities  (clipboard, csv-tools, send-email)
 *  - Command-driven skills    (open-application, text-to-speech)
 *  - Reference/knowledge guides (pdf, brand-guidelines, internal-comms)
 *  - Creative workflow skills  (algorithmic-art, canvas-design)
 *  - Toolkit skills           (web-artifacts-builder, word-documents)
 *
 * All creative content (description, procedure, examples, etc.) is produced
 * by the LLM in SkillGeneralizer. The builder formats it as markdown with
 * the correct sections for the skill's archetype.
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
        $description       = str_replace('"', '\\"', $generalized['description']);
        $procedure         = $generalized['procedure'];
        $requiredCreds     = $generalized['required_credentials'] ?? [];
        $setupNotes        = $generalized['setup_notes'] ?? [];
        $inputParams       = $generalized['input_parameters'] ?? [];
        $outputFormat      = trim($generalized['output_format'] ?? '');
        $referenceCommands = CredentialPatterns::strip(trim($generalized['reference_commands'] ?? ''));
        $notes             = $generalized['notes'] ?? [];
        $bundledResources  = $generalized['bundled_resources'] ?? [];

        // Normalise examples: prefer the `examples` array, fall back to the
        // legacy single `example_request` string for backward compatibility.
        $examples = $generalized['examples'] ?? [];
        if (empty($examples)) {
            $single = CredentialPatterns::strip(trim($generalized['example_request'] ?? ''));
            if ($single !== '') {
                $examples = [$single];
            }
        } else {
            $examples = array_values(array_filter(
                array_map(fn($e) => CredentialPatterns::strip(trim((string) $e)), $examples),
                fn($e) => $e !== ''
            ));
        }

        // Human-readable title: title-case each hyphen-separated word.
        $title = implode(' ', array_map('ucfirst', explode('-', $slug)));

        // --- Assemble sections in order ---

        $md  = self::buildFrontmatter($slug, $description);
        $md .= "\n\n# {$title}\n\n";
        $md .= self::buildCredentialsSection($requiredCreds, $setupNotes);
        $md .= self::buildInputParametersSection($inputParams);
        $md .= self::buildProcedureSection($procedure);
        $md .= self::buildOutputSection($outputFormat);
        $md .= self::buildScriptsSection($bundledScripts);
        $md .= self::buildBundledResourcesSection($bundledResources);
        $md .= self::buildReferenceCommandsSection($referenceCommands, $bundledScripts);
        $md .= self::buildExampleSection($examples);
        $md .= self::buildNotesSection($notes);

        return $md;
    }

    // =========================================================================
    // Frontmatter
    // =========================================================================

    /**
     * Only `name` and `description` — the two fields the runtime reads for
     * skill matching. Additional fields (tags, version, etc.) are not parsed
     * and only bloat the metadata that is always held in context.
     */
    private static function buildFrontmatter(string $slug, string $description): string
    {
        return "---\nname: {$slug}\ndescription: \"{$description}\"\n---";
    }

    // =========================================================================
    // Section builders
    // =========================================================================

    /**
     * Required Credentials — with optional per-service setup subsections.
     */
    private static function buildCredentialsSection(array $requiredCreds, array $setupNotes = []): string
    {
        if (empty($requiredCreds)) {
            return '';
        }

        $md  = "## Required Credentials\n\n";
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

        $md  = "## Input Parameters\n\n";
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
     * Procedure — numbered steps with concrete commands.
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
     * Output — what the skill produces/delivers. Omitted when empty or obvious.
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

        $md  = "## Bundled Scripts\n\n";
        $md .= "| Script | Type | Description |\n";
        $md .= "|--------|------|-------------|\n";

        foreach ($bundledScripts as $script) {
            $ext  = strtoupper($script['extension']);
            $desc = $script['description'] ?? 'Auto-captured from task execution';
            $md  .= "| `{$script['path']}` | {$ext} | {$desc} |\n";
        }

        $md .= "\n";

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
     * Reference Commands — generalized shell commands with {{PLACEHOLDER}} vars.
     * Omitted when auto-generated scripts already cover the workflow.
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

        return "## Reference Commands\n\n```bash\n{$referenceCommands}\n```\n\n";
    }

    /**
     * Example — trigger phrases that should match this skill.
     *
     * Accepts an array of diverse user queries. Multiple examples improve
     * skill discoverability across different phrasings of the same intent,
     * matching the convention used in project skills like `screenshot`.
     */
    private static function buildExampleSection(array $examples): string
    {
        if (empty($examples)) {
            return '';
        }

        return "## Example\n\n```\n" . implode("\n", $examples) . "\n```\n";
    }

    /**
     * Notes — tips, gotchas, and important caveats.
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
}
