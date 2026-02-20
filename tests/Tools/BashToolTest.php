<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\DryRun\DryRunContext;
use Dalehurley\Phpbot\Storage\BackupManager;
use Dalehurley\Phpbot\Storage\RollbackManager;
use Dalehurley\Phpbot\Tools\BashTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mockery;

#[CoversClass(BashTool::class)]
class BashToolTest extends TestCase
{
    private string $tmpDir;
    private BashTool $tool;

    protected function setUp(): void
    {
        DryRunContext::deactivate();
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-bash-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->tool = new BashTool(['working_directory' => $this->tmpDir]);
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
        $this->assertSame('bash', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $desc = $this->tool->getDescription();
        $this->assertStringContainsString('bash', strtolower($desc));
        $this->assertStringContainsString('command', strtolower($desc));
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('command', $schema['properties']);
        $this->assertArrayHasKey('working_directory', $schema['properties']);
        $this->assertArrayHasKey('timeout', $schema['properties']);
        $this->assertContains('command', $schema['required']);
    }

    public function testToDefinition(): void
    {
        $def = $this->tool->toDefinition();
        $this->assertSame('bash', $def['name']);
        $this->assertSame($this->tool->getDescription(), $def['description']);
        $this->assertSame($this->tool->getInputSchema(), $def['input_schema']);
    }

    public function testExecuteEmptyCommandReturnsError(): void
    {
        $result = $this->tool->execute(['command' => '']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('empty', $result->getContent());
    }

    public function testExecuteWhitespaceOnlyCommandReturnsError(): void
    {
        $result = $this->tool->execute(['command' => '   ']);
        $this->assertTrue($result->isError());
    }

    public function testExecuteAllowedCommandReturnsSuccess(): void
    {
        $result = $this->tool->execute(['command' => 'echo hello']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame('hello', trim($data['stdout'] ?? ''));
        $this->assertSame(0, $data['exit_code']);
    }

    public function testExecuteBlockedCommandReturnsError(): void
    {
        $result = $this->tool->execute(['command' => 'rm -rf /']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('blocked', $result->getContent());
    }

    public function testExecuteBlockedMkfsReturnsError(): void
    {
        $result = $this->tool->execute(['command' => 'mkfs.ext4 /dev/sda1']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('blocked', $result->getContent());
    }

    public function testExecuteWithAllowedCommandsWhitelist(): void
    {
        $tool = new BashTool([
            'allowed_commands' => ['echo ', 'ls '],
        ]);
        $result = $tool->execute(['command' => 'echo allowed']);
        $this->assertFalse($result->isError());

        $result = $tool->execute(['command' => 'whoami']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not in allowed list', $result->getContent());
    }

    public function testExecuteDryRun(): void
    {
        DryRunContext::activate();
        $result = $this->tool->execute(['command' => 'echo dry-run']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertTrue($data['dry_run'] ?? false);
        $this->assertSame('', $data['stdout'] ?? null);
        DryRunContext::deactivate();
    }

    public function testExecuteWithWorkingDirectory(): void
    {
        $result = $this->tool->execute([
            'command' => 'pwd',
            'working_directory' => $this->tmpDir,
        ]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertStringContainsString(basename($this->tmpDir), $data['stdout'] ?? '');
    }

    public function testExecuteWithInvalidWorkingDirectory(): void
    {
        $result = $this->tool->execute([
            'command' => 'echo test',
            'working_directory' => '/nonexistent/path/xyz123',
        ]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('does not exist', $result->getContent());
    }

    public function testConsecutiveEmptyCommandsEscalateMessage(): void
    {
        $tool = new BashTool();
        $r1 = $tool->execute(['command' => '']);
        $this->assertStringContainsString('empty', strtolower($r1->getContent()));
        $r2 = $tool->execute(['command' => '']);
        $this->assertStringContainsString('WARNING', $r2->getContent());
        $r3 = $tool->execute(['command' => '']);
        $this->assertStringContainsString('CRITICAL', $r3->getContent());
    }

    public function testBackupManagerCalledWhenWritingFile(): void
    {
        $existingFile = $this->tmpDir . '/target.txt';
        file_put_contents($existingFile, 'original');

        $backupManager = Mockery::mock(BackupManager::class);
        $backupManager->shouldReceive('backup')
            ->once()
            ->with($existingFile)
            ->andReturn($this->tmpDir . '/backup.1');

        $tool = new BashTool(
            ['working_directory' => $this->tmpDir],
            $backupManager,
        );
        $result = $tool->execute(['command' => "echo overwrite > {$existingFile}"]);
        $this->assertFalse($result->isError());
    }
}
