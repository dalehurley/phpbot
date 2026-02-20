<?php

declare(strict_types=1);

namespace Tests\Apple;

use Dalehurley\Phpbot\Apple\AppleFMClient;
use Dalehurley\Phpbot\Apple\AppleFMContextCompactor;
use Dalehurley\Phpbot\Apple\AppleFMSimpleAgent;
use Dalehurley\Phpbot\Apple\SmallModelClient;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppleFMClient::class)]
#[CoversClass(AppleFMSimpleAgent::class)]
#[CoversClass(AppleFMContextCompactor::class)]
class AppleFMTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phpbot_applefm_test_' . uniqid();
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

    public function testAppleFMClientIsAvailableNonDarwin(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('Skipping on Darwin - test checks non-Darwin path');
        }
        $client = new AppleFMClient($this->tempDir);
        $this->assertFalse($client->isAvailable());
    }

    public function testAppleFMClientIsAvailableNoBinary(): void
    {
        $client = new AppleFMClient($this->tempDir);
        $available = $client->isAvailable();
        $this->assertIsBool($available);
    }

    public function testAppleFMClientSetLogger(): void
    {
        $client = new AppleFMClient($this->tempDir);
        $client->setLogger(fn(string $m) => null);
        $this->assertIsBool($client->isAvailable());
    }

    public function testAppleFMSimpleAgentConstructor(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $agent = new AppleFMSimpleAgent($appleFM, null);
        $this->assertInstanceOf(AppleFMSimpleAgent::class, $agent);
    }

    public function testAppleFMSimpleAgentCanHandle(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $appleFM->shouldReceive('isAvailable')->andReturn(true);

        $agent = new AppleFMSimpleAgent($appleFM, null);
        $tools = ['bash', 'search_capabilities'];
        $this->assertTrue($agent->canHandle($tools, 'simple'));
        $this->assertFalse($agent->canHandle($tools, 'complex'));
        $this->assertFalse($agent->canHandle(['write_file'], 'simple'));
    }

    public function testAppleFMSimpleAgentCanHandleWhenUnavailable(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $appleFM->shouldReceive('isAvailable')->andReturn(false);

        $agent = new AppleFMSimpleAgent($appleFM, null);
        $this->assertFalse($agent->canHandle(['bash'], 'simple'));
    }

    public function testAppleFMSimpleAgentSetLogger(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $agent = new AppleFMSimpleAgent($appleFM, null);
        $agent->setLogger(fn(string $m) => null);
        $this->assertInstanceOf(AppleFMSimpleAgent::class, $agent);
    }

    public function testAppleFMContextCompactorConstructor(): void
    {
        $compactor = new AppleFMContextCompactor(null, null);
        $this->assertInstanceOf(AppleFMContextCompactor::class, $compactor);
    }

    public function testAppleFMContextCompactorBasicInstantiation(): void
    {
        $appleFM = Mockery::mock(SmallModelClient::class);
        $compactor = new AppleFMContextCompactor($appleFM, null, 80000);
        $this->assertInstanceOf(AppleFMContextCompactor::class, $compactor);
    }
}
