<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Tools\SkillScriptTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillScriptTool::class)]
class SkillScriptToolTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpbot-skill-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
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

    public function testGetName(): void
    {
        $scriptPath = $this->tmpDir . '/script.sh';
        file_put_contents($scriptPath, '#!/bin/bash\necho ok');
        $tool = new SkillScriptTool('my_skill', 'Runs my skill', $scriptPath, '/bin/bash');
        $this->assertSame('my_skill', $tool->getName());
    }

    public function testGetDescription(): void
    {
        $scriptPath = $this->tmpDir . '/script.sh';
        file_put_contents($scriptPath, 'echo ok');
        $tool = new SkillScriptTool('s', 'Runs something', $scriptPath, '/bin/bash');
        $this->assertSame('Runs something', $tool->getDescription());
    }

    public function testGetInputSchema(): void
    {
        $scriptPath = $this->tmpDir . '/script.sh';
        file_put_contents($scriptPath, 'echo ok');
        $tool = new SkillScriptTool('s', 'd', $scriptPath, '/bin/bash');
        $schema = $tool->getInputSchema();
        $this->assertArrayHasKey('args', $schema['properties']);
        $this->assertArrayHasKey('working_directory', $schema['properties']);
    }

    public function testExecuteNonExistentScriptReturnsError(): void
    {
        $tool = new SkillScriptTool('s', 'd', $this->tmpDir . '/missing.sh', '/bin/bash');
        $result = $tool->execute([]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Script not found', $result->getContent());
    }

    public function testExecuteValidScript(): void
    {
        $scriptPath = $this->tmpDir . '/test.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho hello");
        chmod($scriptPath, 0755);

        $tool = new SkillScriptTool('test_script', 'Test script', $scriptPath, '/bin/bash');
        $result = $tool->execute([]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame('hello', trim($data['stdout'] ?? ''));
        $this->assertSame(0, $data['exit_code']);
    }

    public function testExecuteWithArgs(): void
    {
        $scriptPath = $this->tmpDir . '/arg.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho \"\$1\"");
        chmod($scriptPath, 0755);

        $tool = new SkillScriptTool('arg_script', 'd', $scriptPath, '/bin/bash');
        $result = $tool->execute(['args' => ['world']]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame('world', trim($data['stdout'] ?? ''));
    }

    public function testToDefinition(): void
    {
        $scriptPath = $this->tmpDir . '/s.sh';
        file_put_contents($scriptPath, 'echo ok');
        $tool = new SkillScriptTool('s', 'd', $scriptPath, '/bin/bash');
        $def = $tool->toDefinition();
        $this->assertSame('s', $def['name']);
    }
}
