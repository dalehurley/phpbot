<?php

declare(strict_types=1);

namespace Tests;

use Dalehurley\Phpbot\CredentialPatterns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CredentialPatterns::class)]
class CredentialPatternsTest extends TestCase
{
    public function testDetectEmptyStringReturnsEmpty(): void
    {
        $this->assertSame([], CredentialPatterns::detect(''));
    }

    public function testStripEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', CredentialPatterns::strip(''));
    }

    public function testDetectAnthropicApiKey(): void
    {
        $text = 'Use sk-ant-api03-abc123xyz7890123456789012 for auth';
        $found = CredentialPatterns::detect($text);
        $this->assertCount(1, $found);
        $this->assertSame('anthropic_api_key', $found[0]['type']);
        $this->assertSame('sk-ant-api03-abc123xyz7890123456789012', $found[0]['value']);
        $this->assertSame('{{ANTHROPIC_API_KEY}}', $found[0]['placeholder']);
    }

    public function testDetectOpenAIApiKey(): void
    {
        $text = 'OpenAI API key: sk-projabcdefghijklmnopqrstuvwxyz1234567890';
        $found = CredentialPatterns::detect($text);
        $this->assertCount(1, $found);
        $this->assertSame('openai_api_key', $found[0]['type']);
    }

    public function testDetectGithubToken(): void
    {
        $text = 'Use ghp_abcdefghijklmnopqrstuvwxyz1234567890ab for GitHub';
        $found = CredentialPatterns::detect($text);
        $this->assertGreaterThanOrEqual(1, count($found));
        $types = array_column($found, 'type');
        $this->assertContains('github_token', $types);
    }

    public function testDetectTwilioAccountSid(): void
    {
        $text = 'AC' . '1234567890abcdef' . '1234567890abcdef';
        $found = CredentialPatterns::detect($text);
        $this->assertCount(1, $found);
        $this->assertSame('twilio_account_sid', $found[0]['type']);
    }

    public function testDetectAwsAccessKey(): void
    {
        $text = 'AKIAIOSFODNN7EXAMPLE';
        $found = CredentialPatterns::detect($text);
        $this->assertCount(1, $found);
        $this->assertSame('aws_access_key', $found[0]['type']);
    }

    public function testStripReplacesCredentials(): void
    {
        $text = 'Use sk-ant-api03-abc123xyz7890123456789012 for auth';
        $stripped = CredentialPatterns::strip($text);
        $this->assertStringContainsString('{{ANTHROPIC_API_KEY}}', $stripped);
        $this->assertStringNotContainsString('sk-ant-api03', $stripped);
    }

    public function testStripReplacesUserPaths(): void
    {
        $text = 'File at /Users/john/Desktop/secret.txt';
        $stripped = CredentialPatterns::strip($text);
        $this->assertStringContainsString('$HOME', $stripped);
    }

    public function testDetectFromToolCalls(): void
    {
        $recipe = 'Send SMS with Twilio';
        $toolCalls = [
            [
                'input' => ['api_key' => 'sk-ant-api03-xyz1234567890123456789012'],
                'output' => 'Success',
            ],
        ];
        $found = CredentialPatterns::detectFromToolCalls($recipe, $toolCalls);
        $this->assertCount(1, $found);
        $this->assertSame('anthropic_api_key', $found[0]['type']);
    }

    public function testDetectFromToolCallsWithArrayInput(): void
    {
        $recipe = 'Recipe';
        $toolCalls = [
            ['input' => ['command' => 'export OPENAI_API_KEY=sk-proj-abcdefghij1234567890'], 'output' => ''],
        ];
        $found = CredentialPatterns::detectFromToolCalls($recipe, $toolCalls);
        $this->assertCount(1, $found);
    }

    public function testDescribeTypeKnown(): void
    {
        $desc = CredentialPatterns::describeType('anthropic_api_key');
        $this->assertSame('Anthropic API key (starts with sk-ant-)', $desc);
    }

    public function testDescribeTypeUnknown(): void
    {
        $desc = CredentialPatterns::describeType('unknown_cred_type');
        $this->assertSame('Unknown cred type', $desc);
    }

    public function testDescribeTypeFormatsUnderscores(): void
    {
        $desc = CredentialPatterns::describeType('some_weird_type');
        $this->assertSame('Some weird type', $desc);
    }

    public function testDetectPhoneNumber(): void
    {
        $text = 'Call +14155551234 for support';
        $found = CredentialPatterns::detect($text);
        $this->assertNotEmpty($found);
        $phoneTypes = array_filter($found, fn($f) => $f['type'] === 'phone_number');
        $this->assertNotEmpty($phoneTypes);
    }

    public function testDetectDoesNotDoubleCount(): void
    {
        $text = 'Key: sk-ant-api03-abc123xyz7890123456789012 and again sk-ant-api03-abc123xyz7890123456789012';
        $found = CredentialPatterns::detect($text);
        $anthropic = array_filter($found, fn($f) => $f['type'] === 'anthropic_api_key');
        $this->assertCount(1, $anthropic);
    }
}