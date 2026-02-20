<?php

declare(strict_types=1);

namespace Tests\Router;

use Dalehurley\Phpbot\Router\CachedRouter;
use Dalehurley\Phpbot\Router\ClassifierClient;
use Dalehurley\Phpbot\Router\RouteResult;
use Dalehurley\Phpbot\Router\RouterCache;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CachedRouter::class)]
#[CoversClass(RouteResult::class)]
class CachedRouterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phpbot_router_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        if (is_dir($this->tempDir)) {
            $this->rmrf($this->tempDir);
        }
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

    private function createRouterWithCache(array $manifest): CachedRouter
    {
        $path = $this->tempDir . '/router_cache.json';
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT));
        $cache = new RouterCache($this->tempDir);
        $cache->load();

        $classifier = Mockery::mock(ClassifierClient::class)->makePartial();

        return new CachedRouter($cache, $classifier, null);
    }

    public function testTier0InstantTime(): void
    {
        $router = $this->createRouterWithCache([
            'version' => 1,
            'categories' => [],
            'bash_commands' => [],
            'tool_index' => [],
            'skill_index' => [],
        ]);

        $result = $router->route('what time is it');
        $this->assertSame(RouteResult::TIER_INSTANT, $result->tier);
        $this->assertTrue($result->isEarlyExit());
        $this->assertStringContainsString('current time', $result->resolve());
    }

    public function testTier0InstantDate(): void
    {
        $router = $this->createRouterWithCache([
            'version' => 1,
            'categories' => [],
            'bash_commands' => [],
            'tool_index' => [],
            'skill_index' => [],
        ]);

        $result = $router->route("what's the date");
        $this->assertSame(RouteResult::TIER_INSTANT, $result->tier);
        $this->assertTrue($result->isEarlyExit());
        $this->assertStringContainsString('Today is', $result->resolve());
    }

    public function testTier0InstantGreeting(): void
    {
        $router = $this->createRouterWithCache([
            'version' => 1,
            'categories' => [],
            'bash_commands' => [],
            'tool_index' => ['bash' => 'Run commands'],
            'skill_index' => ['test' => 'Test skill'],
        ]);

        $result = $router->route('hello');
        $this->assertSame(RouteResult::TIER_INSTANT, $result->tier);
        $this->assertTrue($result->isEarlyExit());
        $this->assertStringContainsString('PhpBot', $result->resolve());
    }

    public function testTier0InstantCapabilities(): void
    {
        $router = $this->createRouterWithCache([
            'version' => 1,
            'categories' => [],
            'bash_commands' => [],
            'tool_index' => ['bash' => 'Run shell'],
            'skill_index' => ['skill1' => 'Desc'],
        ]);

        $result = $router->route('what can you do');
        $this->assertSame(RouteResult::TIER_INSTANT, $result->tier);
        $this->assertTrue($result->isEarlyExit());
        $this->assertStringContainsString('Skills', $result->resolve());
        $this->assertStringContainsString('Core Tools', $result->resolve());
    }

    public function testTier1BashFromCache(): void
    {
        $router = $this->createRouterWithCache([
            'version' => 1,
            'categories' => [],
            'bash_commands' => [
                'uptime|how long running|system uptime' => 'uptime',
                'whoami|who am i' => 'whoami',
            ],
            'tool_index' => [],
            'skill_index' => [],
        ]);

        $result = $router->route('who am i');
        $this->assertSame(RouteResult::TIER_BASH, $result->tier);
        $this->assertTrue($result->isEarlyExit());
        $output = $result->resolve();
        $this->assertNotEmpty($output);
    }

    public function testTier2CachedCategory(): void
    {
        $router = $this->createRouterWithCache([
            'version' => 1,
            'categories' => [
                [
                    'id' => 'file_ops',
                    'patterns' => ['read file', 'write file', 'create file', 'edit file'],
                    'tools' => ['bash', 'read_file', 'write_file', 'search_capabilities'],
                    'skills' => [],
                    'agent_type' => 'react',
                    'prompt_tier' => 'standard',
                ],
            ],
            'bash_commands' => [],
            'tool_index' => [],
            'skill_index' => [],
        ]);

        $result = $router->route('create a file');
        $this->assertSame(RouteResult::TIER_CACHED, $result->tier);
        $this->assertFalse($result->isEarlyExit());
        $this->assertContains('bash', $result->tools);
        $this->assertContains('search_capabilities', $result->tools);
    }

    public function testTier3aPhpClassifier(): void
    {
        $router = $this->createRouterWithCache([
            'version' => 1,
            'categories' => [
                [
                    'id' => 'send-sms',
                    'patterns' => ['send sms', 'text someone', 'send message'],
                    'tools' => ['bash', 'search_capabilities'],
                    'skills' => [],
                    'agent_type' => 'react',
                    'prompt_tier' => 'standard',
                ],
            ],
            'bash_commands' => [],
            'tool_index' => [],
            'skill_index' => [],
        ]);

        $result = $router->route('send sms to john');
        $this->assertContains(RouteResult::TIER_CACHED, [$result->tier, RouteResult::TIER_CLASSIFIED]);
        $this->assertFalse($result->isEarlyExit());
        if ($result->tier === RouteResult::TIER_CACHED) {
            $this->assertNotEmpty($result->tools);
        }
    }

    public function testTier3bLlmClassifier(): void
    {
        $path = $this->tempDir . '/router_cache.json';
        file_put_contents($path, json_encode([
            'version' => 1,
            'categories' => [['id' => 'xyz', 'patterns' => ['qwerty zzz aaa bbb ccc']]],
            'bash_commands' => [],
            'tool_index' => [],
            'skill_index' => [],
        ], JSON_PRETTY_PRINT));
        $cache = new RouterCache($this->tempDir);
        $cache->load();

        $classifier = Mockery::mock(ClassifierClient::class);
        $classifier->shouldReceive('classify')
            ->andReturn('{"category_id":"general","tools":["bash","search_capabilities"],"agent_type":"react","prompt_tier":"standard"}');

        $router = new CachedRouter($cache, $classifier, null);
        $result = $router->route('analyze my stock portfolio and suggest diversification');
        $this->assertSame(RouteResult::TIER_CLASSIFIED, $result->tier);
        $this->assertFalse($result->isEarlyExit());
        $this->assertContains('bash', $result->tools);
    }

    public function testRouteResultResolveThrowsForNonEarlyExit(): void
    {
        $result = RouteResult::cached(tools: ['bash'], skills: []);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('resolve() can only be called on early-exit routes');
        $result->resolve();
    }

    public function testRouteResultInstantResolve(): void
    {
        $result = RouteResult::instant('Hello world');
        $this->assertSame('Hello world', $result->resolve());
    }

    public function testRouteResultToolsAndSkillsAccess(): void
    {
        $result = RouteResult::cached(
            tools: ['bash', 'read_file'],
            skills: ['send-sms'],
            agentType: 'react',
            promptTier: 'standard',
            confidence: 0.8
        );
        $this->assertSame(['bash', 'read_file'], $result->tools);
        $this->assertSame(['send-sms'], $result->skills);
        $this->assertSame('react', $result->agentType);
        $this->assertSame(0.8, $result->confidence);
    }

    public function testRouteResultToAnalysis(): void
    {
        $result = RouteResult::cached(
            tools: ['bash', 'read_file'],
            skills: ['skill1'],
            agentType: 'plan_execute',
            promptTier: 'full',
            confidence: 0.9
        );
        $analysis = $result->toAnalysis();
        $this->assertTrue($analysis['requires_bash']);
        $this->assertTrue($analysis['requires_planning']);
        $this->assertTrue($analysis['skill_matched']);
        $this->assertContains('read_file', $analysis['potential_tools_needed']);
    }

    public function testSetLogger(): void
    {
        $router = $this->createRouterWithCache([
            'version' => 1,
            'categories' => [],
            'bash_commands' => [],
            'tool_index' => [],
            'skill_index' => [],
        ]);
        $router->setLogger(fn(string $m) => null);
        $result = $router->route('hello');
        $this->assertSame(RouteResult::TIER_INSTANT, $result->tier);
    }
}
