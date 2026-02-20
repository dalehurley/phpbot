<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\DryRun\DryRunContext;
use Dalehurley\Phpbot\Storage\KeyStore;
use Dalehurley\Phpbot\Tools\GetKeysTool;
use Dalehurley\Phpbot\Tools\StoreKeysTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetKeysTool::class)]
#[CoversClass(StoreKeysTool::class)]
class KeyToolsTest extends TestCase
{
    private string $tmpDir;
    private string $keyStorePath;

    protected function setUp(): void
    {
        DryRunContext::deactivate();
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-keys-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->keyStorePath = $this->tmpDir . '/keys.json';
    }

    protected function tearDown(): void
    {
        DryRunContext::deactivate();
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

    // GetKeysTool
    public function testGetKeysWithoutConfigReturnsError(): void
    {
        $tool = new GetKeysTool([]);
        $result = $tool->execute(['keys' => ['openai_api_key']]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('KeyStore not configured', $result->getContent());
    }

    public function testGetKeysInvalidKeysType(): void
    {
        $tool = new GetKeysTool(['keys_storage_path' => $this->keyStorePath]);
        $result = $tool->execute(['keys' => 'not-an-array']);
        $this->assertTrue($result->isError());
    }

    public function testGetKeysReturnsFoundAndMissing(): void
    {
        $store = new KeyStore($this->keyStorePath);
        $store->set('found_key', 'value123');

        $tool = new GetKeysTool(['keys_storage_path' => $this->keyStorePath]);
        $result = $tool->execute(['keys' => ['found_key', 'missing_key']]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame(['found_key' => 'value123'], $data['found']);
        $this->assertSame(['missing_key'], $data['missing']);
        $this->assertFalse($data['all_found']);
    }

    public function testGetKeysAllFound(): void
    {
        $store = new KeyStore($this->keyStorePath);
        $store->set('k1', 'v1');
        $store->set('k2', 'v2');

        $tool = new GetKeysTool(['keys_storage_path' => $this->keyStorePath]);
        $result = $tool->execute(['keys' => ['k1', 'k2']]);
        $data = json_decode($result->getContent(), true);
        $this->assertTrue($data['all_found']);
    }

    // StoreKeysTool
    public function testStoreKeysWithoutConfigReturnsError(): void
    {
        $tool = new StoreKeysTool([]);
        $result = $tool->execute(['keys' => ['k' => 'v']]);
        $this->assertTrue($result->isError());
    }

    public function testStoreKeysInvalidKeysType(): void
    {
        $tool = new StoreKeysTool(['keys_storage_path' => $this->keyStorePath]);
        $result = $tool->execute(['keys' => 'not-an-object']);
        $this->assertTrue($result->isError());
    }

    public function testStoreKeysDryRun(): void
    {
        DryRunContext::activate();
        $tool = new StoreKeysTool(['keys_storage_path' => $this->keyStorePath]);
        $result = $tool->execute(['keys' => ['test_key' => 'secret']]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertTrue($data['dry_run'] ?? false);
        $store = new KeyStore($this->keyStorePath);
        $this->assertNull($store->get('test_key'));
        DryRunContext::deactivate();
    }

    public function testStoreAndGetRoundTrip(): void
    {
        $storeTool = new StoreKeysTool(['keys_storage_path' => $this->keyStorePath]);
        $getTool = new GetKeysTool(['keys_storage_path' => $this->keyStorePath]);

        $storeResult = $storeTool->execute(['keys' => ['twilio_sid' => 'AC123', 'twilio_token' => 'abc456']]);
        $this->assertFalse($storeResult->isError());

        $getResult = $getTool->execute(['keys' => ['twilio_sid', 'twilio_token']]);
        $this->assertFalse($getResult->isError());
        $data = json_decode($getResult->getContent(), true);
        $this->assertSame('AC123', $data['found']['twilio_sid']);
        $this->assertSame('abc456', $data['found']['twilio_token']);
        $this->assertTrue($data['all_found']);
    }

    public function testGetKeysToDefinition(): void
    {
        $tool = new GetKeysTool(['keys_storage_path' => $this->keyStorePath]);
        $def = $tool->toDefinition();
        $this->assertSame('get_keys', $def['name']);
    }

    public function testStoreKeysToDefinition(): void
    {
        $tool = new StoreKeysTool(['keys_storage_path' => $this->keyStorePath]);
        $def = $tool->toDefinition();
        $this->assertSame('store_keys', $def['name']);
    }
}
