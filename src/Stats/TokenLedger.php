<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Stats;

/**
 * Multi-provider token usage ledger.
 *
 * Tracks every LLM call across all providers and purposes:
 * - Anthropic (agent execution, classification, progress summaries)
 * - Apple FM (classification, summarization, progress summaries)
 * - Groq, Gemini, Ollama, etc. (classification)
 * - PHP native (0-token classification)
 *
 * Calculates costs, savings from summarization, and provides
 * formatted reports for CLI display.
 */
class TokenLedger
{
    /**
     * Default per-million-token pricing: [input, output].
     * Apple FM / PHP native / Ollama / LM Studio = free.
     * These can be overridden via config/env at construction time.
     */
    private const DEFAULT_PRICING = [
        'anthropic_haiku'  => [1.00, 5.00],
        'anthropic_sonnet' => [3.00, 15.00],
        'anthropic_opus'   => [5.00, 25.00],
        'groq'             => [0.59, 0.79],
        'gemini'           => [0.50, 3.00],
        'apple_fm'         => [0.0, 0.0],
        'mlx'              => [0.0, 0.0],
        'ollama'           => [0.0, 0.0],
        'lmstudio'         => [0.0, 0.0],
        'php_native'       => [0.0, 0.0],
    ];

    /** @var array<string, array{0: float, 1: float}> Resolved pricing (defaults + overrides). */
    private array $pricing;

    /** @var array<int, array{provider: string, purpose: string, input: int, output: int, cost: float, bytes_saved: int, timestamp: float}> */
    private array $entries = [];

    /** The Anthropic model name for accurate pricing. */
    private string $anthropicModel = 'claude-sonnet-4-5';

    /**
     * @param string                              $anthropicModel  Claude model name for tier detection.
     * @param array<string, array{0: float, 1: float}> $pricingOverrides  Per-provider [input, output] overrides.
     */
    public function __construct(string $anthropicModel = 'claude-sonnet-4-5', array $pricingOverrides = [])
    {
        $this->anthropicModel = $anthropicModel;
        $this->pricing = array_merge(self::DEFAULT_PRICING, $pricingOverrides);
    }

    // -------------------------------------------------------------------------
    // Recording
    // -------------------------------------------------------------------------

    /**
     * Record a single LLM call.
     *
     * @param string $provider   Provider name (anthropic, apple_fm, groq, gemini, php_native, etc.)
     * @param string $purpose    Purpose (agent, classification, summarization, progress, context_compaction)
     * @param int    $inputTokens  Input tokens consumed (or estimated)
     * @param int    $outputTokens Output tokens consumed (or estimated)
     * @param float  $costUsd    Explicit cost override (0.0 = auto-calculate from pricing table)
     * @param int    $bytesSaved Original bytes minus summarized bytes (for summarization calls)
     */
    public function record(
        string $provider,
        string $purpose,
        int $inputTokens,
        int $outputTokens,
        float $costUsd = 0.0,
        int $bytesSaved = 0,
    ): void {
        $cost = $costUsd > 0 ? $costUsd : $this->calculateCost($provider, $inputTokens, $outputTokens);

        $this->entries[] = [
            'provider' => $provider,
            'purpose' => $purpose,
            'input' => $inputTokens,
            'output' => $outputTokens,
            'cost' => $cost,
            'bytes_saved' => $bytesSaved,
            'timestamp' => microtime(true),
        ];
    }

    // -------------------------------------------------------------------------
    // Aggregation
    // -------------------------------------------------------------------------

    /**
     * Get totals grouped by provider.
     *
     * @return array<string, array{input: int, output: int, total: int, cost: float, calls: int}>
     */
    public function getTotalByProvider(): array
    {
        $totals = [];

        foreach ($this->entries as $entry) {
            $provider = $entry['provider'];

            if (!isset($totals[$provider])) {
                $totals[$provider] = ['input' => 0, 'output' => 0, 'total' => 0, 'cost' => 0.0, 'calls' => 0];
            }

            $totals[$provider]['input'] += $entry['input'];
            $totals[$provider]['output'] += $entry['output'];
            $totals[$provider]['total'] += $entry['input'] + $entry['output'];
            $totals[$provider]['cost'] += $entry['cost'];
            $totals[$provider]['calls']++;
        }

        return $totals;
    }

    /**
     * Get totals grouped by purpose.
     *
     * @return array<string, array{input: int, output: int, total: int, cost: float, calls: int}>
     */
    public function getTotalByPurpose(): array
    {
        $totals = [];

        foreach ($this->entries as $entry) {
            $purpose = $entry['purpose'];

            if (!isset($totals[$purpose])) {
                $totals[$purpose] = ['input' => 0, 'output' => 0, 'total' => 0, 'cost' => 0.0, 'calls' => 0];
            }

            $totals[$purpose]['input'] += $entry['input'];
            $totals[$purpose]['output'] += $entry['output'];
            $totals[$purpose]['total'] += $entry['input'] + $entry['output'];
            $totals[$purpose]['cost'] += $entry['cost'];
            $totals[$purpose]['calls']++;
        }

        return $totals;
    }

