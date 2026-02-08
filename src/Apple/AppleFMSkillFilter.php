<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Apple;

use Dalehurley\Phpbot\Stats\TokenLedger;

/**
 * Apple FM-powered semantic skill relevance filter.
 *
 * After cheap keyword-based skill resolution returns candidates, this filter
 * uses the on-device Apple Intelligence model to validate whether those
 * candidates are actually relevant to the user's request.
 *
 * This catches false positives that slip through keyword matching (e.g.
 * "financial planning" matching "financial-analysis" when the user wants
 * general planning advice, not financial statement analysis).
 *
 * All calls are free (on-device), private, and typically complete in <1s.
 */
class AppleFMSkillFilter
{
    /** Optional logging callback. */
    private ?\Closure $logger = null;

    public function __construct(
        private SmallModelClient $appleFM,
        private ?TokenLedger $ledger = null,
    ) {}

    /**
     * Set an optional logger.
     */
    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Filter resolved skills to only those semantically relevant to the input.
     *
     * Takes candidate skills from the keyword resolver and asks Apple FM
     * to identify which ones are genuinely relevant to the user's request.
     * Returns only the relevant skills, or an empty array if none are.
     *
     * Falls back to returning all candidates if Apple FM fails (safe default
     * that preserves existing behavior).
     *
     * @param string $input The user's request
     * @param array $skills Array of SkillInterface objects (candidates from keyword matching)
     * @return array Filtered array of SkillInterface objects
     */
    public function filter(string $input, array $skills): array
    {
        if (empty($skills)) {
            return [];
        }

        // Build compact skill descriptions for the prompt
        $skillList = [];
        $skillMap = [];

        foreach ($skills as $skill) {
            $name = $skill->getName();
            $description = $skill->getDescription();
            // Truncate long descriptions to keep prompt small
            if (strlen($description) > 150) {
                $description = substr($description, 0, 147) . '...';
            }
            $skillList[] = "- {$name}: {$description}";
            $skillMap[$name] = $skill;
        }

        $skillListStr = implode("\n", $skillList);
        $prompt = <<<PROMPT
Given this user request: "{$input}"

Which of these skills are relevant and would help complete the request?

{$skillListStr}

Respond with JSON: {"relevant": ["skill-name-1"]} or {"relevant": []} if none are relevant.
Only include skills that directly help with the specific request.
PROMPT;

        try {
            $response = $this->appleFM->classify($prompt, 128);
            $data = json_decode($response, true);

            if (!is_array($data) || !isset($data['relevant'])) {
                $this->log('Apple FM skill filter returned invalid JSON, returning no matches (safer than keeping all candidates)');

                return [];
            }

            $relevantNames = $data['relevant'];

            if (!is_array($relevantNames)) {
                $this->log('Apple FM skill filter returned non-array relevant field, returning no matches');

                return [];
            }

            // Map names back to skill objects
            $filtered = [];

            foreach ($relevantNames as $name) {
                if (isset($skillMap[$name])) {
                    $filtered[] = $skillMap[$name];
                }
            }

            $originalCount = count($skills);
            $filteredCount = count($filtered);

            if ($filteredCount < $originalCount) {
                $this->log("Filtered skills: {$filteredCount}/{$originalCount} relevant (removed " . ($originalCount - $filteredCount) . ' false positives)');
            } else {
                $this->log("All {$originalCount} candidate skills confirmed relevant");
            }

            return $filtered;
        } catch (\Throwable $e) {
            // On failure, return no matches rather than all candidates.
            // Keeping all candidates floods the prompt with irrelevant skills
            // and triggers expensive optimization calls for each one.
            $this->log('Apple FM skill filter failed: ' . $e->getMessage() . ', returning no matches');

            return [];
        }
    }

    /**
     * Log a message via the optional logger.
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
