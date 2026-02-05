<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tests;

use PHPUnit\Framework\TestCase;
use Dalehurley\Phpbot\BotResult;

class BotResultTest extends TestCase
{
    public function testLearnedAbilitiesDefaultsToEmpty(): void
    {
        $result = new BotResult(
            success: true,
            answer: 'Done',
            error: null,
            iterations: 1,
            toolCalls: [],
            tokenUsage: [],
            analysis: []
        );

        $this->assertSame([], $result->getLearnedAbilities());
    }

    public function testLearnedAbilitiesCanBeSet(): void
    {
        $abilities = [
            ['id' => 'ability_1', 'title' => 'Use retries'],
            ['id' => 'ability_2', 'title' => 'Check permissions first'],
        ];

        $result = new BotResult(
            success: true,
            answer: 'Done',
            error: null,
            iterations: 5,
            toolCalls: [],
            tokenUsage: [],
            analysis: [],
            learnedAbilities: $abilities
        );

        $this->assertCount(2, $result->getLearnedAbilities());
        $this->assertSame('Use retries', $result->getLearnedAbilities()[0]['title']);
        $this->assertSame('Check permissions first', $result->getLearnedAbilities()[1]['title']);
    }

    public function testToArrayIncludesLearnedAbilities(): void
    {
        $abilities = [
            ['id' => 'ability_1', 'title' => 'Test ability'],
        ];

        $result = new BotResult(
            success: true,
            answer: 'Done',
            error: null,
            iterations: 3,
            toolCalls: [],
            tokenUsage: [],
            analysis: [],
            learnedAbilities: $abilities
        );

        $array = $result->toArray();

        $this->assertArrayHasKey('learned_abilities', $array);
        $this->assertCount(1, $array['learned_abilities']);
        $this->assertSame('Test ability', $array['learned_abilities'][0]['title']);
    }

    public function testToJsonIncludesLearnedAbilities(): void
    {
        $result = new BotResult(
            success: true,
            answer: 'Done',
            error: null,
            iterations: 1,
            toolCalls: [],
            tokenUsage: [],
            analysis: [],
            learnedAbilities: [['id' => 'test', 'title' => 'JSON test']]
        );

        $json = $result->toJson();
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('learned_abilities', $decoded);
        $this->assertSame('JSON test', $decoded['learned_abilities'][0]['title']);
    }

    public function testToArrayWithEmptyLearnedAbilities(): void
    {
        $result = new BotResult(
            success: true,
            answer: 'Done',
            error: null,
            iterations: 1,
            toolCalls: [],
            tokenUsage: [],
            analysis: []
        );

        $array = $result->toArray();
        $this->assertArrayHasKey('learned_abilities', $array);
        $this->assertSame([], $array['learned_abilities']);
    }

    public function testAllExistingFieldsStillWork(): void
    {
        $result = new BotResult(
            success: false,
            answer: 'Partial answer',
            error: 'Something failed',
            iterations: 7,
            toolCalls: [['tool' => 'bash', 'input' => []]],
            tokenUsage: ['input' => 100, 'output' => 50],
            analysis: ['task_type' => 'coding']
        );

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Partial answer', $result->getAnswer());
        $this->assertSame('Something failed', $result->getError());
        $this->assertSame(7, $result->getIterations());
        $this->assertCount(1, $result->getToolCalls());
        $this->assertSame(100, $result->getTokenUsage()['input']);
        $this->assertSame('coding', $result->getAnalysis()['task_type']);
    }
}
