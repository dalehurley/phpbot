<?php

declare(strict_types=1);

namespace Tests\DryRun;

use Dalehurley\Phpbot\DryRun\DryRunContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DryRunContext::class)]
class DryRunContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DryRunContext::deactivate();
    }

    protected function tearDown(): void
    {
        DryRunContext::deactivate();
        parent::tearDown();
    }

    public function testIsActiveReturnsFalseWhenNotActivated(): void
    {
        DryRunContext::deactivate();
        $this->assertFalse(DryRunContext::isActive());
    }

    public function testActivateSetsActive(): void
    {
        DryRunContext::activate();
        $this->assertTrue(DryRunContext::isActive());
        DryRunContext::deactivate();
    }

    public function testDeactivateSetsInactive(): void
    {
        DryRunContext::activate();
        DryRunContext::deactivate();
        $this->assertFalse(DryRunContext::isActive());
    }

    public function testRecordAndGetLog(): void
    {
        DryRunContext::activate();

        DryRunContext::record('write_file', 'create', ['path' => '/tmp/test.txt', 'size' => 100]);
        DryRunContext::record('bash', 'execute', ['command' => 'ls -la']);

        $log = DryRunContext::getLog();

        $this->assertCount(2, $log);
        $this->assertSame('write_file', $log[0]['tool']);
        $this->assertSame('create', $log[0]['action']);
        $this->assertSame(['path' => '/tmp/test.txt', 'size' => 100], $log[0]['details']);

        $this->assertSame('bash', $log[1]['tool']);
        $this->assertSame('execute', $log[1]['action']);
        $this->assertSame(['command' => 'ls -la'], $log[1]['details']);

        DryRunContext::deactivate();
    }

    public function testActivateClearsLog(): void
    {
        DryRunContext::activate();
        DryRunContext::record('bash', 'run', []);
        $this->assertCount(1, DryRunContext::getLog());

        DryRunContext::deactivate();
        DryRunContext::activate();
        $this->assertCount(0, DryRunContext::getLog());
        DryRunContext::deactivate();
    }

    public function testFormatPlanWithEmptyLog(): void
    {
        DryRunContext::activate();
        $plan = DryRunContext::formatPlan();
        $this->assertStringContainsString('No actions would be taken', $plan);
        DryRunContext::deactivate();
    }

    public function testFormatPlanWithEntries(): void
    {
        DryRunContext::activate();
        DryRunContext::record('write_file', 'create', ['path' => 'foo.txt', 'content' => 'hello']);
        DryRunContext::record('bash', 'run', ['command' => 'echo test']);

        $plan = DryRunContext::formatPlan();

        $this->assertStringContainsString('2 actions', $plan);
        $this->assertStringContainsString('[write_file] create', $plan);
        $this->assertStringContainsString('[bash] run', $plan);
        $this->assertStringContainsString('path:', $plan);
        $this->assertStringContainsString('content:', $plan);
        $this->assertStringContainsString('command:', $plan);

        DryRunContext::deactivate();
    }

    public function testFormatPlanTruncatesLongValues(): void
    {
        DryRunContext::activate();
        $longContent = str_repeat('x', 100);
        DryRunContext::record('write_file', 'create', ['content' => $longContent]);

        $plan = DryRunContext::formatPlan();
        $this->assertStringContainsString('…', $plan);
        $this->assertStringNotContainsString($longContent, $plan);

        DryRunContext::deactivate();
    }

    public function testRecordWithComplexDetails(): void
    {
        DryRunContext::activate();
        DryRunContext::record('edit_file', 'patch', [
            'path' => 'config.php',
            'changes' => ['key' => 'value'],
            'count' => 42,
        ]);

        $log = DryRunContext::getLog();
        $this->assertCount(1, $log);
        $this->assertIsArray($log[0]['details']['changes']);
        $this->assertSame(42, $log[0]['details']['count']);

        DryRunContext::deactivate();
    }
}