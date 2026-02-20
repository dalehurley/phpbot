<?php

declare(strict_types=1);

namespace Tests\Router;

use ClaudeAgents\Skills\SkillManager;
use Dalehurley\Phpbot\Registry\PersistentToolRegistry;
use Dalehurley\Phpbot\Router\ClassifierClient;
use Dalehurley\Phpbot\Router\RouterCache;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouterCache::class)]
class RouterCacheTest extends TestCase
{
    private string $tmpDir;
    private RouterCache $cache;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-router-cache-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->cache = new RouterCache($this->tmpDir);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->rmrf($this->tmpDir);
        parent::tearDown();
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

    public function testLoadReturnsFalseWhenFileDoesNotExist(): void
    {
        $result = $this->cache->load();
        $this->assertFalse($result);
    }

    public function testIsLoadedReturnsFalseWhenEmpty(): void
    {
        $this->assertFalse($this->cache->isLoaded());
    }

    public function testSaveAndLoad(): void
    {
        $this->cache->appendTool('test_tool', 'A test tool');
        $this->cache->save();

        $fresh = new RouterCache($this->tmpDir);
        $loaded = $fresh->load();
        $this->assertTrue($loaded);
        $this->assertTrue($fresh->isLoaded());
        $this->assertArrayHasKey('test_tool', $fresh->getToolIndex());
    }

    public function testLoadReturnsFalseForInvalidJson(): void
    {
        file_put_contents($this->tmpDir . '/router_cache.json', 'invalid json');
        $result = $this->cache->load();
        $this->assertFalse($result);
    }

    public function testGetInstantAnswersReturnsEmptyWhenNotLoaded(): void
    {
        $this->assertSame([], $this->cache->getInstantAnswers());
    }

    public function testGetBashCommandsReturnsEmptyWhenNotLoaded(): void
    {
        $this->assertSame([], $this->cache->getBashCommands());
    }

    public function testGetToolIndexReturnsEmptyWhenNotLoaded(): void
    {
        $this->assertSame([], $this->cache->getToolIndex());
    }

    public function testGetSkillIndexReturnsEmptyWhenNotLoaded(): void
    {
        $this->assertSame([], $this->cache->getSkillIndex());
    }

    public function testGetCategoriesReturnsEmptyWhenNotLoaded(): void
    {
        $this->assertSame([], $this->cache->getCategories());
    }

    public function testAppendTool(): void
    {
        $this->cache->appendTool('my_tool', 'Description of my tool');
        $index = $this->cache->getToolIndex();
        $this->assertArrayHasKey('my_tool', $index);
        $this->assertSame('Description of my tool', $index['my_tool']);
        $this->assertFileExists($this->tmpDir . '/router_cache.json');
    }

    public function testAppendSkill(): void
    {
        $this->cache->appendSkill('my_skill', 'Skill description', ['tag1']);
        $index = $this->cache->getSkillIndex();
        $this->assertArrayHasKey('my_skill', $index);
        $this->assertSame('Skill description', $index['my_skill']);
    }

    public function testAppendBashCommand(): void
    {
        $this->cache->appendBashCommand('pattern', 'echo hello');
        $cmds = $this->cache->getBashCommands();
        $this->assertArrayHasKey('pattern', $cmds);
        $this->assertSame('echo hello', $cmds['pattern']);
    }

    public function testIsStaleReturnsTrueWhenNotLoaded(): void
    {
        $skillManager = Mockery::mock(SkillManager::class);
        $toolRegistry = Mockery::mock(PersistentToolRegistry::class);

        $this->assertTrue($this->cache->isStale($skillManager, $toolRegistry));
    }

    public function testIsStaleReturnsTrueWhenNewSkills(): void
    {
        $manifest = [
            'skill_index' => ['old_skill' => 'desc'],
            'tool_index' => ['old_tool' => 'desc'],
        ];
        file_put_contents($this->tmpDir . '/router_cache.json', json_encode($manifest));
        $this->cache->load();

        $skillManager = Mockery::mock(SkillManager::class);
        $skillManager->shouldReceive('summaries')->andReturn([
            'old_skill' => ['description' => 'desc'],
            'new_skill' => ['description' => 'new'],
        ]);

        $toolRegistry = Mockery::mock(PersistentToolRegistry::class);
        $toolRegistry->shouldReceive('names')->andReturn(['old_tool']);

        $this->assertTrue($this->cache->isStale($skillManager, $toolRegistry));
    }

    public function testIsStaleReturnsTrueWhenNewTools(): void
    {
        $manifest = [
            'skill_index' => ['skill' => 'desc'],
            'tool_index' => ['old_tool' => 'desc'],
        ];
        file_put_contents($this->tmpDir . '/router_cache.json', json_encode($manifest));
        $this->cache->load();

        $skillManager = Mockery::mock(SkillManager::class);
        $skillManager->shouldReceive('summaries')->andReturn(['skill' => ['description' => 'desc']]);

        $toolRegistry = Mockery::mock(PersistentToolRegistry::class);
        $toolRegistry->shouldReceive('names')->andReturn(['old_tool', 'new_tool']);

        $this->assertTrue($this->cache->isStale($skillManager, $toolRegistry));
    }

