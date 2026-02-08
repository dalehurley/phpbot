<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Router;

use ClaudeAgents\Skills\SkillManager;

/**
 * Five-tier router with early exit for simple requests.
 *
 * Tier 0: Instant PHP-computed answer (0 tokens)
 * Tier 1: Single bash command (0 tokens)
 * Tier 2: Cached category match (0 routing tokens)
 * Tier 3a: PHP native TF-IDF classifier (0 tokens, in-process)
 * Tier 3b: LLM classifier fallback (~500 tokens, Apple FM → MLX → cloud)
 */
class CachedRouter
{
    private PhpNativeClassifier $phpClassifier;

    public function __construct(
        private RouterCache $cache,
        private ClassifierClient $classifier,
        private ?SkillManager $skillManager = null,
    ) {
        $this->phpClassifier = new PhpNativeClassifier();
    }

    /**
     * Set an optional logger for the PHP native classifier.
     */
    public function setLogger(?\Closure $logger): void
    {
        $this->phpClassifier->setLogger($logger);
    }

    /**
     * Route a user input through the 5-tier escalation model.
     */
    public function route(string $input): RouteResult
    {
        $normalized = strtolower(trim($input));

        // Tier 0: Instant answer (regex, 0 tokens)
        $instant = $this->tryInstantAnswer($normalized);
        if ($instant !== null) {
            return $instant;
        }

        // Tier 1: Single bash command (pattern match, 0 tokens)
        $bash = $this->tryBashCommand($normalized);
        if ($bash !== null) {
            return $bash;
        }

        // Tier 2: Cached category match (keyword scoring, 0 tokens)
        $cached = $this->tryCategoryMatch($normalized);
        if ($cached !== null) {
            return $cached;
        }

        // Tier 3a: PHP native TF-IDF classifier (in-process, 0 tokens)
        $phpResult = $this->tryPhpClassifier($normalized);
        if ($phpResult !== null) {
            return $phpResult;
        }

        // Tier 3b: LLM classifier (Apple FM → MLX → Ollama → cloud)
        return $this->classify($input);
    }

    // -------------------------------------------------------------------------
    // Tier 0: Instant answers
    // -------------------------------------------------------------------------

    private function tryInstantAnswer(string $input): ?RouteResult
    {
        // Instant answers use strict regex patterns to avoid false positives.
        // Each pattern must match the full intent, not just a keyword.
        foreach (self::INSTANT_ANSWER_PATTERNS as $regex => $type) {
            if (preg_match($regex, $input)) {
                $answer = $this->computeInstantAnswer($type, $input);
                if ($answer !== null) {
                    return RouteResult::instant($answer);
                }
            }
        }

        return null;
    }

    /**
     * Strict regex patterns for Tier 0 instant answers.
     *
     * These are intentionally narrow to prevent false positives.
     * The pattern must match the user's actual intent, not just a keyword.
     */
    private const INSTANT_ANSWER_PATTERNS = [
        // Time queries: "what time is it", "current time", "what's the time", "time please"
        // But NOT: "what time did...", "time to cook...", "time management..."
        '/\b(?:what(?:\'?s| is) the time(?:\s+in\s+[\w\s]+?)?|what time is it(?:\s+in\s+[\w\s]+?)?|current time|time (?:now|please|here|in [\w\s]+?))\s*[?!.]?\s*$/i' => 'time',

        // Date queries: "what is the date", "today's date", "what day is it"
        // But NOT: "what is the date of christmas", "date of birth"
        '/\b(?:what(?:\'?s| is) (?:the |today(?:\'?s)? )?date(?! of\b)|what day is (?:it|today)|today(?:\'?s)? date|current date)\b/i' => 'date',

        // Greetings: only when the entire input is essentially a greeting
        '/^(?:hello|hi|hey|good (?:morning|afternoon|evening)|howdy|greetings|yo)\b(?:\s+there)?[!.\s]*$/i' => 'greeting',

        // Capabilities: "what can you do", "help", "your capabilities"
        '/\b(?:what can you do|your capabilities|what are you capable of)\b/i' => 'capabilities',
        '/^help[!?\s]*$/i' => 'capabilities',
    ];

    /**
     * Compute an instant answer by type.
     */
    private function computeInstantAnswer(string $type, string $input): ?string
    {
        return match ($type) {
            'time' => $this->computeTimeAnswer($input),
            'date' => 'Today is **' . date('l, F jS, Y') . '**.',
            'greeting' => 'Hello! I\'m PhpBot, your AI assistant. I can help with tasks like creating documents, sending emails, running commands, processing data, and much more. What would you like me to do?',
            'capabilities' => $this->buildCapabilitiesAnswer(),
            default => null,
        };
    }

