<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Router;

/**
 * Zero-dependency PHP-native classifier for task routing.
 *
 * Uses TF-IDF weighted cosine similarity with synonym expansion and
 * basic morphological normalization to classify user input into
 * known categories — all in-process, no external calls.
 *
 * Typically handles ~80% of requests, eliminating the need for an LLM
 * classifier call entirely. When confidence is too low, falls through
 * to the LLM-based ClassifierClient.
 */
class PhpNativeClassifier
{
    private const DEFAULT_MIN_CONFIDENCE = 0.35;

    /**
     * Common English stop words — filtered from both input and patterns.
     * Only true function words; action verbs like "make", "get", "help" are kept.
     */
    private const STOP_WORDS = [
        'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'have', 'has', 'had', 'having', 'do', 'does', 'did', 'doing',
        'will', 'would', 'could', 'should', 'may', 'might', 'shall', 'can',
        'to', 'of', 'in', 'for', 'on', 'with', 'at', 'by', 'from', 'as',
        'into', 'about', 'like', 'through', 'after', 'over', 'between',
        'and', 'but', 'or', 'nor', 'not', 'so', 'yet',
        'this', 'that', 'these', 'those', 'it', 'its',
        'i', 'me', 'my', 'mine', 'we', 'us', 'our', 'ours',
        'you', 'your', 'yours', 'he', 'him', 'his', 'she', 'her', 'hers',
        'they', 'them', 'their', 'theirs',
        'what', 'which', 'who', 'whom', 'whose',
        'if', 'then', 'else', 'when', 'where', 'why', 'how',
        'all', 'each', 'every', 'both', 'few', 'more', 'most',
        'some', 'any', 'no', 'only', 'very', 'just', 'also',
        'please', 'want', 'need', 'let',
    ];

    /**
     * Synonym groups: canonical form → alternatives.
     *
     * Both directions are indexed (canonical ↔ synonym) so that
     * "make a file" matches a category pattern containing "create file".
     */
    private const SYNONYMS = [
        'create'   => ['make', 'build', 'generate', 'produce', 'craft', 'compose', 'author', 'draft', 'new', 'add', 'init', 'initialize', 'scaffold'],
        'delete'   => ['remove', 'destroy', 'erase', 'clear', 'drop', 'trash', 'eliminate', 'purge', 'unlink'],
        'update'   => ['modify', 'change', 'edit', 'alter', 'revise', 'patch', 'amend', 'tweak', 'adjust'],
        'find'     => ['search', 'locate', 'discover', 'query', 'lookup', 'seek', 'scan', 'grep', 'filter'],
        'send'     => ['deliver', 'transmit', 'dispatch', 'mail', 'email', 'post', 'submit', 'notify'],
        'install'  => ['setup', 'configure', 'deploy', 'provision'],
        'run'      => ['execute', 'launch', 'start', 'invoke', 'trigger', 'fire', 'spawn'],
        'fix'      => ['repair', 'debug', 'resolve', 'correct', 'troubleshoot', 'diagnose'],
        'list'     => ['show', 'display', 'enumerate', 'view', 'print', 'dump', 'output'],
        'analyze'  => ['examine', 'inspect', 'review', 'assess', 'evaluate', 'audit', 'profile'],
        'convert'  => ['transform', 'translate', 'parse', 'format', 'encode', 'decode', 'serialize'],
        'download' => ['fetch', 'retrieve', 'pull', 'grab', 'get', 'wget', 'curl'],
        'upload'   => ['push', 'publish', 'share', 'distribute', 'release'],
        'test'     => ['verify', 'validate', 'assert', 'confirm', 'check'],
        'document' => ['describe', 'explain', 'annotate', 'comment', 'readme'],
        'optimize' => ['improve', 'enhance', 'speed', 'boost', 'accelerate', 'refactor', 'clean'],
        'monitor'  => ['watch', 'track', 'observe', 'log', 'tail'],
        'backup'   => ['save', 'archive', 'snapshot', 'preserve', 'export'],
        'compress' => ['zip', 'gzip', 'tar', 'pack', 'bundle'],
        'extract'  => ['unzip', 'unpack', 'decompress', 'expand'],
        'write'    => ['author', 'compose', 'draft', 'type', 'pen'],
        'read'     => ['open', 'cat', 'view', 'load', 'inspect'],
        'copy'     => ['duplicate', 'clone', 'replicate', 'mirror'],
        'move'     => ['rename', 'relocate', 'transfer', 'mv'],
        'stop'     => ['kill', 'halt', 'terminate', 'abort', 'cancel', 'end', 'quit'],
    ];

    /** Reverse lookup: synonym → canonical form. */
    private array $synonymIndex = [];

    /** IDF scores computed from category corpus. */
    private array $idfScores = [];

    /** Optional logging callback. */
    private ?\Closure $logger = null;

