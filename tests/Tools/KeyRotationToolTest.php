<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Security\KeyRotationManager;
use Dalehurley\Phpbot\Storage\RollbackManager;
use Dalehurley\Phpbot\Tools\KeyRotationTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mockery;

#[CoversClass(KeyRotationTool::class)]
class KeyRotationToolTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-keyrot-' . uniqid();
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

    public function testGetName(): void
    {
        $krm = new KeyRotationManager();
        $tool = new KeyRotationTool($krm);
        $this->assertSame('rotate_keys', $tool->getName());
    }

    public function testListProvidersAction(): void
    {
        $krm = new KeyRotationManager();
        $tool = new KeyRotationTool($krm);
        $result = $tool->execute(['action' => 'list_providers']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertArrayHasKey('supported_providers', $data);
    }

    public function testDetectActionEmptyFilesReturnsError(): void
    {
        $krm = new KeyRotationManager();
        $tool = new KeyRotationTool($krm);
        $result = $tool->execute(['action' => 'detect', 'files' => []]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('file path', $result->getContent());
    }

    public function testDetectAction(): void
    {
        $file = $this->tmpDir . '/config.php';
        file_put_contents($file, 'OPENAI_API_KEY=sk-abc123');

        $krm = new KeyRotationManager();
        $tool = new KeyRotationTool($krm);
        $result = $tool->execute(['action' => 'detect', 'files' => [$file]]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertArrayHasKey('detected', $data);
    }

    public function testRotateActionEmptyFilesReturnsError(): void
    {
        $krm = new KeyRotationManager();
        $tool = new KeyRotationTool($krm);
        $result = $tool->execute([
            'action' => 'rotate',
            'files' => [],
            'replacements' => ['old' => 'new'],
        ]);
        $this->assertTrue($result->isError());
    }

    public function testRotateActionEmptyReplacementsReturnsError(): void
    {
        $file = $this->tmpDir . '/f.txt';
        file_put_contents($file, 'content');
        $krm = new KeyRotationManager();
        $tool = new KeyRotationTool($krm);
        $result = $tool->execute([
            'action' => 'rotate',
            'files' => [$file],
            'replacements' => [],
        ]);
        $this->assertTrue($result->isError());
    }

    public function testRotateActionWithRollbackManager(): void
    {
        $file = $this->tmpDir . '/target.txt';
        file_put_contents($file, 'OLD_KEY_123');

        $rollbackManager = Mockery::mock(RollbackManager::class);
        $rollbackManager->shouldReceive('createSnapshot')
            ->once()
            ->with('sess-1', [$file]);

        $krm = new KeyRotationManager();
        $tool = new KeyRotationTool($krm, $rollbackManager, 'sess-1');
        $result = $tool->execute([
            'action' => 'rotate',
            'files' => [$file],
            'replacements' => ['OLD_KEY_123' => 'NEW_KEY_456'],
        ]);
        $this->assertFalse($result->isError());
    }

    public function testUnknownActionReturnsError(): void
    {
        $krm = new KeyRotationManager();
        $tool = new KeyRotationTool($krm);
        $result = $tool->execute(['action' => 'invalid']);
        $this->assertTrue($result->isError());
    }

    public function testToDefinition(): void
    {
        $krm = new KeyRotationManager();
        $tool = new KeyRotationTool($krm);
        $def = $tool->toDefinition();
        $this->assertSame('rotate_keys', $def['name']);
    }
}
