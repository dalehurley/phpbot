<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\DryRun\DryRunContext;
use Dalehurley\Phpbot\Storage\BackupManager;
use Dalehurley\Phpbot\Storage\RollbackManager;
use Dalehurley\Phpbot\Tools\EditFileTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mockery;

#[CoversClass(EditFileTool::class)]
class EditFileToolTest extends TestCase
{
    private string $tmpDir;
    private EditFileTool $tool;

    protected function setUp(): void
    {
        DryRunContext::deactivate();
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-editfile-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->tool = new EditFileTool();
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
        $this->assertSame('edit_file', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertStringContainsString('edit', strtolower($this->tool->getDescription()));
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertArrayHasKey('path', $schema['properties']);
        $this->assertArrayHasKey('search', $schema['properties']);
        $this->assertArrayHasKey('replace', $schema['properties']);
        $this->assertArrayHasKey('replace_all', $schema['properties']);
    }

    public function testExecuteEmptyPathReturnsError(): void
    {
        $result = $this->tool->execute(['path' => '', 'search' => 'x', 'replace' => 'y']);
        $this->assertTrue($result->isError());
    }

    public function testExecuteEmptySearchReturnsError(): void
    {
        $file = $this->tmpDir . '/f.txt';
        file_put_contents($file, 'content');
        $result = $this->tool->execute(['path' => $file, 'search' => '', 'replace' => 'y']);
        $this->assertTrue($result->isError());
    }

    public function testExecuteFileNotFoundReturnsError(): void
    {
        $result = $this->tool->execute([
            'path' => $this->tmpDir . '/missing.txt',
            'search' => 'x',
            'replace' => 'y',
        ]);
        $this->assertTrue($result->isError());
    }

    public function testExecuteSearchNotFoundReturnsError(): void
    {
        $file = $this->tmpDir . '/f.txt';
        file_put_contents($file, 'content');
        $result = $this->tool->execute(['path' => $file, 'search' => 'NOPE', 'replace' => 'y']);
        $this->assertTrue($result->isError());
    }

    public function testExecuteReplaceAll(): void
    {
        $file = $this->tmpDir . '/f.txt';
        file_put_contents($file, 'foo bar foo');
        $result = $this->tool->execute([
            'path' => $file,
            'search' => 'foo',
            'replace' => 'baz',
            'replace_all' => true,
        ]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame(2, $data['replacements']);
        $this->assertSame('baz bar baz', file_get_contents($file));
    }

    public function testExecuteReplaceFirstOnly(): void
    {
        $file = $this->tmpDir . '/f.txt';
        file_put_contents($file, 'foo bar foo');
        $result = $this->tool->execute([
            'path' => $file,
            'search' => 'foo',
            'replace' => 'baz',
            'replace_all' => false,
        ]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame(1, $data['replacements']);
        $this->assertSame('baz bar foo', file_get_contents($file));
    }

    public function testExecuteDryRun(): void
    {
        $file = $this->tmpDir . '/f.txt';
        file_put_contents($file, 'original');
        DryRunContext::activate();
        $result = $this->tool->execute(['path' => $file, 'search' => 'original', 'replace' => 'changed']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertTrue($data['dry_run'] ?? false);
        $this->assertSame('original', file_get_contents($file));
        DryRunContext::deactivate();
    }

    public function testExecuteBackupAndRollbackIntegration(): void
    {
        $file = $this->tmpDir . '/target.txt';
        file_put_contents($file, 'before');

        $backupManager = Mockery::mock(BackupManager::class);
        $backupManager->shouldReceive('backup')->once()->with($file)->andReturn($this->tmpDir . '/backup.1');

        $rollbackManager = Mockery::mock(RollbackManager::class);
        $rollbackManager->shouldReceive('createSnapshot')->once();

        $tool = new EditFileTool($backupManager, $rollbackManager, 'session-1');
        $result = $tool->execute(['path' => $file, 'search' => 'before', 'replace' => 'after']);
        $this->assertFalse($result->isError());
    }
}