    /**
     * Compute a time answer, detecting timezone from the input.
     *
     * Supports "time in <city>" patterns with common city-to-timezone mappings.
     * Falls back to the system's local timezone.
     */
    private function computeTimeAnswer(string $input): string
    {
        $timezone = $this->detectTimezone($input);

        $tz = new \DateTimeZone($timezone);
        $now = new \DateTimeImmutable('now', $tz);

        $timeStr = $now->format('g:i A T');
        $dateStr = $now->format('l, F jS, Y');

        return "The current time is **{$timeStr}** on {$dateStr}.";
    }

    /**
     * Detect timezone from user input like "time in Sydney" or "time in New York".
     * Falls back to the system's default timezone.
     */
    private function detectTimezone(string $input): string
    {
        // Common city-to-timezone mappings
        $cityTimezones = [
            'sydney' => 'Australia/Sydney',
            'melbourne' => 'Australia/Melbourne',
            'brisbane' => 'Australia/Brisbane',
            'perth' => 'Australia/Perth',
            'adelaide' => 'Australia/Adelaide',
            'auckland' => 'Pacific/Auckland',
            'new york' => 'America/New_York',
            'nyc' => 'America/New_York',
            'los angeles' => 'America/Los_Angeles',
            'la' => 'America/Los_Angeles',
            'chicago' => 'America/Chicago',
            'denver' => 'America/Denver',
            'london' => 'Europe/London',
            'paris' => 'Europe/Paris',
            'berlin' => 'Europe/Berlin',
            'tokyo' => 'Asia/Tokyo',
            'beijing' => 'Asia/Shanghai',
            'shanghai' => 'Asia/Shanghai',
            'singapore' => 'Asia/Singapore',
            'hong kong' => 'Asia/Hong_Kong',
            'dubai' => 'Asia/Dubai',
            'mumbai' => 'Asia/Kolkata',
            'delhi' => 'Asia/Kolkata',
            'toronto' => 'America/Toronto',
            'vancouver' => 'America/Vancouver',
            'sao paulo' => 'America/Sao_Paulo',
            'moscow' => 'Europe/Moscow',
            'seoul' => 'Asia/Seoul',
            'bangkok' => 'Asia/Bangkok',
            'jakarta' => 'Asia/Jakarta',
            'cairo' => 'Africa/Cairo',
            'johannesburg' => 'Africa/Johannesburg',
            'hawaii' => 'Pacific/Honolulu',
            'alaska' => 'America/Anchorage',
        ];

        $lower = strtolower($input);
        foreach ($cityTimezones as $city => $tz) {
            if (str_contains($lower, $city)) {
                return $tz;
            }
        }

        // Fall back to system timezone
        return date_default_timezone_get();
    }

    /**
     * Build a dynamic capabilities answer from the cache index.
     */
    private function buildCapabilitiesAnswer(): string
    {
        $skills = $this->cache->getSkillIndex();
        $tools = $this->cache->getToolIndex();

        $answer = "I'm PhpBot, an AI assistant with access to a full computer. Here's what I can do:\n\n";
        $answer .= "**Skills** (" . count($skills) . " available):\n";

        foreach ($skills as $name => $desc) {
            $answer .= "- **{$name}**: {$desc}\n";
        }

        $answer .= "\n**Core Tools** (" . count($tools) . " available):\n";

        foreach ($tools as $name => $desc) {
            $shortDesc = mb_strlen($desc) > 80 ? mb_substr($desc, 0, 77) . '...' : $desc;
            $answer .= "- **{$name}**: {$shortDesc}\n";
        }

        $answer .= "\nJust ask me to do something and I'll figure out the best approach!";

        return $answer;
    }

    // -------------------------------------------------------------------------
    // Tier 1: Single bash command
    // -------------------------------------------------------------------------

