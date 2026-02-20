<?php

declare(strict_types=1);

namespace Tests\Apple;

use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Apple\AppleScriptRunner;
use Dalehurley\Phpbot\Apple\HaikuModelClient;
use Dalehurley\Phpbot\Apple\SmallModelClient;
use Dalehurley\Phpbot\Apple\ToolResultSummarizer;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppleScriptRunner::class)]
#[CoversClass(ToolResultSummarizer::class)]
#[CoversClass(HaikuModelClient::class)]
class AppleClassesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testAppleScriptRunnerEscapeAppleScript(): void
    {
        $runner = new AppleScriptRunner();
        $this->assertSame('Hello \\"world\\"', $runner->escapeAppleScript('Hello "world"'));
        $this->assertSame('path\\\\to\\\\file', $runner->escapeAppleScript('path\to\file'));
    }

    public function testAppleScriptRunnerIsPermissionError(): void
    {
        $runner = new AppleScriptRunner();
        $this->assertTrue($runner->isPermissionError(['stdout' => '', 'stderr' => 'not allowed assistive access', 'exit_code' => 1]));
        $this->assertTrue($runner->isPermissionError(['stdout' => 'execution error: Not authorized', 'stderr' => '', 'exit_code' => 1]));
        $this->assertFalse($runner->isPermissionError(['stdout' => 'ok', 'stderr' => '', 'exit_code' => 0]));
    }

    public function testToolResultSummarizerShouldSummarize(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $summarizer = new ToolResultSummarizer($appleFM);

        $largeResult = ToolResult::success(str_repeat('x', 1000));
        $this->assertTrue($summarizer->shouldSummarize('bash', $largeResult));

        $smallResult = ToolResult::success('ok');
        $this->assertFalse($summarizer->shouldSummarize('bash', $smallResult));

        $errorResult = ToolResult::error('failed');
        $this->assertFalse($summarizer->shouldSummarize('bash', $errorResult));

        $passThrough = ToolResult::success(str_repeat('x', 1000));
        $this->assertFalse($summarizer->shouldSummarize('search_capabilities', $passThrough));
    }

    public function testToolResultSummarizerSummarizeLightCompress(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $appleFM->shouldNotReceive('summarize');
        $summarizer = new ToolResultSummarizer($appleFM, null, [
            'skip_threshold' => 100,
            'summarize_threshold' => 1000,
        ]);

        $content = str_repeat('x', 5) . "\n\n\n\n" . str_repeat('y', 5);
        $result = ToolResult::success($content);
        $out = $summarizer->summarize('bash', [], $result);
        $this->assertInstanceOf(ToolResult::class, $out);
    }

    public function testToolResultSummarizerSummarizeAboveThreshold(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $appleFM->shouldReceive('summarize')
            ->andReturn('Summary of output');

        $summarizer = new ToolResultSummarizer($appleFM, null, [
            'skip_threshold' => 50,
            'summarize_threshold' => 100,
        ]);

        $longStdout = str_repeat('x', 500);
        $content = json_encode([
            'stdout' => $longStdout,
            'exit_code' => 0,
            'command' => 'ls -la',
        ]);
        $result = ToolResult::success($content);
        $out = $summarizer->summarize('bash', ['command' => 'ls -la'], $result);
        $this->assertInstanceOf(ToolResult::class, $out);
        $this->assertLessThan(strlen($content), strlen($out->getContent()));
    }

    public function testHaikuModelClientIsAvailable(): void
    {
        $client = new HaikuModelClient(fn() => null, 'claude-haiku-4-5');
        $this->assertTrue($client->isAvailable());
    }

    public function testHaikuModelClientSetLogger(): void
    {
        $client = new HaikuModelClient(fn() => null, 'claude-haiku-4-5');
        $client->setLogger(fn(string $m) => null);
        $this->assertTrue($client->isAvailable());
    }

    public function testHaikuModelClientClassifyThrowsWhenFactoryThrows(): void
    {
        $client = new HaikuModelClient(
            fn() => throw new \RuntimeException('API key missing'),
            'claude-haiku-4-5',
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API key missing');
        $client->classify('test prompt', 256);
    }

    public function testHaikuModelClientSummarizeThrowsWhenFactoryThrows(): void
    {
        $client = new HaikuModelClient(
            fn() => throw new \RuntimeException('No client'),
            'claude-haiku-4-5',
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No client');
        $client->summarize('content', 'context', 128);
    }
}
