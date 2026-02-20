<?php

declare(strict_types=1);

namespace Tests\Security;

use Dalehurley\Phpbot\Security\KeyRotationManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeyRotationManager::class)]
class KeyRotationManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phpbot_keyrot_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testDetectProviders(): void
    {
        $file = $this->tempDir . '/.env';
        file_put_contents($file, 'ANTHROPIC_API_KEY=sk-ant-abc123def456ghi789jkl012mno345pq');
        $manager = new KeyRotationManager();
        $detected = $manager->detectProviders([$file]);
        $this->assertIsArray($detected);
        $this->assertArrayHasKey('anthropic', $detected);
    }

    public function testReplaceKeys(): void
    {
        $file = $this->tempDir . '/config.php';
        $oldKey = 'sk-ant-old123old123old123old123old12';
        file_put_contents($file, "api_key = '{$oldKey}'");
        $manager = new KeyRotationManager();
        $newKey = 'sk-ant-new456new456new456new456new45';
        $result = $manager->replaceKeys([$oldKey => $newKey], [$file]);
        $this->assertArrayHasKey('replaced', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertStringContainsString($newKey, file_get_contents($file));
    }

    public function testGetSupportedProviders(): void
    {
        $manager = new KeyRotationManager();
        $providers = $manager->getSupportedProviders();
        $this->assertIsArray($providers);
        $this->assertContains('Anthropic', $providers);
    }

    public function testGetProviderConfig(): void
    {
        $manager = new KeyRotationManager();
        $config = $manager->getProviderConfig('anthropic');
        $this->assertIsArray($config);
        $this->assertArrayHasKey('pattern', $config);
        $this->assertArrayHasKey('env_var', $config);
        $this->assertNull($manager->getProviderConfig('nonexistent'));
    }
}