    /**
     * Get summarization savings.
     *
     * @return array{calls: int, bytes_saved: int, estimated_tokens_saved: int, estimated_cost_saved: float}
     */
    public function getSavings(): array
    {
        $calls = 0;
        $bytesSaved = 0;

        foreach ($this->entries as $entry) {
            if ($entry['bytes_saved'] > 0) {
                $calls++;
                $bytesSaved += $entry['bytes_saved'];
            }
        }

        // Estimate tokens saved: bytes_saved / 4 (rough chars-to-tokens ratio)
        $tokensSaved = (int) ceil($bytesSaved / 4);

        // Estimate cost saved using the Anthropic model's input pricing
        $pricing = $this->getAnthropicPricing();
        $costSaved = ($tokensSaved / 1_000_000) * $pricing[0];

        return [
            'calls' => $calls,
            'bytes_saved' => $bytesSaved,
            'estimated_tokens_saved' => $tokensSaved,
            'estimated_cost_saved' => $costSaved,
        ];
    }

    /**
     * Get overall totals.
     *
     * @return array{input: int, output: int, total: int, cost: float, calls: int}
     */
    public function getOverallTotals(): array
    {
        $totals = ['input' => 0, 'output' => 0, 'total' => 0, 'cost' => 0.0, 'calls' => 0];

        foreach ($this->entries as $entry) {
            $totals['input'] += $entry['input'];
            $totals['output'] += $entry['output'];
            $totals['total'] += $entry['input'] + $entry['output'];
            $totals['cost'] += $entry['cost'];
            $totals['calls']++;
        }

        return $totals;
    }

    // -------------------------------------------------------------------------
    // Formatting
    // -------------------------------------------------------------------------

    /**
     * Format a multi-line report for CLI display.
     */
    public function formatReport(): string
    {
        $byProvider = $this->getTotalByProvider();
        $savings = $this->getSavings();
        $lines = [];

        // Provider breakdown
        foreach ($byProvider as $provider => $data) {
            $label = $this->formatProviderLabel($provider);
            $tokenStr = number_format($data['total']);
            $inStr = number_format($data['input']);
            $outStr = number_format($data['output']);

            if ($data['cost'] > 0) {
                $costStr = '$' . number_format($data['cost'], 4);
                $lines[] = "  {$label}: {$tokenStr} tokens ({$inStr} in / {$outStr} out)  ~{$costStr}";
            } else {
                $callStr = $data['calls'] === 1 ? '1 call' : "{$data['calls']} calls";
                $lines[] = "  {$label}: {$callStr}, ~{$tokenStr} est. tokens  \$0.0000";
            }
        }

        // Savings
        if ($savings['calls'] > 0) {
            $savedTokens = number_format($savings['estimated_tokens_saved']);
            $savedCost = '$' . number_format($savings['estimated_cost_saved'], 4);
            $lines[] = "  Savings: ~{$savedTokens} tokens saved via summarization (~{$savedCost})";
        }

        return implode("\n", $lines);
    }

    /**
     * Format a compact one-line summary.
     */
    public function formatCompact(): string
    {
        $totals = $this->getOverallTotals();
        $savings = $this->getSavings();

        $parts = [];
        $parts[] = number_format($totals['total']) . ' tokens';
        $parts[] = '(' . number_format($totals['input']) . ' in / ' . number_format($totals['output']) . ' out)';

        if ($savings['estimated_tokens_saved'] > 0) {
            $parts[] = 'saved ~' . number_format($savings['estimated_tokens_saved']);
        }

        return implode(' ', $parts);
    }

    // -------------------------------------------------------------------------
    // Serialization
    // -------------------------------------------------------------------------

    /**
     * Get the full ledger data for JSON/API consumers.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entries' => $this->entries,
            'by_provider' => $this->getTotalByProvider(),
            'by_purpose' => $this->getTotalByPurpose(),
            'savings' => $this->getSavings(),
            'totals' => $this->getOverallTotals(),
        ];
    }

    /**
     * Check if the ledger has any entries.
     */
    public function hasEntries(): bool
    {
        return !empty($this->entries);
    }

    // -------------------------------------------------------------------------
    // Pricing
    // -------------------------------------------------------------------------

    /**
     * Calculate cost for a provider call.
     */
    private function calculateCost(string $provider, int $inputTokens, int $outputTokens): float
    {
        $pricing = match ($provider) {
            'anthropic' => $this->getAnthropicPricing(),
            default => $this->pricing[$provider] ?? [0.0, 0.0],
        };

        $inputCost = ($inputTokens / 1_000_000) * $pricing[0];
        $outputCost = ($outputTokens / 1_000_000) * $pricing[1];

        return $inputCost + $outputCost;
    }

    /**
     * Get Anthropic pricing based on the configured model.
     *
     * @return array{0: float, 1: float} [input_per_million, output_per_million]
     */
    private function getAnthropicPricing(): array
    {
        return match (true) {
            str_contains($this->anthropicModel, 'haiku') => $this->pricing['anthropic_haiku'],
            str_contains($this->anthropicModel, 'opus') => $this->pricing['anthropic_opus'],
            default => $this->pricing['anthropic_sonnet'],
        };
    }

    /**
     * Format a provider name for display.
     */
    private function formatProviderLabel(string $provider): string
    {
        return match ($provider) {
            'anthropic' => 'Claude (' . $this->formatModelName() . ')',
            'apple_fm' => 'Apple FM (on-device)',
            'mlx' => 'MLX (local)',
            'ollama' => 'Ollama (local)',
            'lmstudio' => 'LM Studio (local)',
            'groq' => 'Groq (cloud)',
            'gemini' => 'Gemini (cloud)',
            'php_native' => 'PHP native',
            default => $provider,
        };
    }

    /**
     * Format the Anthropic model name for display.
     */
    private function formatModelName(): string
    {
        return match (true) {
            str_contains($this->anthropicModel, 'haiku') => 'Haiku',
            str_contains($this->anthropicModel, 'opus') => 'Opus',
            default => 'Sonnet',
        };
    }
}
