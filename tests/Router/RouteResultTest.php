<?php

declare(strict_types=1);

namespace Tests\Router;

use Dalehurley\Phpbot\Router\RouteResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteResult::class)]
class RouteResultTest extends TestCase
{
    public function testInstantFactoryCreatesTier0Result(): void
    {
        $result = RouteResult::instant('Hello, world!');
        $this->assertSame(RouteResult::TIER_INSTANT, $result->tier);
        $this->assertSame('Hello, world!', $result->directAnswer);
        $this->assertNull($result->bashCommand);
        $this->assertSame([], $result->tools);
        $this->assertSame([], $result->skills);
        $this->assertSame(1.0, $result->confidence);
    }

    public function testBashFactoryCreatesTier1Result(): void
    {
        $result = RouteResult::bash('echo "test"');
        $this->assertSame(RouteResult::TIER_BASH, $result->tier);
        $this->assertNull($result->directAnswer);
        $this->assertSame('echo "test"', $result->bashCommand);
        $this->assertSame(1.0, $result->confidence);
    }

    public function testCachedFactoryCreatesTier2Result(): void
    {
        $result = RouteResult::cached(
            tools: ['bash', 'read_file'],
            skills: ['skill-a'],
            agentType: 'react',
            promptTier: 'standard',
            confidence: 0.8
        );
        $this->assertSame(RouteResult::TIER_CACHED, $result->tier);
        $this->assertSame(['bash', 'read_file'], $result->tools);
        $this->assertSame(['skill-a'], $result->skills);
        $this->assertSame('react', $result->agentType);
        $this->assertSame('standard', $result->promptTier);
        $this->assertSame(0.8, $result->confidence);
    }

    public function testClassifiedFactoryCreatesTier3Result(): void
    {
        $result = RouteResult::classified(
            tools: ['write_file'],
            skills: ['skill-b'],
            agentType: 'plan_execute',
            promptTier: 'full',
            confidence: 0.6
        );
        $this->assertSame(RouteResult::TIER_CLASSIFIED, $result->tier);
        $this->assertSame(['write_file'], $result->tools);
        $this->assertSame(['skill-b'], $result->skills);
        $this->assertSame('plan_execute', $result->agentType);
        $this->assertSame('full', $result->promptTier);
        $this->assertSame(0.6, $result->confidence);
    }

    public function testIsEarlyExitForInstant(): void
    {
        $result = RouteResult::instant('answer');
        $this->assertTrue($result->isEarlyExit());
    }

    public function testIsEarlyExitForBash(): void
    {
        $result = RouteResult::bash('ls');
        $this->assertTrue($result->isEarlyExit());
    }

    public function testIsEarlyExitForCachedReturnsFalse(): void
    {
        $result = RouteResult::cached(['bash']);
        $this->assertFalse($result->isEarlyExit());
    }

    public function testIsEarlyExitForClassifiedReturnsFalse(): void
    {
        $result = RouteResult::classified(['bash']);
        $this->assertFalse($result->isEarlyExit());
    }

    public function testResolveInstantReturnsDirectAnswer(): void
    {
        $result = RouteResult::instant('Direct answer here');
        $this->assertSame('Direct answer here', $result->resolve());
    }

    public function testResolveInstantWithNullReturnsEmptyString(): void
    {
        $result = new RouteResult(tier: RouteResult::TIER_INSTANT, directAnswer: null);
        $this->assertSame('', $result->resolve());
    }

    public function testResolveBashExecutesCommandAndReturnsStdout(): void
    {
        $result = RouteResult::bash('echo "hello world"');
        $output = $result->resolve();
        $this->assertSame('hello world', trim($output));
    }

    public function testResolveBashWithEmptyCommandReturnsEmpty(): void
    {
        $result = new RouteResult(tier: RouteResult::TIER_BASH, bashCommand: '');
        $this->assertSame('', $result->resolve());
    }

    public function testResolveThrowsOnNonEarlyExitRoute(): void
    {
        $result = RouteResult::cached(['bash']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('resolve() can only be called on early-exit routes');
        $result->resolve();
    }

    public function testToAnalysisMapsCorrectly(): void
    {
        $result = RouteResult::cached(
            tools: ['bash', 'read_file', 'tool_builder'],
            skills: ['skill-x'],
            agentType: 'plan_execute',
            promptTier: 'full'
        );
        $analysis = $result->toAnalysis();

        $this->assertSame('general', $analysis['task_type']);
        $this->assertSame('complex', $analysis['complexity']);
        $this->assertTrue($analysis['requires_bash']);
        $this->assertTrue($analysis['requires_file_ops']);
        $this->assertTrue($analysis['requires_tool_creation']);
        $this->assertTrue($analysis['requires_planning']);
        $this->assertFalse($analysis['requires_reflection']);
        $this->assertSame(['bash', 'read_file', 'tool_builder'], $analysis['potential_tools_needed']);
        $this->assertTrue($analysis['skill_matched']);
        $this->assertSame(10, $analysis['estimated_steps']);
    }

    public function testToAnalysisMinimalPromptTier(): void
    {
        $result = RouteResult::cached(
            tools: [],
            skills: [],
            agentType: 'react',
            promptTier: 'minimal'
        );
        $analysis = $result->toAnalysis();
        $this->assertSame('simple', $analysis['complexity']);
        $this->assertSame(2, $analysis['estimated_steps']);
        $this->assertFalse($analysis['requires_bash']);
        $this->assertSame('direct', $analysis['suggested_approach']);
    }

    public function testToAnalysisReflectionAgentType(): void
    {
        $result = RouteResult::classified(
            tools: ['bash'],
            agentType: 'reflection',
            promptTier: 'standard'
        );
        $analysis = $result->toAnalysis();
        $this->assertTrue($analysis['requires_reflection']);
    }
}