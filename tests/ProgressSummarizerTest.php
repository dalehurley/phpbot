<?php

declare(strict_types=1);

namespace Tests;

use Dalehurley\Phpbot\Apple\SmallModelClient;
use Dalehurley\Phpbot\ProgressSummarizer;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProgressSummarizer::class)]
class ProgressSummarizerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSummarizeBeforeWithAppleFM(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $appleFM->shouldReceive('isAvailable')->andReturn(true);
        $appleFM->shouldReceive('call')->andReturn('The plan is to create a file.');

        $summarizer = new ProgressSummarizer(
            fn() => null,
            'claude-sonnet',
            'claude-haiku',
            $appleFM,
        );
        $result = $summarizer->summarizeBefore('create a file', ['task_type' => 'file']);
        $this->assertSame('The plan is to create a file.', $result);
    }

    public function testSummarizeAfterWithAppleFM(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $appleFM->shouldReceive('isAvailable')->andReturn(true);
        $appleFM->shouldReceive('call')->andReturn('Successfully created the file.');
        $result = Mockery::mock();
        $result->shouldReceive('isSuccess')->andReturn(true);
        $result->shouldReceive('getAnswer')->andReturn('Done');
        $result->shouldReceive('getError')->andReturn(null);
        $result->shouldReceive('getIterations')->andReturn(1);
        $result->shouldReceive('getToolCalls')->andReturn([]);

        $summarizer = new ProgressSummarizer(
            fn() => null,
            'claude-sonnet',
            'claude-haiku',
            $appleFM,
        );
        $out = $summarizer->summarizeAfter('create file', [], $result);
        $this->assertSame('Successfully created the file.', $out);
    }

    public function testSummarizeIterationWithAppleFM(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $appleFM->shouldReceive('isAvailable')->andReturn(true);
        $appleFM->shouldReceive('call')->andReturn('Running ls command.');
        $summarizer = new ProgressSummarizer(
            fn() => null,
            'claude-sonnet',
            'claude-haiku',
            $appleFM,
        );
        $out = $summarizer->summarizeIteration('I will run ls -la to list files.');
        $this->assertSame('Running ls command.', $out);
    }
}
