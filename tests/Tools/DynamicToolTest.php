<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Tools\DynamicTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DynamicTool::class)]
class DynamicToolTest extends TestCase
{
    public function testGetName(): void
    {
        $tool = new DynamicTool(
            name: 'custom_tool',
            description: 'A custom tool',
            parameters: [['name' => 'x', 'type' => 'string', 'description' => 'Input']],
            handlerCode: 'return $input[\'x\'];',
        );
        $this->assertSame('custom_tool', $tool->getName());
    }

    public function testGetDescription(): void
    {
        $tool = new DynamicTool('t', 'Does something', [], 'return "ok";');
        $this->assertSame('Does something', $tool->getDescription());
    }

    public function testGetCategory(): void
    {
        $tool = new DynamicTool('t', 'd', [], 'return 1;', 'network');
        $this->assertSame('network', $tool->getCategory());
    }

    public function testGetCategoryDefaultsToGeneral(): void
    {
        $tool = new DynamicTool('t', 'd', [], 'return 1;');
        $this->assertSame('general', $tool->getCategory());
    }

    public function testGetInputSchemaFromParameters(): void
    {
        $tool = new DynamicTool(
            name: 'test',
            description: 'd',
            parameters: [
                ['name' => 'a', 'type' => 'string', 'description' => 'A param', 'required' => true],
                ['name' => 'b', 'type' => 'integer', 'description' => 'B param', 'required' => false, 'default' => 5],
            ],
            handlerCode: 'return $input[\'a\'];',
        );
        $schema = $tool->getInputSchema();
        $this->assertArrayHasKey('a', $schema['properties']);
        $this->assertArrayHasKey('b', $schema['properties']);
        $this->assertContains('a', $schema['required']);
    }

    public function testExecuteReturnsStringResult(): void
    {
        $tool = new DynamicTool('t', 'd', [], 'return "hello";');
        $result = $tool->execute([]);
        $this->assertFalse($result->isError());
        $this->assertSame('hello', $result->getContent());
    }

    public function testExecuteReturnsJsonEncodedArray(): void
    {
        $tool = new DynamicTool('t', 'd', [], 'return ["key" => "value"];');
        $result = $tool->execute([]);
        $this->assertFalse($result->isError());
        $decoded = json_decode($result->getContent(), true);
        $this->assertSame(['key' => 'value'], $decoded);
    }

    public function testExecuteAppliesDefaultsForOptionalParameters(): void
    {
        $tool = new DynamicTool(
            name: 't',
            description: 'd',
            parameters: [
                ['name' => 'x', 'type' => 'string', 'description' => 'X', 'required' => false, 'default' => 'defaulted'],
            ],
            handlerCode: 'return $input[\'x\'] ?? "missing";',
        );
        $result = $tool->execute([]);
        $this->assertSame('defaulted', $result->getContent());
    }

    public function testExecuteThrowsReturnsError(): void
    {
        $tool = new DynamicTool('t', 'd', [], 'throw new \RuntimeException("oops");');
        $result = $tool->execute([]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('oops', $result->getContent());
    }

    public function testValidateHandlerInvalidCodeThrows(): void
    {
        $tool = new DynamicTool('t', 'd', [], 'syntax error {');
        $this->expectException(\RuntimeException::class);
        $tool->validateHandler();
    }

    public function testToArrayAndFromArrayRoundTrip(): void
    {
        $original = new DynamicTool(
            name: 'round_trip',
            description: 'Round trip test',
            parameters: [['name' => 'p', 'type' => 'string', 'description' => 'Param']],
            handlerCode: 'return $input["p"];',
            category: 'data',
        );
        $arr = $original->toArray();
        $restored = DynamicTool::fromArray($arr);
        $this->assertSame($original->getName(), $restored->getName());
        $this->assertSame($original->getCategory(), $restored->getCategory());
        $result = $restored->execute(['p' => 'value']);
        $this->assertSame('value', $result->getContent());
    }

    public function testToDefinition(): void
    {
        $tool = new DynamicTool('t', 'desc', [], 'return 1;');
        $def = $tool->toDefinition();
        $this->assertSame('t', $def['name']);
        $this->assertSame('desc', $def['description']);
    }
}
