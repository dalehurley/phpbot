<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tests;

use PHPUnit\Framework\TestCase;
use Dalehurley\Phpbot\Ability\AbilityRetriever;
use Dalehurley\Phpbot\Storage\AbilityStore;
use ClaudePhp\ClaudePhp;
use ReflectionMethod;

class AbilityRetrieverTest extends TestCase
{
    private string $tempDir;
    private AbilityStore $store;
    private AbilityRetriever $retriever;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpbot_test_retriever_' . uniqid();
        $this->store = new AbilityStore($this->tempDir);

        $client = $this->createMock(ClaudePhp::class);
        $this->retriever = new AbilityRetriever($client, $this->store, 'claude-haiku-4-5');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
    }

    // --- Tests for parseIds (private method via reflection) ---

    private function invokeParseIds(string $answer): array
    {
        $method = new ReflectionMethod(AbilityRetriever::class, 'parseIds');
        $method->setAccessible(true);
        return $method->invoke($this->retriever, $answer);
    }

    public function testParseIdsWithValidJson(): void
    {
        $result = $this->invokeParseIds('["ability_001", "ability_002"]');
        $this->assertSame(['ability_001', 'ability_002'], $result);
    }

    public function testParseIdsWithEmptyArray(): void
    {
        $result = $this->invokeParseIds('[]');
        $this->assertSame([], $result);
    }

    public function testParseIdsWithMarkdownCodeBlock(): void
    {
        $answer = "```json\n[\"ability_001\"]\n```";
        $result = $this->invokeParseIds($answer);
        $this->assertSame(['ability_001'], $result);
    }

    public function testParseIdsWithBareCodeBlock(): void
    {
        $answer = "```\n[\"ability_001\", \"ability_002\"]\n```";
        $result = $this->invokeParseIds($answer);
        $this->assertSame(['ability_001', 'ability_002'], $result);
    }

    public function testParseIdsWithSurroundingText(): void
    {
        $answer = "Based on the task, these are relevant:\n[\"ability_001\"]\nThose should help.";
        $result = $this->invokeParseIds($answer);
        $this->assertSame(['ability_001'], $result);
    }

    public function testParseIdsWithInvalidJson(): void
    {
        $result = $this->invokeParseIds('not json');
        $this->assertSame([], $result);
    }

    public function testParseIdsFiltersOutNonStrings(): void
    {
        $result = $this->invokeParseIds('["ability_001", 42, null, true, "ability_002"]');
        $this->assertSame(['ability_001', 'ability_002'], $result);
    }

    // --- Tests for formatGuidance (private method via reflection) ---

    private function invokeFormatGuidance(array $abilities): string
    {
        $method = new ReflectionMethod(AbilityRetriever::class, 'formatGuidance');
        $method->setAccessible(true);
        return $method->invoke($this->retriever, $abilities);
    }

    public function testFormatGuidanceWithFullAbility(): void
    {
        $abilities = [
            [
                'title' => 'Retry API on 503',
                'obstacle' => 'API returned 503 intermittently',
                'strategy' => 'Exponential backoff retry',
                'outcome' => 'Calls succeed after retries',
                'description' => 'Handles flaky APIs gracefully',
            ],
        ];

        $guidance = $this->invokeFormatGuidance($abilities);

        $this->assertStringContainsString('## Previously Learned Abilities', $guidance);
        $this->assertStringContainsString('### Retry API on 503', $guidance);
        $this->assertStringContainsString('**Obstacle encountered:** API returned 503 intermittently', $guidance);
        $this->assertStringContainsString('**Strategy that worked:** Exponential backoff retry', $guidance);
        $this->assertStringContainsString('**Outcome:** Calls succeed after retries', $guidance);
        $this->assertStringContainsString('Handles flaky APIs gracefully', $guidance);
    }

    public function testFormatGuidanceWithMinimalAbility(): void
    {
        $abilities = [
            [
                'title' => 'Minimal Ability',
            ],
        ];

        $guidance = $this->invokeFormatGuidance($abilities);

        $this->assertStringContainsString('### Minimal Ability', $guidance);
        $this->assertStringNotContainsString('**Obstacle encountered:**', $guidance);
        $this->assertStringNotContainsString('**Strategy that worked:**', $guidance);
        $this->assertStringNotContainsString('**Outcome:**', $guidance);
    }

    public function testFormatGuidanceMultipleAbilities(): void
    {
        $abilities = [
            ['title' => 'First', 'strategy' => 'Do A'],
            ['title' => 'Second', 'strategy' => 'Do B'],
        ];

        $guidance = $this->invokeFormatGuidance($abilities);

        $this->assertStringContainsString('### First', $guidance);
        $this->assertStringContainsString('### Second', $guidance);
        $this->assertStringContainsString('Do A', $guidance);
        $this->assertStringContainsString('Do B', $guidance);
    }

    // --- Tests for retrieve with empty store ---

    public function testRetrieveReturnsEmptyStringWhenNoAbilities(): void
    {
        $result = $this->retriever->retrieve('Some task');
        $this->assertSame('', $result);
    }
}
