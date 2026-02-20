<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Storage\BackupManager;
use Dalehurley\Phpbot\Tools\VerifyOperationTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mockery;

#[CoversClass(VerifyOperationTool::class)]
class VerifyOperationToolTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-verify-' . uniqid();
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

    public function testGetName(): void
    {
        $tool = new VerifyOperationTool();
        $this->assertSame('verify_operation', $tool->getName());
    }

    public function testExecuteEmptyFilesReturnsError(): void
    {
        $tool = new VerifyOperationTool();
        $result = $tool->execute(['files' => []]);
        $this->assertTrue($result->isError());
    }

    public function testExecuteExpectedPatternFound(): void
    {
        $file = $this->tmpDir . '/f.txt';
        file_put_contents($file, 'contains expected text');

        $tool = new VerifyOperationTool();
        $result = $tool->execute([
            'files' => [$file],
            'expected_pattern' => 'expected',
        ]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame('PASS', $data['overall']);
        $this->assertSame(1, $data['passed']);
    }

    public function testExecuteExpectedPatternNotFound(): void
    {
        $file = $this->tmpDir . '/f.txt';
        file_put_contents($file, 'content');

        $tool = new VerifyOperationTool();
        $result = $tool->execute([
            'files' => [$file],
            'expected_pattern' => 'MISSING',
        ]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame('FAIL', $data['overall']);
    }

    public function testExecuteForbiddenPatternStillPresent(): void
    {
        $file = $this->tmpDir . '/f.txt';
        file_put_contents($file, 'old secret key here');

        $tool = new VerifyOperationTool();
        $result = $tool->execute([
            'files' => [$file],
            'forbidden_pattern' => 'old secret',
        ]);
        $data = json_decode($result->getContent(), true);
        $this->assertSame('FAIL', $data['overall']);
    }

    public function testExecuteFileNotFound(): void
    {
        $tool = new VerifyOperationTool();
        $result = $tool->execute([
            'files' => [$this->tmpDir . '/nonexistent.txt'],
        ]);
        $data = json_decode($result->getContent(), true);
        $this->assertSame('FAIL', $data['overall']);
    }

    public function testExecuteWithBackupManagerIdenticalContentWarning(): void
    {
        $file = $this->tmpDir . '/f.txt';
        file_put_contents($file, 'content');

        $backupManager = Mockery::mock(BackupManager::class);
        $backupManager->shouldReceive('listBackups')
            ->with($file)
            ->andReturn([['path' => $this->tmpDir . '/backup.txt']]);

        $backupPath = $this->tmpDir . '/backup.txt';
        file_put_contents($backupPath, 'content');

        $tool = new VerifyOperationTool($backupManager);
        $result = $tool->execute(['files' => [$file]]);
        $data = json_decode($result->getContent(), true);
        $this->assertNotEmpty($data['results']);
        $issues = $data['results'][0]['issues'] ?? [];
        $this->assertTrue(
            count(array_filter($issues, fn($i) => str_contains($i, 'identical to backup'))) > 0,
            'Expected warning about file identical to backup'
        );
    }

    public function testExecuteSamplesUpTo10(): void
    {
        $files = [];
        for ($i = 0; $i < 15; $i++) {
            $f = $this->tmpDir . "/f{$i}.txt";
            file_put_contents($f, 'content');
            $files[] = $f;
        }
        $tool = new VerifyOperationTool();
        $result = $tool->execute(['files' => $files]);
        $data = json_decode($result->getContent(), true);
        $this->assertSame(15, $data['total_files']);
        $this->assertSame(10, $data['sampled']);
    }
}
