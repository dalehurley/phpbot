<?php

declare(strict_types=1);

namespace Tests\Router;

use Dalehurley\Phpbot\Apple\SmallModelClient;
use Dalehurley\Phpbot\Router\ClassifierClient;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClassifierClient::class)]
class ClassifierClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testProviderSelectionExplicit(): void
    {
        $config = ['groq_api_key' => 'test-key'];
        $clientFactory = fn() => throw new \RuntimeException('Should not be called');
        $client = new ClassifierClient(
            config: $config,
            providerOverride: 'anthropic',
            clientFactory: $clientFactory,
            fastModel: 'claude-haiku-4-5',
        );

        $this->assertSame('anthropic', $client->getProvider());
    }

    public function testProviderSelectionAutoWithAppleFM(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $appleFM->shouldReceive('isAvailable')->andReturn(true);
        $appleFM->shouldReceive('classify')->withArgs(function ($prompt, $maxTokens) {
            return is_string($prompt) && $maxTokens === 256;
        })->andReturn('{"category_id":"general","tools":["bash"]}');

        $client = new ClassifierClient(
            config: [],
            providerOverride: 'apple_fm',
            clientFactory: fn() => throw new \RuntimeException('Should not use Anthropic'),
            fastModel: 'claude-haiku-4-5',
        );
        $client->setAppleFMClient($appleFM);

        $result = $client->classify('Classify: test');
        $this->assertStringContainsString('general', $result);
    }

    public function testSetLogger(): void
    {
        $logMessages = [];
        $appleFM = Mockery::mock(SmallModelClient::class);
        $appleFM->shouldReceive('isAvailable')->andReturn(true);
        $appleFM->shouldReceive('classify')->andReturn('{}');

        $client = new ClassifierClient(
            config: [],
            providerOverride: 'apple_fm',
            clientFactory: fn() => null,
            fastModel: 'claude-haiku-4-5',
        );
        $client->setAppleFMClient($appleFM);
        $client->setLogger(function (string $msg) use (&$logMessages) {
            $logMessages[] = $msg;
        });

        $client->classify('test');
        $this->assertNotEmpty($logMessages);
    }

    public function testIsAvailableWithAppleFM(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $appleFM->shouldReceive('isAvailable')->andReturn(true);

        $client = new ClassifierClient(
            config: [],
            providerOverride: 'apple_fm',
            clientFactory: fn() => null,
            fastModel: 'claude-haiku-4-5',
        );
        $client->setAppleFMClient($appleFM);

        $this->assertTrue($client->isAvailable());
    }

    public function testIsAvailableAnthropicFallback(): void
    {
        $client = new ClassifierClient(
            config: [],
            providerOverride: 'anthropic',
            clientFactory: fn() => null,
            fastModel: 'claude-haiku-4-5',
        );
        $this->assertTrue($client->isAvailable());
    }

    public function testClassifyViaAppleFM(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $appleFM->shouldReceive('isAvailable')->andReturn(true);
        $appleFM->shouldReceive('classify')
            ->withArgs(function ($p, $t) {
                return str_contains($p, 'Classify') && $t === 256;
            })
            ->andReturn('{"category_id":"test","tools":["bash"]}');

        $client = new ClassifierClient(
            config: [],
            providerOverride: 'apple_fm',
            clientFactory: fn() => null,
            fastModel: 'claude-haiku-4-5',
        );
        $client->setAppleFMClient($appleFM);

        $result = $client->classify('Classify this request');
        $this->assertStringContainsString('test', $result);
    }
}
