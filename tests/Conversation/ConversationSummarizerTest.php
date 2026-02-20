<?php

declare(strict_types=1);

namespace Tests\Conversation;

use Dalehurley\Phpbot\Apple\SmallModelClient;
use Dalehurley\Phpbot\BotResult;
use Dalehurley\Phpbot\Conversation\ConversationSummarizer;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversationSummarizer::class)]
class ConversationSummarizerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSummarizeWithMockClient(): void
    {
        $smallModel = Mockery::mock(SmallModelClient::class);
        $smallModel->shouldReceive('call')->andReturn('Executed bash to list files. Created output.txt.');

        $summarizer = new ConversationSummarizer($smallModel);
        $result = Mockery::mock(BotResult::class);
        $result->shouldReceive('isSuccess')->andReturn(true);
        $result->shouldReceive('getAnswer')->andReturn('Done');
        $result->shouldReceive('getError')->andReturn(null);
        $result->shouldReceive('getIterations')->andReturn(2);
        $result->shouldReceive('getToolCalls')->andReturn([
            ['tool' => 'bash', 'input' => ['command' => 'ls']],
            ['tool' => 'write_file', 'input' => ['path' => 'output.txt']],
        ]);
        $result->shouldReceive('getTokenUsage')->andReturn(['input' => 100, 'output' => 50, 'total' => 150]);
        $result->shouldReceive('getAnalysis')->andReturn(['complexity' => 'simple']);

        $summary = $summarizer->summarize('list files and create output', $result);
        $this->assertSame('Executed bash to list files. Created output.txt.', $summary);
    }

    public function testSummarizeReturnsFallbackOnException(): void
    {
        $smallModel = Mockery::mock(SmallModelClient::class);
        $smallModel->shouldReceive('call')->andThrow(new \RuntimeException('API error'));

        $summarizer = new ConversationSummarizer($smallModel);
        $result = Mockery::mock(BotResult::class);
        $result->shouldReceive('isSuccess')->andReturn(true);
        $result->shouldReceive('getError')->andReturn(null);
        $result->shouldReceive('getIterations')->andReturn(1);
        $result->shouldReceive('getToolCalls')->andReturn([]);
        $result->shouldReceive('getTokenUsage')->andReturn([]);
        $result->shouldReceive('getAnalysis')->andReturn([]);
        $result->shouldReceive('getAnswer')->andReturn('Done');

        $summary = $summarizer->summarize('task', $result);
        $this->assertStringContainsString('Completed successfully', $summary);
    }
}
