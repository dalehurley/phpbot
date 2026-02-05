<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tests;

use PHPUnit\Framework\TestCase;
use Dalehurley\Phpbot\Storage\AbilityStore;

class AbilityStoreTest extends TestCase
{
    private string $tempDir;
    private AbilityStore $store;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpbot_test_abilities_' . uniqid();
        $this->store = new AbilityStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
    }

    public function testConstructorCreatesDirectory(): void
    {
        $this->assertDirectoryExists($this->tempDir);
    }

    public function testSaveAndGet(): void
    {
        $ability = [
            'title' => 'Use step stool for high shelves',
            'description' => 'When something is out of reach, use a step stool',
            'obstacle' => 'Item was too high to reach',
            'strategy' => 'Found a step stool and climbed on it',
            'outcome' => 'Successfully retrieved the item',
            'tags' => ['reaching', 'tools', 'physical'],
        ];

        $id = $this->store->save($ability);

        $this->assertNotEmpty($id);
        $this->assertStringStartsWith('ability_', $id);

        $retrieved = $this->store->get($id);
        $this->assertNotNull($retrieved);
        $this->assertSame('Use step stool for high shelves', $retrieved['title']);
        $this->assertSame('When something is out of reach, use a step stool', $retrieved['description']);
        $this->assertSame('Item was too high to reach', $retrieved['obstacle']);
        $this->assertSame('Found a step stool and climbed on it', $retrieved['strategy']);
        $this->assertSame('Successfully retrieved the item', $retrieved['outcome']);
        $this->assertSame(['reaching', 'tools', 'physical'], $retrieved['tags']);
        $this->assertArrayHasKey('created_at', $retrieved);
        $this->assertSame($id, $retrieved['id']);
    }

    public function testSaveWithCustomId(): void
    {
        $ability = [
            'id' => 'custom_id_123',
            'title' => 'Custom ability',
        ];

        $id = $this->store->save($ability);
        $this->assertSame('custom_id_123', $id);

        $retrieved = $this->store->get('custom_id_123');
        $this->assertNotNull($retrieved);
        $this->assertSame('Custom ability', $retrieved['title']);
    }

    public function testSavePreservesCreatedAt(): void
    {
        $ability = [
            'title' => 'Test ability',
            'created_at' => '2025-01-01T00:00:00+00:00',
        ];

        $id = $this->store->save($ability);
        $retrieved = $this->store->get($id);

        $this->assertSame('2025-01-01T00:00:00+00:00', $retrieved['created_at']);
    }

    public function testSaveGeneratesCreatedAt(): void
    {
        $ability = ['title' => 'Test ability'];
        $id = $this->store->save($ability);
        $retrieved = $this->store->get($id);

        $this->assertArrayHasKey('created_at', $retrieved);
        $this->assertNotEmpty($retrieved['created_at']);
    }

    public function testGetNonExistent(): void
    {
        $result = $this->store->get('nonexistent_id');
        $this->assertNull($result);
    }

    public function testAll(): void
    {
        $this->store->save(['title' => 'First', 'created_at' => '2025-01-01T00:00:00+00:00']);
        $this->store->save(['title' => 'Second', 'created_at' => '2025-01-02T00:00:00+00:00']);
        $this->store->save(['title' => 'Third', 'created_at' => '2025-01-03T00:00:00+00:00']);

        $all = $this->store->all();

        $this->assertCount(3, $all);
        // Should be sorted newest first
        $this->assertSame('Third', $all[0]['title']);
        $this->assertSame('Second', $all[1]['title']);
        $this->assertSame('First', $all[2]['title']);
    }

    public function testAllEmpty(): void
    {
        $this->assertSame([], $this->store->all());
    }

    public function testSummaries(): void
    {
        $this->store->save([
            'title' => 'Test Ability',
            'description' => 'A test description',
            'obstacle' => 'Some obstacle',
            'strategy' => 'Some strategy',
            'outcome' => 'Some outcome',
            'tags' => ['test', 'unit'],
            'created_at' => '2025-01-01T00:00:00+00:00',
        ]);

        $summaries = $this->store->summaries();

        $this->assertCount(1, $summaries);
        $summary = $summaries[0];

        // Summaries should only contain id, title, description, tags, created_at
        $this->assertArrayHasKey('id', $summary);
        $this->assertSame('Test Ability', $summary['title']);
        $this->assertSame('A test description', $summary['description']);
        $this->assertSame(['test', 'unit'], $summary['tags']);
        $this->assertSame('2025-01-01T00:00:00+00:00', $summary['created_at']);

        // Should NOT contain obstacle, strategy, outcome (those are details)
        $this->assertArrayNotHasKey('obstacle', $summary);
        $this->assertArrayNotHasKey('strategy', $summary);
        $this->assertArrayNotHasKey('outcome', $summary);
    }

    public function testGetMany(): void
    {
        $id1 = $this->store->save(['title' => 'First']);
        $id2 = $this->store->save(['title' => 'Second']);
        $id3 = $this->store->save(['title' => 'Third']);

        $results = $this->store->getMany([$id1, $id3]);

        $this->assertCount(2, $results);
        $titles = array_column($results, 'title');
        $this->assertContains('First', $titles);
        $this->assertContains('Third', $titles);
    }

    public function testGetManyWithNonExistent(): void
    {
        $id1 = $this->store->save(['title' => 'First']);

        $results = $this->store->getMany([$id1, 'nonexistent_id']);

        $this->assertCount(1, $results);
        $this->assertSame('First', $results[0]['title']);
    }

    public function testGetManyEmpty(): void
    {
        $results = $this->store->getMany([]);
        $this->assertSame([], $results);
    }

    public function testCount(): void
    {
        $this->assertSame(0, $this->store->count());

        $this->store->save(['title' => 'First']);
        $this->assertSame(1, $this->store->count());

        $this->store->save(['title' => 'Second']);
        $this->assertSame(2, $this->store->count());
    }

    public function testDelete(): void
    {
        $id = $this->store->save(['title' => 'To Delete']);
        $this->assertSame(1, $this->store->count());

        $result = $this->store->delete($id);
        $this->assertTrue($result);
        $this->assertSame(0, $this->store->count());
        $this->assertNull($this->store->get($id));
    }

    public function testDeleteNonExistent(): void
    {
        $result = $this->store->delete('nonexistent_id');
        $this->assertFalse($result);
    }

    public function testPersistenceAcrossInstances(): void
    {
        $id = $this->store->save([
            'title' => 'Persistent Ability',
            'description' => 'Should survive new instance',
        ]);

        // Create a new store pointing to same directory
        $newStore = new AbilityStore($this->tempDir);

        $retrieved = $newStore->get($id);
        $this->assertNotNull($retrieved);
        $this->assertSame('Persistent Ability', $retrieved['title']);
        $this->assertSame(1, $newStore->count());
    }

    public function testCorruptedJsonFileIsIgnored(): void
    {
        // Write a corrupt JSON file
        file_put_contents($this->tempDir . '/corrupt.json', 'not valid json{{{');

        $all = $this->store->all();
        $this->assertSame([], $all);
        $this->assertSame(1, $this->store->count()); // File exists but can't be parsed
    }

    public function testSaveCreatesValidJsonFile(): void
    {
        $id = $this->store->save(['title' => 'JSON Check']);

        $filePath = $this->tempDir . '/' . $id . '.json';
        $this->assertFileExists($filePath);

        $content = file_get_contents($filePath);
        $decoded = json_decode($content, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('JSON Check', $decoded['title']);
    }

    public function testUniqueIdGeneration(): void
    {
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $this->store->save(['title' => "Ability {$i}"]);
        }

        $uniqueIds = array_unique($ids);
        $this->assertCount(10, $uniqueIds);
    }
}
