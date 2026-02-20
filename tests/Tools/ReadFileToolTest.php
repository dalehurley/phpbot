<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Tools\ReadFileTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReadFileTool::class)]
class ReadFileToolTest extends TestCase
{
    private string $tmpDir;
    private ReadFileTool $tool;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-readfile-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->tool = new ReadFileTool();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
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

    public function testGetName(): void
    {
        $this->assertSame('read_file', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $desc = $this->tool->getDescription();
        $this->assertStringContainsString('read', strtolower($desc));
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('path', $schema['properties']);
        $this->assertArrayHasKey('max_bytes', $schema['properties']);
        $this->assertContains('path', $schema['required']);
    }

    public function testToDefinition(): void
    {
        $def = $this->tool->toDefinition();
        $this->assertSame('read_file', $def['name']);
    }

    public function testExecuteEmptyPathReturnsError(): void
    {
        $result = $this->tool->execute(['path' => '']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Path is required', $result->getContent());
    }

    public function testExecuteFileNotFoundReturnsError(): void
    {
        $result = $this->tool->execute(['path' => $this->tmpDir . '/nonexistent.txt']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('File not found', $result->getContent());
    }

    public function testExecuteMaxBytesZeroReturnsError(): void
    {
        $file = $this->tmpDir . '/file.txt';
        file_put_contents($file, 'content');
        $result = $this->tool->execute(['path' => $file, 'max_bytes' => 0]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('max_bytes', $result->getContent());
    }

    public function testExecuteReturnsContent(): void
    {
        $file = $this->tmpDir . '/readme.txt';
        $content = 'Hello World';
        file_put_contents($file, $content);

        $result = $this->tool->execute(['path' => $file]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame($file, $data['path']);
        $this->assertSame(strlen($content), $data['bytes_read']);
        $this->assertSame($content, $data['content']);
        $this->assertFalse($data['truncated']);
    }

    public function testExecuteTruncatesLargeFile(): void
    {
        $file = $this->tmpDir . '/large.txt';
        $content = str_repeat('x', 5000);
        file_put_contents($file, $content);

        $result = $this->tool->execute(['path' => $file, 'max_bytes' => 100]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame(100, $data['bytes_read']);
        $this->assertTrue($data['truncated']);
    }
}
