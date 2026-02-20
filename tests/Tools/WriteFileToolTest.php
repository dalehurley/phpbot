<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\DryRun\DryRunContext;
use Dalehurley\Phpbot\Storage\BackupManager;
use Dalehurley\Phpbot\Tools\WriteFileTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mockery;

#[CoversClass(WriteFileTool::class)]
class WriteFileToolTest extends TestCase
{
    private string $tmpDir;
    private WriteFileTool $tool;

    protected function setUp(): void
    {
        DryRunContext::deactivate();
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-writefile-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->tool = new WriteFileTool($this->tmpDir);
    }

    protected function tearDown(): void
    {
        DryRunContext::deactivate();
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

    public function testGetName(): void
    {
        $this->assertSame('write_file', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertStringContainsString('write', strtolower($this->tool->getDescription()));
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertArrayHasKey('path', $schema['properties']);
        $this->assertArrayHasKey('content', $schema['properties']);
        $this->assertArrayHasKey('append', $schema['properties']);
        $this->assertContains('path', $schema['required']);
        $this->assertContains('content', $schema['required']);
    }

    public function testExecuteEmptyPathReturnsError(): void
    {
        $result = $this->tool->execute(['path' => '', 'content' => 'x']);
        $this->assertTrue($result->isError());
    }

    public function testExecuteCreatesFile(): void
    {
        $result = $this->tool->execute([
            'path' => 'output.txt',
            'content' => 'Hello',
        ]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $expectedPath = $this->tmpDir . '/output.txt';
        $this->assertSame($expectedPath, $data['path']);
        $this->assertFileExists($expectedPath);
        $this->assertSame('Hello', file_get_contents($expectedPath));
    }

    public function testExecuteCreatesSubdirectories(): void
    {
        $result = $this->tool->execute([
            'path' => 'sub/dir/file.txt',
            'content' => 'nested',
        ]);
        $this->assertFalse($result->isError());
        $expectedPath = $this->tmpDir . '/sub/dir/file.txt';
        $this->assertFileExists($expectedPath);
    }

    public function testExecuteAppend(): void
    {
        $path = 'append.txt';
        $this->tool->execute(['path' => $path, 'content' => 'A']);
        $result = $this->tool->execute(['path' => $path, 'content' => 'B', 'append' => true]);
        $this->assertFalse($result->isError());
        $fullPath = $this->tmpDir . '/' . $path;
        $this->assertSame('AB', file_get_contents($fullPath));
    }

    public function testExecuteDryRun(): void
    {
        DryRunContext::activate();
        $result = $this->tool->execute(['path' => 'dry.txt', 'content' => 'skip']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertTrue($data['dry_run'] ?? false);
        $this->assertFileDoesNotExist($this->tmpDir . '/dry.txt');
        DryRunContext::deactivate();
    }

    public function testBackupManagerCalledWhenOverwriting(): void
    {
        $path = 'backup_target.txt';
        $fullPath = $this->tmpDir . '/' . $path;
        file_put_contents($fullPath, 'original');

        $backupManager = Mockery::mock(BackupManager::class);
        $backupManager->shouldReceive('backup')
            ->once()
            ->with($fullPath)
            ->andReturn($this->tmpDir . '/backup.1');

        $tool = new WriteFileTool($this->tmpDir, $backupManager);
        $result = $tool->execute(['path' => $path, 'content' => 'new']);
        $this->assertFalse($result->isError());
    }

    public function testGetCreatedFilesAndReset(): void
    {
        $this->tool->execute(['path' => 'a.txt', 'content' => 'a']);
        $this->tool->execute(['path' => 'b.txt', 'content' => 'b']);
        $created = $this->tool->getCreatedFiles();
        $this->assertCount(2, $created);

        $this->tool->resetCreatedFiles();
        $this->assertSame([], $this->tool->getCreatedFiles());
    }
}
