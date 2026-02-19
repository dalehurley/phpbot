<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use ClaudeAgents\Skills\SkillManager;
use Dalehurley\Phpbot\Skill\ScriptExtractor;
use Dalehurley\Phpbot\Skill\SkillAssessor;
use Dalehurley\Phpbot\Skill\SkillGeneralizer;
use Dalehurley\Phpbot\Skill\SkillMarkdownBuilder;
use Dalehurley\Phpbot\Skill\SkillTextUtils;

/**
 * Orchestrates automatic skill creation from successfully-completed tasks.
 *
 * Each concern is delegated to a specialised class:
 *  - SkillAssessor         — decides if a task warrants a skill
 *  - ScriptExtractor       — extracts/bundles scripts from tool calls
 *  - SkillGeneralizer      — LLM-powered (with fallback) skill definition
 *  - SkillMarkdownBuilder  — assembles the SKILL.md file
 *  - SkillTextUtils        — shared text utilities (slugify, sanitise, etc.)
 *  - CredentialPatterns    — credential detection & stripping
 *  - CurlScriptBuilder     — parameterised API script generation
 */
class SkillAutoCreator
{
    private CurlScriptBuilder $curlScriptBuilder;
    private SkillGeneralizer $generalizer;

    /**
     * @param \Closure(): \ClaudePhp\ClaudePhp $clientFactory
     */
    public function __construct(
        private \Closure $clientFactory,
        private array $config,
        private ?SkillManager $skillManager = null
    ) {
        $this->curlScriptBuilder = new CurlScriptBuilder();
        $this->generalizer = new SkillGeneralizer($clientFactory, $config);
    }

    public function autoCreate(
        string $input,
        array $analysis,
        mixed $result,
        array $resolvedSkills,
        callable $progress
    ): void {
        // --- Guard clauses ---

        if (!($result && $result->isSuccess())) {
            return;
        }

        if ($this->skillManager === null) {
            return;
        }

        // Only suppress skill creation for high-confidence skill matches (fast-path).
        // Low-confidence resolver hits (e.g. "xlsx" superficially matching "send sms")
        // should not prevent a new, more specific skill from being created.
        if ($this->isHighConfidenceSkillMatch($analysis)) {
            return;
        }

        if (!SkillAssessor::shouldCreate($analysis)) {
            return;
        }

        $skillsPath = $this->config['skills_path'] ?? '';
        if (!is_string($skillsPath) || $skillsPath === '' || !is_dir($skillsPath)) {
            return;
        }

        $toolCalls = $result->getToolCalls();

        // Only capture skills from tasks that executed real work (bash commands or
        // file writes). Pure conversation tasks have nothing durable to generalise.
        if (!$this->hasSubstantiveToolUse($toolCalls)) {
            return;
        }

        // Announce early — LLM generalisation takes a moment and the user should
        // know something is happening before the response fully appears.
        $progress('skills', 'Capturing reusable skill from this task…');

        // --- Extract data from tool calls ---

        $scripts          = ScriptExtractor::fromToolCalls($toolCalls);
        $recipe           = SkillTextUtils::extractToolRecipe($toolCalls);
        $credentialReport = CredentialPatterns::detectFromToolCalls($recipe, $toolCalls);
        $sanitizedRecipe  = CredentialPatterns::strip($recipe);
        $generatedScripts = $this->curlScriptBuilder->generateApiScripts($toolCalls, $credentialReport);

        // --- Generalise via LLM ---

        $allScripts = array_merge($scripts, $generatedScripts);

        $generalized = $this->generalizer->generalize(
            $input,
            $analysis,
            $result->getAnswer() ?? '',
            $allScripts,
            $sanitizedRecipe,
            $credentialReport
        );

        // --- Write skill to disk ---

        $slug = SkillTextUtils::slugify($generalized['name']);
        if ($slug === '') {
            return;
        }

        $dir = $skillsPath . '/' . $slug;
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            $progress('skills', "Failed to create skill directory: {$slug}");
            return;
        }

        $bundledScripts = ScriptExtractor::bundle($dir, $allScripts);

        $skillMd = SkillMarkdownBuilder::build(
            $slug,
            $generalized,
            $bundledScripts,
        );

        if (file_put_contents($dir . '/SKILL.md', $skillMd) === false) {
            $progress('skills', "Failed to write SKILL.md for: {$slug}");
            return;
        }

        $scriptCount = count($bundledScripts);
        $scriptNote  = $scriptCount > 0 ? " with {$scriptCount} script(s)" : '';
        $progress('skills', "Created skill: {$slug}{$scriptNote}");
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Return true only when a previous skill was matched with high confidence.
     *
     * The analysis array may carry a `skill_confidence` key ('high'|'medium'|'low').
     * When absent, we default to 'high' to preserve backward-compatible behaviour
     * (if something was matched, assume it was deliberate unless told otherwise).
     */
    private function isHighConfidenceSkillMatch(array $analysis): bool
    {
        if (empty($analysis['skill_matched'])) {
            return false;
        }

        $confidence = $analysis['skill_confidence'] ?? 'high';

        return $confidence === 'high';
    }

    /**
     * Return true when the tool call list contains at least one bash execution
     * or file-write operation — the signals that indicate generalizable work.
     *
     * Tasks that only called ask_user, get_keys, or read-only tools produced
     * no durable artefact and are not worth capturing as a skill.
     */
    private function hasSubstantiveToolUse(array $toolCalls): bool
    {
        foreach ($toolCalls as $call) {
            if (!empty($call['is_error'])) {
                continue;
            }

            $tool = $call['tool'] ?? '';
            if ($tool === 'bash' || $tool === 'write_file') {
                return true;
            }
        }

        return false;
    }
}
