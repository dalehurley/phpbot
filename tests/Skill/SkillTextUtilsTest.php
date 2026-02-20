<?php

declare(strict_types=1);

namespace Tests\Skill;

use Dalehurley\Phpbot\Skill\SkillTextUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillTextUtils::class)]
class SkillTextUtilsTest extends TestCase
{
    public function testSanitizeInputReplacesUserPaths(): void
    {
        $input = 'Read the file at /Users/john/Desktop/report.pdf';
        $result = SkillTextUtils::sanitizeInput($input);
        $this->assertStringContainsString('a PDF file', $result);
        $this->assertStringNotContainsString('/Users/john', $result);
    }

    public function testSanitizeInputReplacesHomePaths(): void
    {
        $input = 'Open ~/Documents/file.txt';
        $result = SkillTextUtils::sanitizeInput($input);
        $this->assertStringContainsString('a text file', $result);
    }

    public function testSanitizeInputUnknownExtensionBecomesFile(): void
    {
        $input = 'Check /tmp/foo.xyz';
        $result = SkillTextUtils::sanitizeInput($input);
        $this->assertStringContainsString('a file', $result);
    }

    public function testSanitizeInputNormalizesWhitespace(): void
    {
        $input = "Multiple   spaces    and\t\ttabs";
        $result = SkillTextUtils::sanitizeInput($input);
        $this->assertStringNotContainsString('   ', $result);
    }

    public function testSlugify(): void
    {
        $this->assertSame('send-sms', SkillTextUtils::slugify('Send SMS'));
        $this->assertSame('hello-world', SkillTextUtils::slugify('Hello World!'));
    }

    public function testSlugifyEmptyReturnsEmpty(): void
    {
        $this->assertSame('', SkillTextUtils::slugify(''));
    }

    public function testSlugifyTruncatesLongInput(): void
    {
        $long = str_repeat('a', 60);
        $result = SkillTextUtils::slugify($long);
        $this->assertLessThanOrEqual(48, strlen($result));
    }

    public function testSlugifyRemovesNonAlphanumeric(): void
    {
        $this->assertSame('abc-123', SkillTextUtils::slugify('abc @#$ 123'));
    }

    public function testGenerateShortName(): void
    {
        $result = SkillTextUtils::generateShortName('Please send an SMS to the team');
        $this->assertNotEmpty($result);
        $this->assertStringNotContainsString('please', $result);
        $this->assertStringNotContainsString('the', $result);
    }

    public function testGenerateShortNameRemovesQuotedContent(): void
    {
        $result = SkillTextUtils::generateShortName('Send SMS saying "hello world"');
        $this->assertStringNotContainsString('hello', $result);
    }

    public function testGenerateShortNameFallbackForEmpty(): void
    {
        $result = SkillTextUtils::generateShortName('"    "');
        $this->assertSame('general-task', $result);
    }

    public function testParseToolInputArray(): void
    {
        $call = ['input' => ['command' => 'ls', 'path' => '/tmp']];
        $result = SkillTextUtils::parseToolInput($call);
        $this->assertSame(['command' => 'ls', 'path' => '/tmp'], $result);
    }

    public function testParseToolInputJsonString(): void
    {
        $call = ['input' => '{"command":"echo hello"}'];
        $result = SkillTextUtils::parseToolInput($call);
        $this->assertSame(['command' => 'echo hello'], $result);
    }

    public function testParseToolInputInvalidJsonReturnsEmpty(): void
    {
        $call = ['input' => 'not valid json'];
        $result = SkillTextUtils::parseToolInput($call);
        $this->assertSame([], $result);
    }

    public function testParseToolInputMissingReturnsEmpty(): void
    {
        $call = [];
        $result = SkillTextUtils::parseToolInput($call);
        $this->assertSame([], $result);
    }

    public function testExtractToolRecipeBashCommands(): void
    {
        $toolCalls = [
            ['tool' => 'bash', 'input' => ['command' => 'echo hello'], 'is_error' => false],
            ['tool' => 'bash', 'input' => ['command' => 'ls -la'], 'is_error' => false],
        ];
        $recipe = SkillTextUtils::extractToolRecipe($toolCalls);
        $this->assertStringContainsString('echo hello', $recipe);
        $this->assertStringContainsString('ls -la', $recipe);
    }

    public function testExtractToolRecipeSkipsErrors(): void
    {
        $toolCalls = [
            ['tool' => 'bash', 'input' => ['command' => 'fail'], 'is_error' => true],
            ['tool' => 'bash', 'input' => ['command' => 'echo ok'], 'is_error' => false],
        ];
        $recipe = SkillTextUtils::extractToolRecipe($toolCalls);
        $this->assertStringNotContainsString('fail', $recipe);
        $this->assertStringContainsString('echo ok', $recipe);
    }

    public function testExtractToolRecipeWriteFile(): void
    {
        $toolCalls = [
            ['tool' => 'write_file', 'input' => ['path' => '/tmp/script.sh', 'content' => '#!/bin/bash'], 'is_error' => false],
        ];
        $recipe = SkillTextUtils::extractToolRecipe($toolCalls);
        $this->assertStringContainsString('# write_file:', $recipe);
        $this->assertStringContainsString('/tmp/script.sh', $recipe);
    }

    public function testExtractJsonFromResponseDirectJson(): void
    {
        $response = '{"name":"test","value":42}';
        $result = SkillTextUtils::extractJsonFromResponse($response);
        $this->assertIsArray($result);
        $this->assertSame('test', $result['name']);
        $this->assertSame(42, $result['value']);
    }

    public function testExtractJsonFromResponseMarkdownFenced(): void
    {
        $response = "Some text\n```json\n{\"foo\":\"bar\"}\n```\nMore text";
        $result = SkillTextUtils::extractJsonFromResponse($response);
        $this->assertIsArray($result);
        $this->assertSame('bar', $result['foo']);
    }

    public function testExtractJsonFromResponseBraceBlock(): void
    {
        $response = "Prefix {\"a\":1,\"b\":2} suffix";
        $result = SkillTextUtils::extractJsonFromResponse($response);
        $this->assertIsArray($result);
        $this->assertSame(1, $result['a']);
        $this->assertSame(2, $result['b']);
    }

    public function testExtractJsonFromResponseNullReturnsNull(): void
    {
        $this->assertNull(SkillTextUtils::extractJsonFromResponse(null));
        $this->assertNull(SkillTextUtils::extractJsonFromResponse(''));
    }

    public function testExtractJsonFromResponseInvalidReturnsNull(): void
    {
        $this->assertNull(SkillTextUtils::extractJsonFromResponse('not json at all'));
    }
}