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
 *  - CurlScriptBuilder    — parameterised API script generation
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
        $result,
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

        // Only skip skill creation if a skill was genuinely matched with high
        // confidence (fast-path). Low-confidence matches from the resolver
        // (e.g. "xlsx" matching on "send sms") should NOT prevent creation.
        if (!empty($analysis['skill_matched'])) {
            return;
        }

        if (!SkillAssessor::shouldCreate($analysis)) {
            return;
        }

        $skillsPath = $this->config['skills_path'] ?? '';
        if (!is_string($skillsPath) || $skillsPath === '' || !is_dir($skillsPath)) {
            return;
        }

        // --- Extract data from tool calls ---

        $toolCalls = $result->getToolCalls();

        $scripts         = ScriptExtractor::fromToolCalls($toolCalls);
        $recipe          = SkillTextUtils::extractToolRecipe($toolCalls);
        $credentialReport = CredentialPatterns::detectFromToolCalls($recipe, $toolCalls);
        $sanitizedRecipe  = CredentialPatterns::strip($recipe);
        $generatedScripts = $this->curlScriptBuilder->generateApiScripts($toolCalls, $credentialReport);

        // --- Generalise via LLM ---

        $allScripts  = array_merge($scripts, $generatedScripts);

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
            return;
        }

        $bundledScripts = ScriptExtractor::bundle($dir, $allScripts);

        $skillMd = SkillMarkdownBuilder::build(
            $slug,
            $generalized,
            $bundledScripts,
        );

        file_put_contents($dir . '/SKILL.md', $skillMd);

        $scriptCount = count($bundledScripts);
        $scriptNote  = $scriptCount > 0 ? " with {$scriptCount} script(s)" : '';
        $progress('skills', "Created skill: {$slug}{$scriptNote}");
    }
}
