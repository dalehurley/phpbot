<?php

declare(strict_types=1);

namespace Tests;

use Dalehurley\Phpbot\TaskAnalyzer;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskAnalyzer::class)]
class TaskAnalyzerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testConstructor(): void
    {
        $clientFactory = fn() => null;
        $analyzer = new TaskAnalyzer($clientFactory, 'claude-sonnet-4-5');
        $this->assertInstanceOf(TaskAnalyzer::class, $analyzer);
    }

    public function testAnalyzePropagatesException(): void
    {
        $analyzer = new TaskAnalyzer(
            fn() => throw new \RuntimeException('Client failed'),
            'claude-sonnet-4-5',
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Client failed');
        $analyzer->analyze('test');
    }
}