    public function __construct(
        private float $minConfidence = self::DEFAULT_MIN_CONFIDENCE,
    ) {
        $this->buildSynonymIndex();
    }

    /**
     * Set an optional logger for debugging classification decisions.
     */
    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Classify user input against known categories.
     *
     * @param string $input       Raw user input
     * @param array  $categories  Categories from the router cache
     * @return array{category: array, confidence: float}|null  Best match or null
     */
    public function classify(string $input, array $categories): ?array
    {
        $inputTokens = $this->tokenize($input);
        if (empty($inputTokens)) {
            return null;
        }

        // Build IDF weights from the category corpus
        $this->computeIdf($categories);

        $bestCategory = null;
        $bestScore = 0.0;
        $secondBestScore = 0.0;

        foreach ($categories as $category) {
            $score = $this->scoreCategory($inputTokens, strtolower($input), $category);

            if ($score > $bestScore) {
                $secondBestScore = $bestScore;
                $bestScore = $score;
                $bestCategory = $category;
            } elseif ($score > $secondBestScore) {
                $secondBestScore = $score;
            }
        }

        if ($bestCategory === null || $bestScore <= 0) {
            $this->log("PHP classifier: no match for \"{$input}\"");

            return null;
        }

        // Confidence = score with margin bonus (reward clear winners)
        $margin = $bestScore > 0 ? ($bestScore - $secondBestScore) / $bestScore : 0;
        $confidence = min(1.0, $bestScore * (0.65 + 0.35 * $margin));

        $this->log(sprintf(
            'PHP classifier: "%s" → %s (score=%.3f, margin=%.3f, confidence=%.3f, threshold=%.2f)',
            mb_substr($input, 0, 50),
            $bestCategory['id'] ?? '?',
            $bestScore,
            $margin,
            $confidence,
            $this->minConfidence,
        ));

        if ($confidence >= $this->minConfidence) {
            return [
                'category' => $bestCategory,
                'confidence' => round($confidence, 3),
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Scoring
    // -------------------------------------------------------------------------

    /**
     * Score how well user input matches a single category.
     *
     * Uses three signals:
     * 1. Exact phrase match against the raw input (highest weight)
     * 2. Token overlap with IDF weighting (medium weight)
     * 3. Fuzzy token matching via synonyms and morphology (lower weight)
     */
    private function scoreCategory(array $inputTokens, string $rawInput, array $category): float
    {
        $totalScore = 0.0;
        $patterns = $category['patterns'] ?? [];

        if (empty($patterns)) {
            return 0.0;
        }

        foreach ($patterns as $pattern) {
            $alternatives = array_map('trim', explode('|', $pattern));

            foreach ($alternatives as $phrase) {
                $phrase = strtolower($phrase);

                // Signal 1: Full phrase match (very high value)
                if (str_contains($rawInput, $phrase)) {
                    $totalScore += 3.0;

                    continue;
                }

                // Signal 2+3: Token-level matching with IDF weighting
                $phraseTokens = $this->tokenize($phrase);
                if (empty($phraseTokens)) {
                    continue;
                }

                $matchedWeight = 0.0;
                $totalWeight = 0.0;

                foreach ($phraseTokens as $pt) {
                    $idf = $this->idfScores[$pt] ?? 1.0;
                    $totalWeight += $idf;

                    foreach ($inputTokens as $it) {
                        if ($this->tokensMatch($it, $pt)) {
                            $matchedWeight += $idf;

                            break;
                        }
                    }
                }

                if ($totalWeight > 0) {
                    $matchRatio = $matchedWeight / $totalWeight;
                    $totalScore += $matchRatio * 1.5;
                }
            }
        }

        // Normalize by pattern count to avoid bias toward verbose categories
        $patternCount = max(1, count($patterns));

        return $totalScore / $patternCount;
    }

    // -------------------------------------------------------------------------
    // Token matching
    // -------------------------------------------------------------------------

    /**
     * Check if two tokens match (exact, synonym, or morphological prefix).
     */
    private function tokensMatch(string $a, string $b): bool
    {
        // Exact match
        if ($a === $b) {
            return true;
        }

        // Normalize via synonyms + morphology
        $normA = $this->normalize($a);
        $normB = $this->normalize($b);

        if ($normA === $normB) {
            return true;
        }

        // Prefix match: handles inflections like "creating" ↔ "create"
        // Require at least 3 chars overlap
        $shorter = mb_strlen($normA) <= mb_strlen($normB) ? $normA : $normB;
        $longer = mb_strlen($normA) <= mb_strlen($normB) ? $normB : $normA;

        if (mb_strlen($shorter) >= 3 && str_starts_with($longer, $shorter)) {
            return true;
        }

        return false;
    }

    /**
     * Normalize a word: apply synonym mapping then basic morphological reduction.
     */
    private function normalize(string $word): string
    {
        // Direct synonym match
        if (isset($this->synonymIndex[$word])) {
            return $this->synonymIndex[$word];
        }

        // Morphological normalization: strip common English suffixes
        // and check if the stem maps to a known synonym
        $stem = $this->stem($word);
        if (isset($this->synonymIndex[$stem])) {
            return $this->synonymIndex[$stem];
        }

        return $stem;
    }

    /**
     * Basic English stemmer — strips common suffixes.
     *
     * Not a full Porter stemmer, but sufficient for classification.
     * Handles: -ing, -ed, -tion, -ment, -ness, -able/-ible, -ly, -er, -es, -s
     */
    private function stem(string $word): string
    {
        if (strlen($word) <= 4) {
            return $word;
        }

        // Try suffixes longest-first to avoid partial matches
        $rules = [
            // -ation, -ition (creation → creat, definition → definit)
            ['ation', 4, ''],
            ['ition', 4, ''],
            ['ment', 4, ''],
            ['ness', 4, ''],
            ['able', 4, ''],
            ['ible', 4, ''],
            ['ical', 4, ''],
            // -ing: try restoring trailing 'e' (creating → create, running → run)
            ['ting', 4, 'te'],
            ['ring', 4, 're'],
            ['ling', 4, 'le'],
            ['ning', 5, 'n'],   // running → run (double consonant)
            ['ing', 4, ''],
            ['ied', 3, 'y'],
            ['ies', 3, 'y'],
            ['eed', 3, 'ee'],
            ['ely', 4, 'e'],
            ['ous', 4, ''],
            ['ful', 4, ''],
            ['ted', 4, 'te'],   // created → create
            ['red', 4, 're'],   // configured → configure
            ['led', 4, 'le'],   // compiled → compile
            ['ned', 4, 'ne'],   // defined → define
            ['ded', 4, 'de'],   // decoded → decode
            ['sed', 4, 'se'],   // composed → compose
            ['zed', 4, 'ze'],   // optimized → optimize
            ['ed', 3, ''],
            ['er', 3, ''],
            ['ly', 3, ''],
            ['es', 3, ''],
            ['al', 3, ''],
            ['s', 3, ''],
        ];

        foreach ($rules as [$suffix, $minLen, $replacement]) {
            if (strlen($word) >= $minLen + strlen($suffix) && str_ends_with($word, $suffix)) {
                return substr($word, 0, -strlen($suffix)) . $replacement;
            }
        }

        return $word;
    }

    // -------------------------------------------------------------------------
    // Tokenization
    // -------------------------------------------------------------------------

    /**
     * Extract content words from text, lowercased, stop words removed.
     *
     * @return string[]
     */
    private function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9\s_-]/', ' ', $text);
        $words = preg_split('/\s+/', trim($text));

        if ($words === false) {
            return [];
        }

        $stopWords = array_flip(self::STOP_WORDS);

        return array_values(array_filter($words, static function (string $w) use ($stopWords): bool {
            return strlen($w) > 1 && !isset($stopWords[$w]);
        }));
    }

    // -------------------------------------------------------------------------
    // TF-IDF
    // -------------------------------------------------------------------------

    /**
     * Compute IDF (Inverse Document Frequency) weights from the category corpus.
     *
     * Rare terms get higher IDF → they're more discriminative.
     * Common terms (appearing in many categories) get lower IDF.
     */
    private function computeIdf(array $categories): void
    {
        $docCount = count($categories);
        $termDocFreqs = [];

        foreach ($categories as $category) {
            $terms = $this->extractCategoryTerms($category);
            $uniqueTerms = array_unique($terms);

            foreach ($uniqueTerms as $term) {
                $termDocFreqs[$term] = ($termDocFreqs[$term] ?? 0) + 1;
            }
        }

        $this->idfScores = [];

        foreach ($termDocFreqs as $term => $docFreq) {
            // Smoothed IDF: log((N+1) / (df+1)) + 1
            $this->idfScores[$term] = log(($docCount + 1) / ($docFreq + 1)) + 1.0;
        }
    }

    /**
     * Extract all normalized tokens from a category's patterns.
     *
     * @return string[]
     */
    private function extractCategoryTerms(array $category): array
    {
        $text = implode(' ', $category['patterns'] ?? []);
        $text = str_replace('|', ' ', $text);
        $text .= ' ' . ($category['id'] ?? '');

        return $this->tokenize($text);
    }

    // -------------------------------------------------------------------------
    // Synonym index
    // -------------------------------------------------------------------------

    /**
     * Build the reverse synonym index: synonym → canonical form.
     *
     * Also indexes the canonical forms themselves so that normalize()
     * can map both directions.
     */
    private function buildSynonymIndex(): void
    {
        foreach (self::SYNONYMS as $canonical => $synonyms) {
            // Map canonical to itself
            $this->synonymIndex[$canonical] = $canonical;

            foreach ($synonyms as $synonym) {
                $this->synonymIndex[$synonym] = $canonical;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