    private function tryBashCommand(string $input): ?RouteResult
    {
        foreach ($this->cache->getBashCommands() as $pattern => $command) {
            if ($this->matchesPattern($input, $pattern)) {
                return RouteResult::bash($command);
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Tier 2: Cached category match
    // -------------------------------------------------------------------------

    private function tryCategoryMatch(string $input): ?RouteResult
    {
        $bestMatch = null;
        $bestScore = 0;

        foreach ($this->cache->getCategories() as $category) {
            $score = $this->scoreCategoryMatch($input, $category);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $category;
            }
        }

        // Require at least one pattern word to match
        if ($bestMatch !== null && $bestScore >= 1) {
            // Also resolve skills from SkillManager for better matching
            $skills = $bestMatch['skills'] ?? [];
            if ($this->skillManager !== null) {
                try {
                    $resolved = $this->skillManager->resolve($input, 0.3);
                    foreach ($resolved as $skill) {
                        $name = $skill->getName();
                        if (!in_array($name, $skills, true)) {
                            $skills[] = $name;
                        }
                    }
                } catch (\Throwable) {
                    // Ignore skill resolution errors
                }
            }

            return RouteResult::cached(
                tools: $this->ensureMinimalTools($bestMatch['tools'] ?? []),
                skills: $skills,
                agentType: $bestMatch['agent_type'] ?? 'react',
                promptTier: $bestMatch['prompt_tier'] ?? 'standard',
                confidence: min(1.0, $bestScore / 3),
            );
        }

        return null;
    }

    /**
     * Score how well an input matches a category.
     */
    private function scoreCategoryMatch(string $input, array $category): float
    {
        $score = 0.0;
        $inputWords = preg_split('/\s+/', $input);

        foreach ($category['patterns'] ?? [] as $pattern) {
            $alternatives = array_map('trim', explode('|', $pattern));
            foreach ($alternatives as $alt) {
                // Exact phrase match
                if (str_contains($input, $alt)) {
                    $score += 2.0;

                    continue;
                }

                // Individual word match
                $patternWords = preg_split('/\s+/', $alt);
                foreach ($patternWords as $word) {
                    if (strlen($word) > 2) {
                        foreach ($inputWords as $inputWord) {
                            if (str_contains($inputWord, $word) || str_contains($word, $inputWord)) {
                                $score += 0.5;
                            }
                        }
                    }
                }
            }
        }

        return $score;
    }

    // -------------------------------------------------------------------------
    // Tier 3a: PHP native TF-IDF classifier
    // -------------------------------------------------------------------------

    private function tryPhpClassifier(string $input): ?RouteResult
    {
        $categories = $this->cache->getCategories();

        if (empty($categories)) {
            return null;
        }

        $result = $this->phpClassifier->classify($input, $categories);

        if ($result === null) {
            return null;
        }

        $category = $result['category'];
        $confidence = $result['confidence'];

        // Also resolve skills from SkillManager for better matching
        $skills = $category['skills'] ?? [];
        if ($this->skillManager !== null) {
            try {
                $resolved = $this->skillManager->resolve($input, 0.3);
                foreach ($resolved as $skill) {
                    $name = $skill->getName();
                    if (!in_array($name, $skills, true)) {
                        $skills[] = $name;
                    }
                }
            } catch (\Throwable) {
                // Ignore skill resolution errors
            }
        }

        return RouteResult::cached(
            tools: $this->ensureMinimalTools($category['tools'] ?? []),
            skills: $skills,
            agentType: $category['agent_type'] ?? 'react',
            promptTier: $category['prompt_tier'] ?? 'standard',
            confidence: $confidence,
        );
    }

    // -------------------------------------------------------------------------
    // Tier 3b: LLM classifier (provider-agnostic via ClassifierClient)
    // -------------------------------------------------------------------------

    private function classify(string $input): RouteResult
    {
        try {
            $categories = $this->cache->getCategories();
            $categoryList = '';
            foreach ($categories as $cat) {
                $patterns = implode(', ', array_slice($cat['patterns'] ?? [], 0, 5));
                $categoryList .= "- {$cat['id']}: {$patterns}\n";
            }

            $prompt = <<<PROMPT
Classify this user request into one of these categories:

{$categoryList}
- general: anything that doesn't fit above

User request: "{$input}"

Respond with ONLY a JSON object: {"category_id": "...", "tools": ["bash", "search_capabilities", ...], "agent_type": "react|plan_execute", "prompt_tier": "minimal|standard|full"}
PROMPT;

            $response = $this->classifier->classify($prompt);
            $classification = json_decode($response, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($classification)) {
                // Find the matched category for its skills
                $skills = [];
                $categoryId = $classification['category_id'] ?? 'general';
                foreach ($categories as $cat) {
                    if (($cat['id'] ?? '') === $categoryId) {
                        $skills = $cat['skills'] ?? [];

                        break;
                    }
                }

                return RouteResult::classified(
                    tools: $this->ensureMinimalTools($classification['tools'] ?? ['bash']),
                    skills: $skills,
                    agentType: $classification['agent_type'] ?? 'react',
                    promptTier: $classification['prompt_tier'] ?? 'standard',
                );
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        // Default fallback: give the agent everything
        return RouteResult::classified(
            tools: ['bash', 'search_capabilities'],
            skills: [],
            agentType: 'react',
            promptTier: 'standard',
            confidence: 0.3,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check if input matches a pipe-separated pattern.
     * Uses word-boundary matching to prevent "time" matching "uptime".
     */
    private function matchesPattern(string $input, string $pattern): bool
    {
        $alternatives = array_map('trim', explode('|', $pattern));

        foreach ($alternatives as $alt) {
            if ($alt === '') {
                continue;
            }

            // Multi-word alternatives: check substring containment
            if (str_contains($alt, ' ')) {
                if (str_contains($input, $alt)) {
                    return true;
                }
            } else {
                // Single-word alternatives: use word boundary matching
                // to prevent "time" matching "uptime"
                if (preg_match('/\b' . preg_quote($alt, '/') . '\b/', $input)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Ensure bash and search_capabilities are always in the tool set.
     *
     * @param string[] $tools
     * @return string[]
     */
    private function ensureMinimalTools(array $tools): array
    {
        if (!in_array('bash', $tools, true)) {
            array_unshift($tools, 'bash');
        }

        if (!in_array('search_capabilities', $tools, true)) {
            $tools[] = 'search_capabilities';
        }

        return $tools;
    }
}
