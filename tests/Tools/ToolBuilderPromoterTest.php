<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Registry\PersistentToolRegistry;
use Dalehurley\Phpbot\Tools\DynamicTool;
use Dalehurley\Phpbot\Tools\ToolBuilderTool;
use Dalehurley\Phpbot\Tools\ToolPromoterTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mockery;

#[CoversClass(ToolBuilderTool::class)]
#[CoversClass(ToolPromoterTool::class)]
class ToolBuilderPromoterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-registry-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
        Mockery::close();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ToolBuilderTool
    public function testToolBuilderGetName(): void
    {
        $registry = new PersistentToolRegistry($this->tmpDir);
        $tool = new ToolBuilderTool($registry);
        $this->assertSame('tool_builder', $tool->getName());
    }

    public function testToolBuilderInvalidNameReturnsError(): void
    {
        $registry = new PersistentToolRegistry($this->tmpDir);
        $tool = new ToolBuilderTool($registry);
        $result = $tool->execute([
            'name' => 'Invalid-Name',
            'description' => 'Test',
            'parameters' => [],
            'handler_code' => 'return "ok";',
        ]);
        $this->assertTrue($result->isError());
    }

    public function testToolBuilderCreatesAndRegistersTool(): void
    {
        $registry = new PersistentToolRegistry($this->tmpDir);
        $tool = new ToolBuilderTool($registry);

        $result = $tool->execute([
            'name' => 'simple_test_tool',
            'description' => 'A simple test tool',
            'parameters' => [['name' => 'x', 'type' => 'string', 'description' => 'Input']],
            'handler_code' => 'return $input["x"] ?? "default";',
            'test_inputs' => [['x' => 'hello']],
        ]);

        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertTrue($data['success'] ?? false);
        $this->assertTrue($registry->has('simple_test_tool'));
    }

    public function testToolBuilderToolAlreadyExistsReturnsError(): void
    {
        $registry = new PersistentToolRegistry($this->tmpDir);
        $dynamicTool = new DynamicTool(
            name: 'existing_tool',
            description: 'Exists',
            parameters: [],
            handlerCode: 'return 1;',
        );
        $registry->registerCustomTool($dynamicTool);

        $builder = new ToolBuilderTool($registry);
        $result = $builder->execute([
            'name' => 'existing_tool',
            'description' => 'Duplicate',
            'parameters' => [],
            'handler_code' => 'return 1;',
            'test_inputs' => [[]],
        ]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('already exists', $result->getContent());
    }

    public function testToolBuilderBlockedHandlerCodeReturnsError(): void
    {
        $registry = new PersistentToolRegistry($this->tmpDir);
        $tool = new ToolBuilderTool($registry);
        $result = $tool->execute([
            'name' => 'dangerous_tool',
            'description' => 'Uses eval',
            'parameters' => [],
            'handler_code' => 'eval("return 1;");',
            'test_inputs' => [[]],
        ]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('blocked', strtolower($result->getContent()));
    }

    // ToolPromoterTool
    public function testToolPromoterGetName(): void
    {
        $registry = new PersistentToolRegistry($this->tmpDir);
        $tool = new ToolPromoterTool($registry);
        $this->assertSame('tool_promoter', $tool->getName());
    }

    public function testToolPromoterToolNotFoundReturnsError(): void
    {
        $registry = new PersistentToolRegistry($this->tmpDir);
        $tool = new ToolPromoterTool($registry);
        $result = $tool->execute(['name' => 'nonexistent_tool']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not found', $result->getContent());
    }

    public function testToolPromoterPromotesDynamicTool(): void
    {
        $registry = new PersistentToolRegistry($this->tmpDir);
        $dynamicTool = new DynamicTool(
            name: 'promote_me',
            description: 'Tool to promote',
            parameters: [['name' => 'p', 'type' => 'string', 'description' => 'Param']],
            handlerCode: 'return $input["p"];',
            category: 'general',
        );
        $registry->registerCustomTool($dynamicTool);

        $promoterDir = $this->tmpDir . '/promoted';
        $tool = new ToolPromoterTool($registry);
        $result = $tool->execute([
            'name' => 'promote_me',
            'destination_dir' => $promoterDir,
            'keep_json' => true,
        ]);

        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertTrue($data['success'] ?? false);
        $this->assertFileExists($data['file'] ?? '');
    }

    public function testToolPromoterNonDynamicToolReturnsError(): void
    {
        $registry = new PersistentToolRegistry($this->tmpDir);
        $registry->register(new \Dalehurley\Phpbot\Tools\ReadFileTool());

        $tool = new ToolPromoterTool($registry);
        $result = $tool->execute(['name' => 'read_file']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not a dynamic tool', $result->getContent());
    }
}
