<?php

declare(strict_types=1);

namespace Tests\Storage;

use Dalehurley\Phpbot\Storage\RollbackManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RollbackManager::class)]
class RollbackManagerTest extends TestCase
{
    private string $tmpDir;
    private string $rollbackRoot;
    private RollbackManager $manager;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-rollback-' . uniqid();
        $this->rollbackRoot = $this->tmpDir . '/rollback';
        mkdir($this->tmpDir, 0755, true);
        $this->manager = new RollbackManager($this->rollbackRoot);
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

    public function testCreateSnapshotForExistingFile(): void
    {
        $file = $this->tmpDir . '/original.txt';
        file_put_contents($file, 'original content');

        $this->manager->createSnapshot('session-1', [$file]);

        $result = $this->manager->rollback('session-1');
        $this->assertContains($file, $result['restored']);
        $this->assertEmpty($result['deleted']);
    }

    public function testCreateSnapshotForNonExistentFileRecordsAsNew(): void
    {
        $newFile = $this->tmpDir . '/new-file.txt';
        $this->manager->createSnapshot('session-1', [$newFile]);

        file_put_contents($newFile, 'created during session');
        $result = $this->manager->rollback('session-1');
        $this->assertContains($newFile, $result['deleted']);
        $this->assertFileDoesNotExist($newFile);
    }

    public function testRollbackRestoresModifiedFile(): void
    {
        $file = $this->tmpDir . '/modified.txt';
        file_put_contents($file, 'before');
        $this->manager->createSnapshot('session-1', [$file]);
        file_put_contents($file, 'after');

        $this->manager->rollback('session-1');
        $this->assertSame('before', file_get_contents($file));
    }

    public function testCreateSnapshotSkipsAlreadySnapshotted(): void
    {
        $file = $this->tmpDir . '/dupe.txt';
        file_put_contents($file, 'content');
        $this->manager->createSnapshot('session-1', [$file]);
        $this->manager->createSnapshot('session-1', [$file]);

        $list = $this->manager->listSessions();
        $this->assertCount(1, $list);
    }

    public function testListSessionsReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->manager->listSessions());
    }

    public function testListSessionsReturnsSessionInfo(): void
    {
        $file = $this->tmpDir . '/file.txt';
        file_put_contents($file, 'x');
        $this->manager->createSnapshot('s1', [$file]);

        $list = $this->manager->listSessions();
        $this->assertCount(1, $list);
        $this->assertArrayHasKey('session_id', $list[0]);
        $this->assertArrayHasKey('created_at', $list[0]);
        $this->assertArrayHasKey('file_count', $list[0]);
        $this->assertSame(1, $list[0]['file_count']);
    }

    public function testSetSessionTask(): void
    {
        $file = $this->tmpDir . '/task-file.txt';
        file_put_contents($file, 'x');
        $this->manager->createSnapshot('s1', [$file]);
        $this->manager->setSessionTask('s1', 'Build the feature');

        $list = $this->manager->listSessions();
        $this->assertCount(1, $list);
        $this->assertStringContainsString('Build', $list[0]['task_preview'] ?? '');
    }

    public function testCreateSnapshotMultipleFiles(): void
    {
        $f1 = $this->tmpDir . '/a.txt';
        $f2 = $this->tmpDir . '/b.txt';
        file_put_contents($f1, 'a');
        file_put_contents($f2, 'b');

        $this->manager->createSnapshot('multi', [$f1, $f2]);
        file_put_contents($f1, 'a-modified');
        file_put_contents($f2, 'b-modified');

        $this->manager->rollback('multi');
        $this->assertSame('a', file_get_contents($f1));
        $this->assertSame('b', file_get_contents($f2));
    }

    public function testCreateSnapshotThrowsWhenCannotCreateDir(): void
    {
        $conflict = $this->tmpDir . '/conflict';
        file_put_contents($conflict, 'file');
        $manager = new RollbackManager($conflict . '/subdir');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create snapshot directory');
        $manager->createSnapshot('s1', []);
    }

    public function testSessionIdSanitizedInPath(): void
    {
        $file = $this->tmpDir . '/test.txt';
        file_put_contents($file, 'x');
        $this->manager->createSnapshot('session/with:chars', [$file]);

        $result = $this->manager->rollback('session/with:chars');
        $this->assertNotEmpty($result['restored']);
    }
}
