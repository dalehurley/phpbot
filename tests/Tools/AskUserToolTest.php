<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Tools\AskUserTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AskUserTool::class)]
class AskUserToolTest extends TestCase
{
    public function testGetName(): void
    {
        $tool = new AskUserTool();
        $this->assertSame('ask_user', $tool->getName());
    }

    public function testGetDescription(): void
    {
        $tool = new AskUserTool();
        $this->assertStringContainsString('user', strtolower($tool->getDescription()));
    }

    public function testGetInputSchema(): void
    {
        $tool = new AskUserTool();
        $schema = $tool->getInputSchema();
        $this->assertArrayHasKey('question', $schema['properties']);
        $this->assertArrayHasKey('default', $schema['properties']);
        $this->assertContains('question', $schema['required']);
    }

    public function testExecuteEmptyQuestionReturnsError(): void
    {
        $tool = new AskUserTool();
        $result = $tool->execute(['question' => '']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Question is required', $result->getContent());
    }

    public function testToDefinition(): void
    {
        $tool = new AskUserTool();
        $def = $tool->toDefinition();
        $this->assertSame('ask_user', $def['name']);
        $this->assertSame($tool->getDescription(), $def['description']);
        $this->assertSame($tool->getInputSchema(), $def['input_schema']);
    }
}
