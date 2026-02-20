<?php

declare(strict_types=1);

namespace Tests;

use Dalehurley\Phpbot\BotResult;
use Dalehurley\Phpbot\Stats\TokenLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BotResult::class)]
class BotResultTest extends TestCase
{
    public function testConstructorAndGetters(): void
    {
        $result = new BotResult(
            success: true,
            answer: 'Done!',
            error: null,
            iterations: 3,
            toolCalls: ['bash', 'read_file'],
            tokenUsage: ['input' => 100, 'output' => 50],
            analysis: ['complexity' => 'medium']
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('Done!', $result->getAnswer());
        $this->assertNull($result->getError());
        $this->assertSame(3, $result->getIterations());
        $this->assertSame(['bash', 'read_file'], $result->getToolCalls());
        $this->assertSame(['input' => 100, 'output' => 50], $result->getTokenUsage());
        $this->assertSame(['complexity' => 'medium'], $result->getAnalysis());
        $this->assertNull($result->getTokenLedger());
        $this->assertSame([], $result->getRawMessages());
        $this->assertSame([], $result->getCreatedFiles());
    }

    public function testFailureResult(): void
    {
        $result = new BotResult(
            success: false,
            answer: null,
            error: 'Something went wrong',
            iterations: 1,
            toolCalls: [],
            tokenUsage: [],
            analysis: []
        );

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getAnswer());
        $this->assertSame('Something went wrong', $result->getError());
    }

    public function testWithTokenLedger(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('anthropic', 'agent', 1000, 500);

        $result = new BotResult(
            success: true,
            answer: 'OK',
            error: null,
            iterations: 1,
            toolCalls: [],
            tokenUsage: [],
            analysis: [],
            tokenLedger: $ledger
        );

        $this->assertNotNull($result->getTokenLedger());
        $this->assertInstanceOf(TokenLedger::class, $result->getTokenLedger());
    }

    public function testWithRawMessages(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
        ];

        $result = new BotResult(
            success: true,
            answer: 'OK',
            error: null,
            iterations: 1,
            toolCalls: [],
            tokenUsage: [],
            analysis: [],
            rawMessages: $messages
        );

        $this->assertSame($messages, $result->getRawMessages());
    }

    public function testWithCreatedFiles(): void
    {
        $createdFiles = ['/tmp/foo.txt', '/tmp/bar.sh'];

        $result = new BotResult(
            success: true,
            answer: 'OK',
            error: null,
            iterations: 2,
            toolCalls: [],
            tokenUsage: [],
            analysis: [],
            createdFiles: $createdFiles
        );

        $this->assertSame($createdFiles, $result->getCreatedFiles());
    }

    public function testToArray(): void
    {
        $result = new BotResult(
            success: true,
            answer: 'Result',
            error: null,
            iterations: 2,
            toolCalls: ['bash'],
            tokenUsage: ['input' => 200],
            analysis: ['task_type' => 'general'],
            createdFiles: ['file.txt']
        );

        $data = $result->toArray();

        $this->assertTrue($data['success']);
        $this->assertSame('Result', $data['answer']);
        $this->assertSame(2, $data['iterations']);
        $this->assertSame(['bash'], $data['tool_calls']);
        $this->assertSame(['input' => 200], $data['token_usage']);
        $this->assertSame(['task_type' => 'general'], $data['analysis']);
        $this->assertSame(['file.txt'], $data['created_files']);
        $this->assertArrayNotHasKey('token_ledger', $data);
    }

    public function testToArrayIncludesTokenLedgerWhenPresent(): void
    {
        $ledger = new TokenLedger();
        $ledger->record('anthropic', 'agent', 100, 50);

        $result = new BotResult(
            success: true,
            answer: 'OK',
            error: null,
            iterations: 1,
            toolCalls: [],
            tokenUsage: [],
            analysis: [],
            tokenLedger: $ledger
        );

        $data = $result->toArray();
        $this->assertArrayHasKey('token_ledger', $data);
        $this->assertIsArray($data['token_ledger']);
        $this->assertArrayHasKey('entries', $data['token_ledger']);
    }

    public function testToJson(): void
    {
        $result = new BotResult(
            success: true,
            answer: 'Done',
            error: null,
            iterations: 1,
            toolCalls: [],
            tokenUsage: [],
            analysis: []
        );

        $json = $result->toJson();
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['success']);
        $this->assertSame('Done', $decoded['answer']);
    }
}