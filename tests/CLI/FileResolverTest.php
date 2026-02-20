<?php

declare(strict_types=1);

namespace Tests\CLI;

use Dalehurley\Phpbot\CLI\FileResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileResolver::class)]
class FileResolverTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phpbot_fileresolver_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                is_file($f) ? unlink($f) : $this->rmrf($f);
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $full = $path . '/' . $e;
            is_dir($full) ? $this->rmrf($full) : unlink($full);
        }
        rmdir($path);
    }

    public function testAttachReturnsArray(): void
    {
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'content');
        $resolver = new FileResolver($this->tempDir);
        $result = $resolver->attach('test.txt');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertTrue($result['success']);
    }

    public function testDetectDragAndDropReturnsArray(): void
    {
        $resolver = new FileResolver($this->tempDir);
        $result = $resolver->detectDragAndDrop('some text without paths');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('input', $result);
        $this->assertArrayHasKey('attached', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertSame('some text without paths', $result['input']);
    }

    public function testParseAndAttach(): void
    {
        $file = $this->tempDir . '/foo.txt';
        file_put_contents($file, 'hello');
        $resolver = new FileResolver($this->tempDir);
        $result = $resolver->parseAndAttach('review @foo.txt please');
        $this->assertArrayHasKey('input', $result);
        $this->assertArrayHasKey('attached', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testDetach(): void
    {
        $file = $this->tempDir . '/detach.txt';
        file_put_contents($file, 'x');
        $resolver = new FileResolver($this->tempDir);
        $resolver->attach('detach.txt');
        $this->assertSame(1, $resolver->count());
        $ok = $resolver->detach(realpath($file) ?: $file);
        $this->assertTrue($ok);
        $this->assertSame(0, $resolver->count());
    }

    public function testBuildContextBlock(): void
    {
        $file = $this->tempDir . '/ctx.txt';
        file_put_contents($file, "line1\nline2");
        $resolver = new FileResolver($this->tempDir);
        $resolver->attach('ctx.txt');
        $block = $resolver->buildContextBlock();
        $this->assertStringContainsString('Attached File Context', $block);
        $this->assertStringContainsString('ctx.txt', $block);
        $this->assertStringContainsString('line1', $block);
    }

    public function testBuildContextBlockEmptyWhenNoFiles(): void
    {
        $resolver = new FileResolver($this->tempDir);
        $this->assertSame('', $resolver->buildContextBlock());
    }

    public function testRealpathResolvesCorrectly(): void
    {
        $file = $this->tempDir . '/real.txt';
        file_put_contents($file, 'x');
        $resolved = realpath($file);
        $this->assertNotFalse($resolved);
        $this->assertFileExists($resolved);
    }
}
