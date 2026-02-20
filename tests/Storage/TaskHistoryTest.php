<?php

declare(strict_types=1);

namespace Tests\Storage;

use Dalehurley\Phpbot\Storage\TaskHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskHistory::class)]
class TaskHistoryTest extends TestCase
{
    private string $tmpDir;
    private TaskHistory $history;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-history-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->history = new TaskHistory($this->tmpDir);
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

    public function testRecordReturnsTaskId(): void
    {
        $id = $this->history->record('Create a file', 'Done', [], []);
        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-[a-f0-9]{8}$/', $id);
    }

    public function testRecordAndGet(): void
    {
        $id = $this->history->record('Task', 'Result', ['param' => 'val'], ['meta' => 'data']);
        $entry = $this->history->get($id);
        $this->assertNotNull($entry);
        $this->assertSame($id, $entry['id']);
        $this->assertSame('Task', $entry['task']);
        $this->assertSame('val', $entry['params']['param'] ?? null);
        $this->assertSame('data', $entry['metadata']['meta'] ?? null);
        $this->assertArrayHasKey('recorded_at', $entry);
        $this->assertArrayHasKey('keywords', $entry);
    }

    public function testRecordTruncatesResultSummary(): void
    {
        $longResult = str_repeat('x', 600);
        $id = $this->history->record('Task', $longResult);
        $entry = $this->history->get($id);
        $this->assertLessThanOrEqual(503, strlen($entry['result_summary'] ?? ''));
    }

    public function testListReturnsNewestFirst(): void
    {
        $this->history->record('First', 'R1');
        sleep(1);
        $this->history->record('Second', 'R2');

        $list = $this->history->list(10);
        $this->assertCount(2, $list);
        $this->assertStringContainsString('Second', $list[0]['task']);
    }

    public function testListRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->history->record("Task {$i}", "R{$i}");
        }
        $list = $this->history->list(3);
        $this->assertCount(3, $list);
    }

    public function testGetReturnsNullForNonExistent(): void
    {
        $this->assertNull($this->history->get('nonexistent-id'));
    }

    public function testGetWithPrefixSearch(): void
    {
        $id = $this->history->record('Task', 'Result');
        $prefix = substr($id, 0, 15);
        $entry = $this->history->get($prefix);
        $this->assertNotNull($entry);
        $this->assertSame($id, $entry['id']);
    }

    public function testFindSimilarReturnsMatchingTasks(): void
    {
        $this->history->record('Fix the login bug in authentication', 'Fixed');
        $similar = $this->history->findSimilar('authentication login problem', 5);
        $this->assertNotEmpty($similar);
        $this->assertArrayHasKey('id', $similar[0]);
        $this->assertArrayHasKey('score', $similar[0]);
        $this->assertGreaterThan(0, $similar[0]['score']);
    }

    public function testFindSimilarReturnsEmptyForNoMatch(): void
    {
        $this->history->record('Create file xyz', 'Done');
        $similar = $this->history->findSimilar('completely unrelated query zzz', 5);
        $this->assertEmpty($similar);
    }

    public function testApplyOverrides(): void
    {
        $task = 'Update user john@example.com with new settings';
        $params = ['email' => 'john@example.com'];
        $overrides = ['email' => 'jane@example.com'];
        $result = $this->history->applyOverrides($task, $params, $overrides);
        $this->assertStringContainsString('jane@example.com', $result);
        $this->assertStringNotContainsString('john@example.com', $result);
    }

    public function testApplyOverridesIgnoresMissingParams(): void
    {
        $task = 'Some task';
        $params = ['x' => 'val'];
        $overrides = ['nonexistent' => 'new'];
        $result = $this->history->applyOverrides($task, $params, $overrides);
        $this->assertSame($task, $result);
    }

    public function testListReturnsEmptyWhenDirDoesNotExist(): void
    {
        $history = new TaskHistory($this->tmpDir . '/nonexistent');
        $this->assertSame([], $history->list());
    }
}
