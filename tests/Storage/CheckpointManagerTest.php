<?php

declare(strict_types=1);

namespace Tests\Storage;

use Dalehurley\Phpbot\Storage\CheckpointManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckpointManager::class)]
class CheckpointManagerTest extends TestCase
{
    private string $tmpDir;
    private CheckpointManager $manager;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-checkpoint-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->manager = new CheckpointManager($this->tmpDir);
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

    public function testSaveAndLoad(): void
    {
        $state = ['task' => 'Fix the bug', 'iteration' => 3];
        $this->manager->save('session-1', $state);

        $loaded = $this->manager->load('session-1');
        $this->assertNotNull($loaded);
        $this->assertSame('Fix the bug', $loaded['task']);
        $this->assertSame(3, $loaded['iteration']);
        $this->assertArrayHasKey('checkpoint_at', $loaded);
        $this->assertArrayHasKey('session_id', $loaded);
        $this->assertSame('session-1', $loaded['session_id']);
    }

    public function testLoadReturnsNullForNonExistentSession(): void
    {
        $this->assertNull($this->manager->load('nonexistent'));
    }

    public function testExistsReturnsFalseForNonExistent(): void
    {
        $this->assertFalse($this->manager->exists('no-session'));
    }

    public function testExistsReturnsTrueAfterSave(): void
    {
        $this->manager->save('s1', ['task' => 'x']);
        $this->assertTrue($this->manager->exists('s1'));
    }

    public function testClearRemovesCheckpoint(): void
    {
        $this->manager->save('clear-me', ['task' => 't']);
        $this->manager->clear('clear-me');
        $this->assertFalse($this->manager->exists('clear-me'));
        $this->assertNull($this->manager->load('clear-me'));
    }

    public function testClearDoesNothingForNonExistent(): void
    {
        $this->manager->clear('nonexistent');
        $this->assertTrue(true);
    }

    public function testListSessionsReturnsEmptyWhenNoCheckpoints(): void
    {
        $list = $this->manager->listSessions();
        $this->assertSame([], $list);
    }

    public function testListSessionsReturnsSessionsNewestFirst(): void
    {
        $this->manager->save('s1', ['task' => 'Task 1', 'iteration' => 1]);
        sleep(1);
        $this->manager->save('s2', ['task' => 'Task 2', 'iteration' => 2]);

        $list = $this->manager->listSessions();
        $this->assertCount(2, $list);
        $this->assertArrayHasKey('session_id', $list[0]);
        $this->assertArrayHasKey('task', $list[0]);
        $this->assertArrayHasKey('checkpoint_at', $list[0]);
        $this->assertArrayHasKey('iteration', $list[0]);
    }

    public function testSessionIdSanitizedInFilename(): void
    {
        $this->manager->save('session/with:special!chars', ['task' => 'x']);
        $this->assertTrue($this->manager->exists('session/with:special!chars'));
        $loaded = $this->manager->load('session/with:special!chars');
        $this->assertNotNull($loaded);
    }

    public function testLoadReturnsNullForInvalidJson(): void
    {
        $path = $this->tmpDir . '/invalid__.json';
        file_put_contents($path, 'not valid json');
        $manager = new CheckpointManager($this->tmpDir);
        $loaded = $manager->load('invalid_');
        $this->assertNull($loaded);
    }
}
