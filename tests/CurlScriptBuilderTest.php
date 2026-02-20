<?php

declare(strict_types=1);

namespace Tests;

use Dalehurley\Phpbot\CurlScriptBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CurlScriptBuilder::class)]
class CurlScriptBuilderTest extends TestCase
{
    public function testIsCurlOrApiCommand(): void
    {
        $builder = new CurlScriptBuilder();
        $this->assertTrue($builder->isCurlOrApiCommand('curl https://api.example.com'));
        $this->assertTrue($builder->isCurlOrApiCommand('wget https://example.com'));
        $this->assertFalse($builder->isCurlOrApiCommand('echo hello'));
    }

    public function testBuildParameterizedScript(): void
    {
        $builder = new CurlScriptBuilder();
        $curlCmd = 'curl -X POST https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json -d "Body=Hello"';
        $script = $builder->buildParameterizedScript($curlCmd, []);
        $this->assertIsArray($script);
        $this->assertArrayHasKey('content', $script);
        $this->assertArrayHasKey('description', $script);
        $this->assertArrayHasKey('parameters', $script);
        $this->assertStringContainsString('#!/usr/bin/env bash', $script['content']);
    }

    public function testGenerateApiScripts(): void
    {
        $builder = new CurlScriptBuilder();
        $toolCalls = [
            [
                'tool' => 'bash',
                'input' => ['command' => 'curl -s https://api.openai.com/v1/models'],
                'is_error' => false,
            ],
        ];
        $scripts = $builder->generateApiScripts($toolCalls, []);
        $this->assertIsArray($scripts);
    }
}
