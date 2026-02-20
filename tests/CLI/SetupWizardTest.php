<?php

declare(strict_types=1);

namespace Tests\CLI;

use Dalehurley\Phpbot\CLI\SetupWizard;
use Dalehurley\Phpbot\Storage\KeyStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetupWizard::class)]
class SetupWizardTest extends TestCase
{
    private string $tempDir;
    private string $keysPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phpbot_setup_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->keysPath = $this->tempDir . '/keys.json';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (scandir($this->tempDir) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($this->tempDir . '/' . $f);
                }
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testConstruct(): void
    {
        $output = fn(string $m) => null;
        $prompt = fn(string $p) => '';
        $keyStore = new KeyStore($this->keysPath);
        $wizard = new SetupWizard($output, $prompt, $this->tempDir, $keyStore);
        $this->assertInstanceOf(SetupWizard::class, $wizard);
    }

    public function testRunReturnsFalseWhenKeyCancelled(): void
    {
        $callCount = 0;
        $prompt = function (string $p) use (&$callCount) {
            $callCount++;
            if (str_contains($p, 'Anthropic')) {
                return false;
            }
            return '';
        };
        $keyStore = new KeyStore($this->keysPath);
        $wizard = new SetupWizard(fn($m) => null, $prompt, $this->tempDir, $keyStore);

        $result = $wizard->run();
        $this->assertFalse($result);
    }

    public function testRunReturnsTrueWhenKeyProvidedAndSaveConfirmed(): void
    {
        $prompts = [
            'test-key-12345',
            'y',
            'y',
        ];
        $idx = 0;
        $prompt = function (string $p) use (&$prompts, &$idx) {
            if ($idx >= count($prompts)) {
                return '';
            }
            return $prompts[$idx++];
        };

        $keyStore = new KeyStore($this->keysPath);
        $wizard = new SetupWizard(fn($m) => null, $prompt, $this->tempDir, $keyStore);

        $result = $wizard->run();
        $this->assertTrue($result);
    }
}
