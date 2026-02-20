<?php

declare(strict_types=1);

namespace Tests;

use Dalehurley\Phpbot\AgentFactory;
use Dalehurley\Phpbot\Bot;
use Dalehurley\Phpbot\Conversation\ConversationHistory;
use Dalehurley\Phpbot\Conversation\ConversationLayer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Bot::class)]
#[CoversClass(AgentFactory::class)]
class BotTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phpbot_bot_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $storageDir = $this->tempDir . '/storage';
        mkdir($storageDir, 0755, true);
        mkdir($storageDir . '/tools', 0755, true);
        mkdir($storageDir . '/backups', 0755, true);
        mkdir($storageDir . '/rollback', 0755, true);
        mkdir($storageDir . '/cache', 0755, true);
        mkdir($storageDir . '/history', 0755, true);
        mkdir($storageDir . '/checkpoints', 0755, true);

        $routerCache = [
            'version' => 1,
            'categories' => [],
            'bash_commands' => [],
            'tool_index' => ['bash' => 'Run commands'],
            'skill_index' => [],
        ];
        file_put_contents($storageDir . '/router_cache.json', json_encode($routerCache));
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->rmrf($full) : unlink($full);
        }
        rmdir($path);
    }

    private function createBotConfig(): array
    {
        return [
            'api_key' => getenv('ANTHROPIC_API_KEY') ?: 'test-key',
            'tools_storage_path' => $this->tempDir . '/storage/tools',
            'files_storage_path' => $this->tempDir . '/storage/files',
            'skills_path' => $this->tempDir . '/skills',
            'keys_storage_path' => $this->tempDir . '/storage/keys.json',
            'vendor_tools_enabled' => false,
            'apple_fm' => ['enabled' => false],
        ];
    }

    public function testBotGetSessionId(): void
    {
        $bot = new Bot($this->createBotConfig(), false);
        $id = $bot->getSessionId();
        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-[a-f0-9]+$/', $id);
    }

    public function testBotGetBackupManager(): void
    {
        $bot = new Bot($this->createBotConfig(), false);
        $this->assertInstanceOf(\Dalehurley\Phpbot\Storage\BackupManager::class, $bot->getBackupManager());
    }

    public function testBotGetRollbackManager(): void
    {
        $bot = new Bot($this->createBotConfig(), false);
        $this->assertInstanceOf(\Dalehurley\Phpbot\Storage\RollbackManager::class, $bot->getRollbackManager());
    }

    public function testBotGetCacheManager(): void
    {
        $bot = new Bot($this->createBotConfig(), false);
        $this->assertInstanceOf(\Dalehurley\Phpbot\Cache\CacheManager::class, $bot->getCacheManager());
    }

    public function testBotGetTaskHistory(): void
    {
        $bot = new Bot($this->createBotConfig(), false);
        $history = $bot->getTaskHistory();
        $this->assertInstanceOf(\Dalehurley\Phpbot\Storage\TaskHistory::class, $history);
    }

    public function testBotListTools(): void
    {
        $bot = new Bot($this->createBotConfig(), false);
        $tools = $bot->listTools();
        $this->assertIsArray($tools);
        $this->assertContains('bash', $tools);
    }

    public function testBotListSkills(): void
    {
        $bot = new Bot($this->createBotConfig(), false);
        $skills = $bot->listSkills();
        $this->assertIsArray($skills);
    }

    public function testBotSetLogger(): void
    {
        $bot = new Bot($this->createBotConfig(), false);
        $bot->setLogger(fn(string $m) => null);
        $this->assertNotEmpty($bot->getSessionId());
    }

    public function testBotSetConversationHistory(): void
    {
        $bot = new Bot($this->createBotConfig(), false);
        $history = new ConversationHistory(ConversationLayer::Basic);
        $bot->setConversationHistory($history);
        $this->assertSame($history, $bot->getConversationHistory());
    }

    public function testBotGetConversationHistoryInitiallyNull(): void
    {
        $bot = new Bot($this->createBotConfig(), false);
        $this->assertNull($bot->getConversationHistory());
    }

    public function testAgentFactoryGetSystemPrompt(): void
    {
        $factory = new AgentFactory(fn() => null, ['model' => 'claude-sonnet-4-5'], false);
        $analysis = [
            'definition_of_done' => ['Complete task'],
            'skill_matched' => false,
            'complexity' => 'medium',
        ];
        $prompt = $factory->getSystemPrompt($analysis);
        $this->assertStringContainsString('PhpBot', $prompt);
        $this->assertStringContainsString('Complete task', $prompt);
    }

    public function testAgentFactoryBuildEnhancedPrompt(): void
    {
        $factory = new AgentFactory(fn() => null, [], false);
        $analysis = [
            'complexity' => 'simple',
            'suggested_approach' => 'direct',
            'estimated_steps' => 5,
            'definition_of_done' => ['Done'],
        ];
        $prompt = $factory->buildEnhancedPrompt('Create a file', $analysis, []);
        $this->assertStringContainsString('Create a file', $prompt);
        $this->assertStringContainsString('Task', $prompt);
    }

    public function testAgentFactorySetLogger(): void
    {
        $factory = new AgentFactory(fn() => null, [], false);
        $factory->setLogger(fn(string $m) => null);
        $this->assertNotNull($factory);
    }
}
