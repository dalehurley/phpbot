<?php

declare(strict_types=1);

namespace Tests;

use Dalehurley\Phpbot\StaleLoopGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StaleLoopGuard::class)]
class StaleLoopGuardTest extends TestCase
{
    public function testSuccessfulCallsDoNotThrow(): void
    {
        $guard = new StaleLoopGuard();
        $guard->record('bash', ['command' => 'echo hello'], false);
        $guard->record('bash', ['command' => 'ls'], false);
        $guard->record('write_file', ['path' => '/tmp/x', 'content' => 'x'], false);

        $this->assertSame(3, $guard->getTotalCalls());
        $this->assertSame(0, $guard->getConsecutiveErrors());
        $this->assertSame(0, $guard->getConsecutiveEmpty());
    }

    public function testConsecutiveErrorsThreshold(): void
    {
        $guard = new StaleLoopGuard(maxConsecutiveErrors: 3);

        $guard->record('bash', ['command' => 'fail1'], true);
        $guard->record('bash', ['command' => 'fail2'], true);
        $this->assertSame(2, $guard->getConsecutiveErrors());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consecutive tool errors');
        $guard->record('bash', ['command' => 'fail3'], true);
    }

    public function testConsecutiveErrorsResetOnSuccess(): void
    {
        $guard = new StaleLoopGuard(maxConsecutiveErrors: 5);
        $guard->record('bash', ['command' => 'fail'], true);
        $guard->record('bash', ['command' => 'fail'], true);
        $guard->record('bash', ['command' => 'ok'], false);

        $this->assertSame(0, $guard->getConsecutiveErrors());
        $this->assertSame(3, $guard->getTotalCalls());
    }

    public function testConsecutiveEmptyThreshold(): void
    {
        $guard = new StaleLoopGuard(maxConsecutiveEmpty: 2);

        $guard->record('bash', ['command' => ''], false);
        $this->assertSame(1, $guard->getConsecutiveEmpty());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consecutive empty tool calls');
        $guard->record('bash', ['command' => ''], false); // 2nd empty - throws
    }

    public function testEmptyBashCommand(): void
    {
        $guard = new StaleLoopGuard(maxConsecutiveEmpty: 2);

        $guard->record('bash', ['command' => '   '], false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consecutive empty tool calls');
        $guard->record('bash', ['command' => ''], false);
    }

    public function testEmptyWriteFile(): void
    {
        $guard = new StaleLoopGuard(maxConsecutiveEmpty: 2);

        $guard->record('write_file', ['path' => '', 'content' => 'x'], false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consecutive empty tool calls');
        $guard->record('write_file', ['path' => 'x', 'content' => ''], false);
    }

    public function testRepeatedIdenticalCalls(): void
    {
        $guard = new StaleLoopGuard(maxRepeatedIdentical: 3);

        $guard->record('bash', ['command' => 'same'], false);
        $guard->record('bash', ['command' => 'same'], false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('identical consecutive tool calls');
        $guard->record('bash', ['command' => 'same'], false); // 3rd identical - throws
    }

    public function testRepeatedIdenticalDifferentInputResets(): void
    {
        $guard = new StaleLoopGuard(maxRepeatedIdentical: 3);

        $guard->record('bash', ['command' => 'same'], false);
        $guard->record('bash', ['command' => 'same'], false);
        $guard->record('bash', ['command' => 'different'], false);
        $guard->record('bash', ['command' => 'same'], false);
        $guard->record('bash', ['command' => 'same'], false);

        $this->assertSame(5, $guard->getTotalCalls());
    }

    public function testEmptyAlsoCountsAsError(): void
    {
        $guard = new StaleLoopGuard(maxConsecutiveErrors: 3);

        $guard->record('bash', ['command' => ''], false);
        $guard->record('bash', ['command' => ''], false);

        $this->expectException(\RuntimeException::class);
        $guard->record('bash', ['command' => ''], false);
    }

    public function testCustomThresholds(): void
    {
        $guard = new StaleLoopGuard(
            maxConsecutiveErrors: 10,
            maxConsecutiveEmpty: 5,
            maxRepeatedIdentical: 6
        );

        for ($i = 0; $i < 5; $i++) {
            $guard->record('bash', ['command' => 'x'], false);
        }
        $this->assertSame(5, $guard->getTotalCalls());
    }

    public function testNonBashNonWriteFileEmptyInput(): void
    {
        $guard = new StaleLoopGuard(maxConsecutiveEmpty: 2);

        $guard->record('unknown_tool', [], false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consecutive empty tool calls');
        $guard->record('another_tool', [], false);
    }

    public function testWriteFileWithPathAndContentIsNotEmpty(): void
    {
        $guard = new StaleLoopGuard();
        $guard->record('write_file', ['path' => '/tmp/x', 'content' => 'hello'], false);
        $this->assertSame(0, $guard->getConsecutiveEmpty());
    }
}