<?php

declare(strict_types=1);

namespace Tests\Conversation;

use Dalehurley\Phpbot\Conversation\ConversationHistory;
use Dalehurley\Phpbot\Conversation\ConversationLayer;
use Dalehurley\Phpbot\Conversation\ConversationTurn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConversationHistory::class)]
#[CoversClass(ConversationTurn::class)]
#[CoversClass(ConversationLayer::class)]
class ConversationTest extends TestCase
{
    public function testConversationTurnConstructor(): void
    {
        $turn = new ConversationTurn(
            userInput: 'hello',
            answer: 'hi',
            error: null,
            summary: null,
            toolCalls: [],
            fullMessages: [],
            metadata: [],
            timestamp: 1.0,
        );
        $this->assertSame('hello', $turn->userInput);
        $this->assertSame('hi', $turn->answer);
        $this->assertTrue($turn->isSuccess());
    }

    public function testConversationTurnWithSummary(): void
    {
        $turn = new ConversationTurn('hello', 'hi');
        $withSummary = $turn->withSummary('User said hello, bot replied hi');
        $this->assertSame('User said hello, bot replied hi', $withSummary->summary);
    }

    public function testConversationTurnToolSummary(): void
    {
        $turn = new ConversationTurn(
            'run ls',
            'done',
            null,
            null,
            [
                ['tool' => 'bash', 'input' => []],
                ['tool' => 'bash', 'input' => []],
                ['tool' => 'read_file', 'input' => []],
            ],
        );
        $this->assertSame('bash(2), read_file', $turn->toolSummary());
    }

    public function testConversationTurnToArray(): void
    {
        $turn = new ConversationTurn('hi', 'hello');
        $arr = $turn->toArray();
        $this->assertSame('hi', $arr['user_input']);
        $this->assertSame('hello', $arr['answer']);
    }

    public function testConversationLayerValues(): void
    {
        $this->assertSame('basic', ConversationLayer::Basic->value);
        $this->assertSame('summarized', ConversationLayer::Summarized->value);
        $this->assertSame('full', ConversationLayer::Full->value);
    }

    public function testConversationLayerDefaultMaxTurns(): void
    {
        $this->assertSame(20, ConversationLayer::Basic->defaultMaxTurns());
        $this->assertSame(10, ConversationLayer::Summarized->defaultMaxTurns());
        $this->assertSame(3, ConversationLayer::Full->defaultMaxTurns());
    }

    public function testConversationHistoryAddAndGet(): void
    {
        $history = new ConversationHistory(ConversationLayer::Basic);
        $this->assertTrue($history->isEmpty());
        $this->assertSame(0, $history->getTurnCount());

        $turn = new ConversationTurn('hi', 'hello');
        $history->addTurn($turn);
        $this->assertFalse($history->isEmpty());
        $this->assertSame(1, $history->getTurnCount());
        $this->assertSame($turn, $history->getTurn(1));
    }

    public function testConversationHistoryBuildContextBlock(): void
    {
        $history = new ConversationHistory(ConversationLayer::Basic);
        $this->assertSame('', $history->buildContextBlock());

        $history->addTurn(new ConversationTurn('create file', 'Created file.txt'));
        $block = $history->buildContextBlock();
        $this->assertStringContainsString('Previous Conversation', $block);
        $this->assertStringContainsString('create file', $block);
    }

    public function testConversationHistoryClear(): void
    {
        $history = new ConversationHistory(ConversationLayer::Basic);
        $history->addTurn(new ConversationTurn('hi', 'hello'));
        $history->clear();
        $this->assertTrue($history->isEmpty());
    }

    public function testConversationHistorySetActiveLayer(): void
    {
        $history = new ConversationHistory(ConversationLayer::Basic);
        $history->setActiveLayer(ConversationLayer::Full);
        $this->assertSame(ConversationLayer::Full, $history->getActiveLayer());
    }
}