    public function testIsStaleReturnsFalseWhenInSync(): void
    {
        $manifest = [
            'skill_index' => ['skill' => 'desc'],
            'tool_index' => ['tool' => 'desc'],
        ];
        file_put_contents($this->tmpDir . '/router_cache.json', json_encode($manifest));
        $this->cache->load();

        $skillManager = Mockery::mock(SkillManager::class);
        $skillManager->shouldReceive('summaries')->andReturn(['skill' => ['description' => 'desc']]);

        $toolRegistry = Mockery::mock(PersistentToolRegistry::class);
        $toolRegistry->shouldReceive('names')->andReturn(['tool']);

        $this->assertFalse($this->cache->isStale($skillManager, $toolRegistry));
    }

    public function testSyncAppendsNewSkillsAndTools(): void
    {
        $manifest = [
            'skill_index' => ['existing_skill' => 'desc'],
            'tool_index' => ['existing_tool' => 'desc'],
        ];
        file_put_contents($this->tmpDir . '/router_cache.json', json_encode($manifest));
        $this->cache->load();

        $skillManager = Mockery::mock(SkillManager::class);
        $skillManager->shouldReceive('summaries')->andReturn([
            'existing_skill' => ['description' => 'desc'],
            'new_skill' => ['description' => 'new skill desc'],
        ]);

        $existingTool = Mockery::mock();
        $existingTool->shouldReceive('getName')->andReturn('existing_tool');
        $existingTool->shouldReceive('getDescription')->andReturn('desc');

        $newTool = Mockery::mock();
        $newTool->shouldReceive('getName')->andReturn('new_tool');
        $newTool->shouldReceive('getDescription')->andReturn('new tool desc');

        $toolRegistry = Mockery::mock(PersistentToolRegistry::class);
        $toolRegistry->shouldReceive('names')->andReturn(['existing_tool', 'new_tool']);
        $toolRegistry->shouldReceive('all')->andReturn([$existingTool, $newTool]);

        $this->cache->sync($skillManager, $toolRegistry);

        $skillIndex = $this->cache->getSkillIndex();
        $toolIndex = $this->cache->getToolIndex();
        $this->assertArrayHasKey('new_skill', $skillIndex);
        $this->assertArrayHasKey('new_tool', $toolIndex);
    }

    public function testGenerateCreatesFullManifest(): void
    {
        $categoriesJson = json_encode([
            ['id' => 'test_cat', 'patterns' => ['test'], 'tools' => ['bash'], 'skills' => [], 'agent_type' => 'react', 'prompt_tier' => 'minimal'],
        ]);

        $classifier = Mockery::mock(ClassifierClient::class);
        $classifier->shouldReceive('classify')->with(Mockery::type('string'), 4096)->andReturn($categoriesJson);

        $skillManager = Mockery::mock(SkillManager::class);
        $skillManager->shouldReceive('summaries')->andReturn(['test_skill' => ['description' => 'A skill']]);

        $tool = Mockery::mock();
        $tool->shouldReceive('getName')->andReturn('test_tool');
        $tool->shouldReceive('getDescription')->andReturn('A tool description');

        $toolRegistry = Mockery::mock(PersistentToolRegistry::class);
        $toolRegistry->shouldReceive('names')->andReturn(['test_tool']);
        $toolRegistry->shouldReceive('all')->andReturn([$tool]);

        $this->cache->generate($classifier, $skillManager, $toolRegistry);

        $this->assertTrue($this->cache->isLoaded());
        $this->assertArrayHasKey('version', $this->cache->getManifest());
        $this->assertArrayHasKey('generated_at', $this->cache->getManifest());
        $this->assertNotEmpty($this->cache->getInstantAnswers());
        $this->assertNotEmpty($this->cache->getBashCommands());
        $this->assertArrayHasKey('test_tool', $this->cache->getToolIndex());
        $this->assertArrayHasKey('test_skill', $this->cache->getSkillIndex());
        $this->assertFileExists($this->tmpDir . '/router_cache.json');
    }

    public function testGenerateUsesDefaultCategoriesWhenClassifierThrows(): void
    {
        $classifier = Mockery::mock(ClassifierClient::class);
        $classifier->shouldReceive('classify')->andThrow(new \RuntimeException('LLM unavailable'));

        $skillManager = Mockery::mock(SkillManager::class);
        $skillManager->shouldReceive('summaries')->andReturn([]);

        $toolRegistry = Mockery::mock(PersistentToolRegistry::class);
        $toolRegistry->shouldReceive('names')->andReturn([]);
        $toolRegistry->shouldReceive('all')->andReturn([]);

        $this->cache->generate($classifier, $skillManager, $toolRegistry);

        $categories = $this->cache->getCategories();
        $this->assertNotEmpty($categories);
    }

    public function testGetManifestReturnsFullManifest(): void
    {
        $this->cache->appendTool('t', 'd');
        $manifest = $this->cache->getManifest();
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('tool_index', $manifest);
    }
}
