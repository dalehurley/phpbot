<?php

declare(strict_types=1);

namespace Tests\Stats;

use Dalehurley\Phpbot\Stats\TokenLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TokenLedger::class)]
class TokenLedgerTest extends TestCase
{
    public function testConstructorAndRecording(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('anthropic', 'agent', 1000, 500);
        $ledger->record('apple_fm', 'classification', 200, 100);

        $this->assertTrue($ledger->hasEntries());
        $this->assertCount(2, $ledger->toArray()['entries']);
    }

    public function testGetTotalByProvider(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('anthropic', 'agent', 1000, 500);
        $ledger->record('anthropic', 'classification', 200, 100);
        $ledger->record('groq', 'classification', 50, 25);

        $byProvider = $ledger->getTotalByProvider();

        $this->assertArrayHasKey('anthropic', $byProvider);
        $this->assertSame(1200, $byProvider['anthropic']['input']);
        $this->assertSame(600, $byProvider['anthropic']['output']);
        $this->assertSame(1800, $byProvider['anthropic']['total']);
        $this->assertSame(2, $byProvider['anthropic']['calls']);

        $this->assertArrayHasKey('groq', $byProvider);
        $this->assertSame(50, $byProvider['groq']['input']);
        $this->assertSame(25, $byProvider['groq']['output']);
    }

    public function testGetTotalByPurpose(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('anthropic', 'agent', 1000, 500);
        $ledger->record('apple_fm', 'summarization', 500, 200);
        $ledger->record('anthropic', 'agent', 300, 150);

        $byPurpose = $ledger->getTotalByPurpose();

        $this->assertArrayHasKey('agent', $byPurpose);
        $this->assertSame(1300, $byPurpose['agent']['input']);
        $this->assertSame(650, $byPurpose['agent']['output']);
        $this->assertSame(2, $byPurpose['agent']['calls']);

        $this->assertArrayHasKey('summarization', $byPurpose);
    }

    public function testGetSavings(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('anthropic', 'summarization', 100, 50, 0.0, 400);
        $ledger->record('anthropic', 'summarization', 100, 50, 0.0, 200);

        $savings = $ledger->getSavings();

        $this->assertSame(2, $savings['calls']);
        $this->assertSame(600, $savings['bytes_saved']);
        $this->assertGreaterThanOrEqual(100, $savings['estimated_tokens_saved']);
        $this->assertIsFloat($savings['estimated_cost_saved']);
    }

    public function testGetSavingsWithNoBytesSaved(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('anthropic', 'agent', 1000, 500);

        $savings = $ledger->getSavings();
        $this->assertSame(0, $savings['calls']);
        $this->assertSame(0, $savings['bytes_saved']);
    }

    public function testGetOverallTotals(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('anthropic', 'agent', 1000, 500);
        $ledger->record('groq', 'classification', 200, 100);

        $totals = $ledger->getOverallTotals();

        $this->assertSame(1200, $totals['input']);
        $this->assertSame(600, $totals['output']);
        $this->assertSame(1800, $totals['total']);
        $this->assertSame(2, $totals['calls']);
        $this->assertIsFloat($totals['cost']);
    }

    public function testHasEntriesWhenEmpty(): void
    {
        $ledger = new TokenLedger();
        $this->assertFalse($ledger->hasEntries());
    }

    public function testFormatReport(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('anthropic', 'agent', 1000, 500);

        $report = $ledger->formatReport();
        $this->assertIsString($report);
        $this->assertNotEmpty($report);
        $this->assertStringContainsString('Claude', $report);
        $this->assertStringContainsString('tokens', $report);
    }

    public function testFormatCompact(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('anthropic', 'agent', 1000, 500);

        $compact = $ledger->formatCompact();
        $this->assertIsString($compact);
        $this->assertStringContainsString('1,500 tokens', $compact);
        $this->assertStringContainsString('in', $compact);
        $this->assertStringContainsString('out', $compact);
    }

    public function testToArray(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('php_native', 'classification', 0, 0);

        $data = $ledger->toArray();
        $this->assertArrayHasKey('entries', $data);
        $this->assertArrayHasKey('by_provider', $data);
        $this->assertArrayHasKey('by_purpose', $data);
        $this->assertArrayHasKey('savings', $data);
        $this->assertArrayHasKey('totals', $data);
    }

    public function testPricingOverrides(): void
    {
        $ledger = new TokenLedger('claude-sonnet', [
            'anthropic_sonnet' => [5.0, 20.0],
        ]);
        $ledger->record('anthropic', 'agent', 1_000_000, 500_000);

        $totals = $ledger->getOverallTotals();
        $this->assertGreaterThan(0, $totals['cost']);
    }

    public function testExplicitCostOverride(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('anthropic', 'agent', 1000, 500, 0.05);

        $totals = $ledger->getOverallTotals();
        $this->assertSame(0.05, $totals['cost']);
    }
}