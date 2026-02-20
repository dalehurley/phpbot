<?php

declare(strict_types=1);

namespace Tests;

use Dalehurley\Phpbot\Platform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Platform::class)]
class PlatformTest extends TestCase
{
    public function testOsDetectionReturnsBoolean(): void
    {
        $this->assertIsBool(Platform::isMacOS());
        $this->assertIsBool(Platform::isLinux());
        $this->assertIsBool(Platform::isWindows());
        $this->assertTrue(Platform::isMacOS() || Platform::isLinux() || Platform::isWindows());
    }

    public function testOsNameReturnsNonEmptyString(): void
    {
        $name = Platform::osName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
        $this->assertContains($name, ['macOS', 'Linux', 'Windows', 'Darwin']);
    }

    public function testTtsCommandReturnsString(): void
    {
        $cmd = Platform::ttsCommand();
        $this->assertIsString($cmd);
        $this->assertContains($cmd, ['say', 'espeak']);
    }

    public function testOpenCommandReturnsString(): void
    {
        $cmd = Platform::openCommand();
        $this->assertIsString($cmd);
        $this->assertContains($cmd, ['open', 'xdg-open']);
    }

    public function testClipboardCommandsReturnStrings(): void
    {
        $this->assertIsString(Platform::clipboardCopyCommand());
        $this->assertIsString(Platform::clipboardPasteCommand());
    }

    public function testAudioPlayCommandReturnsString(): void
    {
        $cmd = Platform::audioPlayCommand();
        $this->assertIsString($cmd);
        $this->assertContains($cmd, ['afplay', 'paplay']);
    }

    public function testScreenshotCommandReturnsString(): void
    {
        $cmd = Platform::screenshotCommand();
        $this->assertIsString($cmd);
        $this->assertContains($cmd, ['screencapture', 'scrot']);
    }

    public function testNotifyCommandReturnsString(): void
    {
        $cmd = Platform::notifyCommand();
        $this->assertIsString($cmd);
        $this->assertNotEmpty($cmd);
    }

    public function testServiceManagerCommandReturnsString(): void
    {
        $cmd = Platform::serviceManagerCommand();
        $this->assertIsString($cmd);
        $this->assertContains($cmd, ['launchctl', 'systemctl']);
    }

    public function testPackageManagerCommandReturnsNonEmptyString(): void
    {
        $cmd = Platform::packageManagerCommand();
        $this->assertIsString($cmd);
        $this->assertNotEmpty($cmd);
        $this->assertStringContainsString('install', $cmd);
    }

    public function testMemoryAndCpuCommandsReturnStrings(): void
    {
        $this->assertIsString(Platform::memoryCommand());
        $this->assertIsString(Platform::cpuInfoCommand());
        $this->assertIsString(Platform::osVersionCommand());
    }

    public function testAvailableAppleServicesReturnsArray(): void
    {
        $services = Platform::availableAppleServices();
        $this->assertIsArray($services);
        if (Platform::isMacOS()) {
            foreach ($services as $service) {
                $this->assertIsString($service);
            }
        } else {
            $this->assertEmpty($services);
        }
    }

    public function testAppleServicesText(): void
    {
        $text = Platform::appleServicesText();
        $this->assertIsString($text);
        if (Platform::isMacOS() && $text !== '') {
            $this->assertStringNotContainsString('  ', $text);
        }
    }

    public function testSuperpowersPromptReturnsNonEmptyString(): void
    {
        $prompt = Platform::superpowersPrompt();
        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('Make sound', $prompt);
        $this->assertStringContainsString('curl', $prompt);
    }

    public function testOsPlaygroundPromptReturnsNonEmptyString(): void
    {
        $prompt = Platform::osPlaygroundPrompt();
        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }

    public function testCredentialSourcesTextReturnsNonEmptyString(): void
    {
        $text = Platform::credentialSourcesText();
        $this->assertIsString($text);
        $this->assertNotEmpty($text);
        $this->assertStringContainsString('env vars', $text);
    }

    public function testCommandExists(): void
    {
        $this->assertTrue(Platform::commandExists('php'));
        $this->assertFalse(Platform::commandExists('nonexistent_command_xyz_12345'));
    }

    public function testHasFilePicker(): void
    {
        $has = Platform::hasFilePicker();
        $this->assertIsBool($has);
        if (Platform::isMacOS()) {
            $this->assertTrue($has);
        }
    }

    public function testFilePickerBackend(): void
    {
        $backend = Platform::filePickerBackend();
        if (Platform::isMacOS()) {
            $this->assertSame('osascript', $backend);
        } else {
            $this->assertTrue($backend === null || in_array($backend, ['zenity', 'kdialog'], true));
        }
    }

    public function testHasLinuxSecretStorage(): void
    {
        $has = Platform::hasLinuxSecretStorage();
        $this->assertIsBool($has);
        if (Platform::isLinux()) {
            $this->assertIsBool($has);
        } else {
            $this->assertFalse($has);
        }
    }
}