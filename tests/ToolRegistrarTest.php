<?php

declare(strict_types=1);

namespace Tests;

use Dalehurley\Phpbot\Registry\PersistentToolRegistry;
use Dalehurley\Phpbot\ToolRegistrar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolRegistrar::class)]
class ToolRegistrarTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phpbot_tools_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testConstructor(): void
    {
        $registry = new PersistentToolRegistry($this->tempDir . '/tools.json');
        $config = ['files_storage_path' => $this->tempDir];
        $registrar = new ToolRegistrar($registry, $config);
        $this->assertInstanceOf(ToolRegistrar::class, $registrar);
    }

    public function testSelectToolsWithoutRouteResult(): void
    {
        $registry = new PersistentToolRegistry($this->tempDir . '/tools.json');
        $config = [
            'files_storage_path' => $this->tempDir,
            'tools_storage_path' => $this->tempDir,
        ];
        $registrar = new ToolRegistrar($registry, $config);
        $registrar->registerCoreTools();
        $analysis = ['potential_tools_needed' => ['bash']];
        $tools = $registrar->selectTools($analysis, null);
        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools);
    }

    public function testGetRegistry(): void
    {
        $registry = new PersistentToolRegistry($this->tempDir . '/tools.json');
        $registrar = new ToolRegistrar($registry, []);
        $this->assertSame($registry, $registrar->getRegistry());
    }
}
