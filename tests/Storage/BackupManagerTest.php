<?php

declare(strict_types=1);

namespace Tests\Storage;

use Dalehurley\Phpbot\Storage\BackupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BackupManager::class)]
class BackupManagerTest extends TestCase
{
    private string $tmpDir;
    private string $backupRoot;
    private BackupManager $manager;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-backup-' . uniqid();
        $this->backupRoot = $this->tmpDir . '/backups';
        mkdir($this->tmpDir, 0755, true);
        $this->manager = new BackupManager($this->backupRoot, 5);
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

    public function testBackupReturnsNullForNonExistentFile(): void
    {
        $result = $this->manager->backup($this->tmpDir . '/nonexistent.txt');
        $this->assertNull($result);
    }

    public function testBackupCreatesVersionedFile(): void
    {
        $source = $this->tmpDir . '/file.txt';
        file_put_contents($source, 'content');

        $result = $this->manager->backup($source);

        $this->assertNotNull($result);
        $this->assertFileExists($result);
        $this->assertStringContainsString(date('Y-m-d'), $result);
        $this->assertStringEndsWith('.1', $result);
        $this->assertSame('content', file_get_contents($result));
    }

    public function testBackupIncrementsVersionNumber(): void
    {
        $source = $this->tmpDir . '/doc.txt';
        file_put_contents($source, 'v1');
        $this->manager->backup($source);
        file_put_contents($source, 'v2');
        $result = $this->manager->backup($source);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('.2', $result);
    }

    public function testListBackupsReturnsEmptyForNonExistentFile(): void
    {
        $list = $this->manager->listBackups($this->tmpDir . '/nonexistent.txt');
        $this->assertSame([], $list);
    }

    public function testListBackupsReturnsBackupsNewestFirst(): void
    {
        $source = $this->tmpDir . '/data.txt';
        file_put_contents($source, 'v1');
        $this->manager->backup($source);
        file_put_contents($source, 'v2');
        $this->manager->backup($source);

        $list = $this->manager->listBackups($source);
        $this->assertCount(2, $list);
        $this->assertGreaterThanOrEqual($list[1]['version'], $list[0]['version']);
        $this->assertArrayHasKey('version', $list[0]);
        $this->assertArrayHasKey('date', $list[0]);
        $this->assertArrayHasKey('path', $list[0]);
        $this->assertArrayHasKey('size', $list[0]);
    }

    public function testRestoreMostRecentByDefault(): void
    {
        $source = $this->tmpDir . '/restore.txt';
        file_put_contents($source, 'original');
        $this->manager->backup($source);
        file_put_contents($source, 'modified');

        $restoredPath = $this->manager->restore($source);
        $this->assertNotNull($restoredPath);
        $this->assertSame('original', file_get_contents($source));
    }

    public function testRestoreSpecificVersion(): void
    {
        $source = $this->tmpDir . '/versioned.txt';
        file_put_contents($source, 'v1');
        $this->manager->backup($source);
        file_put_contents($source, 'v2');
        $this->manager->backup($source);
        file_put_contents($source, 'v3');

        $this->manager->restore($source, 2);
        $this->assertSame('v2', file_get_contents($source));
    }

    public function testRestoreThrowsWhenNoBackupsExist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No backups found for');
        $this->manager->restore($this->tmpDir . '/no-backups.txt');
    }

    public function testRestoreThrowsWhenVersionNotFound(): void
    {
        $source = $this->tmpDir . '/file.txt';
        file_put_contents($source, 'content');
        $this->manager->backup($source);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Backup version 999 not found');
        $this->manager->restore($source, 999);
    }

    public function testPruneKeepsOnlyVersionsToKeep(): void
    {
        $manager = new BackupManager($this->backupRoot, 2);
        $source = $this->tmpDir . '/prune.txt';
        for ($i = 1; $i <= 4; $i++) {
            file_put_contents($source, "v{$i}");
            $manager->backup($source);
        }

        $list = $manager->listBackups($source);
        $this->assertLessThanOrEqual(2, count($list));
    }

    public function testConstructUsesDefaultBackupRootWhenEmpty(): void
    {
        $manager = new BackupManager('', 5);
        $this->assertInstanceOf(BackupManager::class, $manager);
    }

    public function testVersionsToKeepMinimumIsOne(): void
    {
        $manager = new BackupManager($this->backupRoot, 0);
        $source = $this->tmpDir . '/min.txt';
        file_put_contents($source, 'content');
        $result = $manager->backup($source);
        $this->assertNotNull($result);
    }
}
