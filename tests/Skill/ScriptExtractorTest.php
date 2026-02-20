<?php

declare(strict_types=1);

namespace Tests\Skill;

use Dalehurley\Phpbot\Skill\ScriptExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScriptExtractor::class)]
class ScriptExtractorTest extends TestCase
{
    public function testFromToolCallsWriteFile(): void
    {
        $toolCalls = [
            [
                'tool' => 'write_file',
                'input' => ['path' => '/tmp/script.py', 'content' => 'print("hello")'],
                'is_error' => false,
            ],
        ];

        $scripts = ScriptExtractor::fromToolCalls($toolCalls);
        $this->assertCount(1, $scripts);
        $this->assertSame('/tmp/script.py', $scripts[0]['original_path']);
        $this->assertSame('script.py', $scripts[0]['filename']);
        $this->assertSame('print("hello")', $scripts[0]['content']);
        $this->assertSame('py', $scripts[0]['extension']);
        $this->assertSame('write_file', $scripts[0]['source']);
    }

    public function testFromToolCallsSkipsNonScriptExtensions(): void
    {
        $toolCalls = [
            [
                'tool' => 'write_file',
                'input' => ['path' => '/tmp/readme.txt', 'content' => 'text content'],
                'is_error' => false,
            ],
        ];

        $scripts = ScriptExtractor::fromToolCalls($toolCalls);
        $this->assertCount(0, $scripts);
    }

    public function testFromToolCallsSkipsErrors(): void
    {
        $toolCalls = [
            [
                'tool' => 'write_file',
                'input' => ['path' => '/tmp/script.sh', 'content' => '#!/bin/bash'],
                'is_error' => true,
            ],
        ];

        $scripts = ScriptExtractor::fromToolCalls($toolCalls);
        $this->assertCount(0, $scripts);
    }

    public function testFromToolCallsBashHeredocCat(): void
    {
        $toolCalls = [
            [
                'tool' => 'bash',
                'input' => [
                    'command' => "cat > script.sh <<'EOF'\n#!/bin/bash\necho hello\nEOF",
                ],
                'is_error' => false,
            ],
        ];

        $scripts = ScriptExtractor::fromToolCalls($toolCalls);
        $this->assertCount(1, $scripts);
        $this->assertSame('script.sh', $scripts[0]['filename']);
        $this->assertStringContainsString('#!/bin/bash', $scripts[0]['content']);
        $this->assertSame('bash_heredoc', $scripts[0]['source']);
    }

    public function testFromToolCallsBashEcho(): void
    {
        $content = '#!/usr/bin/env python3' . "\n" . str_repeat('print("x")' . "\n", 5);
        $toolCalls = [
            [
                'tool' => 'bash',
                'input' => [
                    'command' => "echo '{$content}' > run.py",
                ],
                'is_error' => false,
            ],
        ];

        $scripts = ScriptExtractor::fromToolCalls($toolCalls);
        $this->assertCount(1, $scripts);
        $this->assertSame('run.py', $scripts[0]['filename']);
    }

    public function testSanitizeStripsCredentials(): void
    {
        $scripts = [
            [
                'filename' => 'script.sh',
                'content' => 'KEY=sk-ant-api03-abc123xyz7890123456789012',
                'extension' => 'sh',
                'original_path' => 'script.sh',
            ],
        ];

        $sanitized = ScriptExtractor::sanitize($scripts);
        $this->assertCount(1, $sanitized);
        $this->assertStringContainsString('{{ANTHROPIC_API_KEY}}', $sanitized[0]['content']);
        $this->assertStringNotContainsString('sk-ant-', $sanitized[0]['content']);
    }

    public function testBundleCreatesDirectoryAndWrites(): void
    {
        $skillDir = sys_get_temp_dir() . '/phpbot_skill_test_' . uniqid();
        $scripts = [
            [
                'original_path' => 'test.py',
                'filename' => 'test.py',
                'content' => 'print("hello")',
                'extension' => 'py',
                'source' => 'write_file',
                'description' => 'Test script',
                'parameters' => [],
            ],
        ];

        $bundled = ScriptExtractor::bundle($skillDir, $scripts);

        $this->assertCount(1, $bundled);
        $this->assertSame('scripts/test.py', $bundled[0]['path']);
        $this->assertFileExists($skillDir . '/scripts/test.py');
        $this->assertSame('print("hello")', file_get_contents($skillDir . '/scripts/test.py'));

        if (is_dir($skillDir)) {
            array_map('unlink', glob($skillDir . '/scripts/*'));
            rmdir($skillDir . '/scripts');
            rmdir($skillDir);
        }
    }

    public function testBundleWithEmptyScriptsReturnsEmpty(): void
    {
        $skillDir = sys_get_temp_dir() . '/phpbot_empty_' . uniqid();
        $bundled = ScriptExtractor::bundle($skillDir, []);
        $this->assertSame([], $bundled);
    }

    public function testDeduplicateByPathLastWins(): void
    {
        $toolCalls = [
            ['tool' => 'write_file', 'input' => ['path' => '/tmp/script.sh', 'content' => 'v1'], 'is_error' => false],
            ['tool' => 'write_file', 'input' => ['path' => '/tmp/script.sh', 'content' => 'v2'], 'is_error' => false],
        ];

        $scripts = ScriptExtractor::fromToolCalls($toolCalls);
        $this->assertCount(1, $scripts);
        $this->assertSame('v2', $scripts[0]['content']);
    }

    public function testScriptExtensionsSupported(): void
    {
        $extensions = ['py', 'sh', 'bash', 'js', 'ts', 'php', 'rb', 'pl'];
        foreach ($extensions as $ext) {
            $toolCalls = [
                [
                    'tool' => 'write_file',
                    'input' => ['path' => "/tmp/script.{$ext}", 'content' => 'content'],
                    'is_error' => false,
                ],
            ];
            $scripts = ScriptExtractor::fromToolCalls($toolCalls);
            $this->assertCount(1, $scripts, "Extension {$ext} should be supported");
            $this->assertSame($ext, $scripts[0]['extension']);
        }
    }

    public function testSanitizePreservesMetadata(): void
    {
        $scripts = [
            [
                'filename' => 'x.sh',
                'content' => 'echo ok',
                'extension' => 'sh',
                'original_path' => 'x.sh',
                'description' => 'Custom desc',
                'parameters' => [['name' => 'arg1']],
                'source' => 'custom',
            ],
        ];

        $sanitized = ScriptExtractor::sanitize($scripts);
        $this->assertSame('Custom desc', $sanitized[0]['description']);
        $this->assertSame([['name' => 'arg1']], $sanitized[0]['parameters']);
        $this->assertSame('custom', $sanitized[0]['source']);
    }
}