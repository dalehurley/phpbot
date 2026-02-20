<?php

declare(strict_types=1);

namespace Tests;

use ClaudeAgents\Skills\SkillManager;
use Dalehurley\Phpbot\SkillAutoCreator;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillAutoCreator::class)]
class SkillAutoCreatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/phpbot_skills_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                is_dir($f) ? $this->rmrf($f) : unlink($f);
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        foreach (scandir($path) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $full = $path . '/' . $e;
            is_dir($full) ? $this->rmrf($full) : unlink($full);
        }
        rmdir($path);
    }

    public function testAutoCreateGuardNullResult(): void
    {
        $creator = new SkillAutoCreator(fn() => null, ['skills_path' => $this->tempDir]);
        $progress = fn($a, $b) => null;
        $creator->autoCreate('task', [], null, [], $progress);
        $this->assertDirectoryDoesNotExist($this->tempDir . '/new-skill');
    }

    public function testAutoCreateGuardNotSuccess(): void
    {
        $result = Mockery::mock();
        $result->shouldReceive('isSuccess')->andReturn(false);
        $result->shouldNotReceive('getToolCalls');
        $creator = new SkillAutoCreator(fn() => null, ['skills_path' => $this->tempDir]);
        $creator->autoCreate('task', [], $result, [], fn() => null);
        $this->assertDirectoryDoesNotExist($this->tempDir . '/any-skill');
    }

    public function testAutoCreateGuardNullSkillManager(): void
    {
        $result = Mockery::mock();
        $result->shouldReceive('isSuccess')->andReturn(true);
        $creator = new SkillAutoCreator(fn() => null, ['skills_path' => $this->tempDir], null);
        $creator->autoCreate('task', [], $result, [], fn() => null);
        $this->assertEmpty(glob($this->tempDir . '/*'));
    }

    public function testAutoCreateGuardHighConfidenceMatch(): void
    {
        $result = Mockery::mock();
        $result->shouldReceive('isSuccess')->andReturn(true);
        $result->shouldReceive('getToolCalls')->andReturn([['tool' => 'bash']]);
        $skillManager = new SkillManager($this->tempDir);
        $skillManager->discover();
        $creator = new SkillAutoCreator(fn() => null, ['skills_path' => $this->tempDir], $skillManager);
        $analysis = ['skill_matched' => true, 'skill_confidence' => 'high'];
        $creator->autoCreate('task', $analysis, $result, [], fn() => null);
        $this->assertEmpty(glob($this->tempDir . '/*'));
    }

    public function testAutoCreateGuardInvalidSkillsPath(): void
    {
        $result = Mockery::mock();
        $result->shouldReceive('isSuccess')->andReturn(true);
        $result->shouldReceive('getToolCalls')->andReturn([['tool' => 'bash']]);
        $skillManager = new SkillManager($this->tempDir);
        $creator = new SkillAutoCreator(fn() => null, ['skills_path' => '/nonexistent/path'], $skillManager);
        $creator->autoCreate('task', [], $result, [], fn() => null);
        $this->assertDirectoryDoesNotExist($this->tempDir . '/new-skill');
    }

    public function testAutoCreateGuardNoSubstantiveToolUse(): void
    {
        $result = Mockery::mock();
        $result->shouldReceive('isSuccess')->andReturn(true);
        $result->shouldReceive('getToolCalls')->andReturn([
            ['tool' => 'ask_user', 'input' => []],
            ['tool' => 'get_keys', 'input' => []],
        ]);
        $skillManager = new SkillManager($this->tempDir);
        $skillManager->discover();
        $creator = new SkillAutoCreator(fn() => null, ['skills_path' => $this->tempDir], $skillManager);
        $creator->autoCreate('task', [], $result, [], fn() => null);
        $this->assertEmpty(glob($this->tempDir . '/*'));
    }
}
