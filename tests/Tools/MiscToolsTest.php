<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Conversation\ConversationHistory;
use Dalehurley\Phpbot\Conversation\ConversationLayer;
use Dalehurley\Phpbot\Registry\PersistentToolRegistry;
use Dalehurley\Phpbot\Tools\AskUserTool;
use Dalehurley\Phpbot\Tools\ConversationContextTool;
use Dalehurley\Phpbot\Tools\SearchCapabilitiesTool;
use ClaudeAgents\Skills\SkillManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mockery;

#[CoversClass(SearchCapabilitiesTool::class)]
#[CoversClass(AskUserTool::class)]
#[CoversClass(ConversationContextTool::class)]
class MiscToolsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-misc-' . uniqid();
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

    // SearchCapabilitiesTool - returns plain string, NOT JSON
    public function testSearchCapabilitiesReturnsPlainString(): void
    {
        $registry = new PersistentToolRegistry($this->tmpDir);
        $registry->register(new \Dalehurley\Phpbot\Tools\ReadFileTool());
        $tool = new SearchCapabilitiesTool(null, $registry);

        $result = $tool->execute(['query' => 'file']);
        $this->assertFalse($result->isError());
        $content = $result->getContent();
        $this->assertIsString($content);
        // Do NOT json_decode - it returns markdown/text, not JSON
        $this->assertStringContainsString('file', strtolower($content));
    }

    public function testSearchCapabilitiesEmptyQueryReturnsError(): void
    {
        $tool = new SearchCapabilitiesTool();
        $result = $tool->execute(['query' => '']);
        $this->assertTrue($result->isError());
    }

    public function testSearchCapabilitiesLoadSkillWithoutManager(): void
    {
        $tool = new SearchCapabilitiesTool(null, null);
        $result = $tool->execute(['query' => 'x', 'load_skill' => 'pdf']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Skill manager not available', $result->getContent());
    }

    public function testSearchCapabilitiesLoadToolInfoWithoutRegistry(): void
    {
        $tool = new SearchCapabilitiesTool(null, null);
        $result = $tool->execute(['query' => 'x', 'load_tool_info' => 'read_file']);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Tool registry not available', $result->getContent());
    }

    public function testSearchCapabilitiesLoadToolInfo(): void
    {
        $registry = new PersistentToolRegistry($this->tmpDir);
        $registry->register(new \Dalehurley\Phpbot\Tools\ReadFileTool());
        $tool = new SearchCapabilitiesTool(null, $registry);
        $result = $tool->execute(['query' => 'irrelevant', 'load_tool_info' => 'read_file']);
        $this->assertFalse($result->isError());
        $content = $result->getContent();
        $this->assertStringContainsString('read_file', $content);
        $this->assertStringContainsString('Input Schema', $content);
    }

    // AskUserTool - minimal metadata tests
    public function testAskUserMetadata(): void
    {
        $tool = new AskUserTool();
        $this->assertSame('ask_user', $tool->getName());
        $this->assertStringContainsString('user', strtolower($tool->getDescription()));
    }

    // ConversationContextTool
    public function testConversationContextGetContextEmpty(): void
    {
        $history = new ConversationHistory(ConversationLayer::Basic);
        $tool = new ConversationContextTool($history);
        $result = $tool->execute(['action' => 'get_context']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame('empty', $data['status'] ?? null);
    }

    public function testConversationContextSwitchLayer(): void
    {
        $history = new ConversationHistory(ConversationLayer::Basic);
        $tool = new ConversationContextTool($history);
        $result = $tool->execute(['action' => 'switch_layer', 'layer' => 'full']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame('full', $data['new_layer'] ?? null);
    }

    public function testConversationContextSwitchLayerInvalid(): void
    {
        $history = new ConversationHistory(ConversationLayer::Basic);
        $tool = new ConversationContextTool($history);
        $result = $tool->execute(['action' => 'switch_layer', 'layer' => 'invalid']);
        $this->assertTrue($result->isError());
    }

    public function testConversationContextGetTurnDetailEmpty(): void
    {
        $history = new ConversationHistory(ConversationLayer::Basic);
        $tool = new ConversationContextTool($history);
        $result = $tool->execute(['action' => 'get_turn_detail', 'turn_index' => 1]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame('empty', $data['status'] ?? null);
    }

    public function testConversationContextUnknownAction(): void
    {
        $history = new ConversationHistory(ConversationLayer::Basic);
        $tool = new ConversationContextTool($history);
        $result = $tool->execute(['action' => 'unknown']);
        $this->assertTrue($result->isError());
    }

    public function testConversationContextToDefinition(): void
    {
        $history = new ConversationHistory(ConversationLayer::Basic);
        $tool = new ConversationContextTool($history);
        $def = $tool->toDefinition();
        $this->assertSame('conversation_context', $def['name']);
    }
}
