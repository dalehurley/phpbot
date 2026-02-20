<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Tools\AnalyzeImpactTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnalyzeImpactTool::class)]
class AnalyzeImpactToolTest extends TestCase
{
    private AnalyzeImpactTool $tool;

    protected function setUp(): void
    {
        $this->tool = new AnalyzeImpactTool();
    }

    public function testGetName(): void
    {
        $this->assertSame('analyze_impact', $this->tool->getName());
    }

    public function testGetDescription(): void
    {
        $this->assertStringContainsString('impact', strtolower($this->tool->getDescription()));
    }

    public function testGetInputSchema(): void
    {
        $schema = $this->tool->getInputSchema();
        $this->assertArrayHasKey('operation', $schema['properties']);
        $this->assertArrayHasKey('files', $schema['properties']);
        $this->assertArrayHasKey('patterns', $schema['properties']);
        $this->assertContains('operation', $schema['required']);
    }

    public function testExecuteReturnsReport(): void
    {
        $result = $this->tool->execute([
            'operation' => 'Replace API keys',
            'files' => [],
            'patterns' => [],
        ]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertSame('Replace API keys', $data['operation']);
        $this->assertArrayHasKey('git_status', $data);
        $this->assertArrayHasKey('dependencies', $data);
        $this->assertArrayHasKey('risk_assessment', $data);
        $this->assertArrayHasKey('suggested_tests', $data);
    }

    public function testExecuteRiskAssessmentLow(): void
    {
        $result = $this->tool->execute([
            'operation' => 'Update config',
            'files' => ['config.json'],
            'patterns' => ['url'],
        ]);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertArrayHasKey('level', $data['risk_assessment']);
        $this->assertContains($data['risk_assessment']['level'], ['low', 'medium', 'high']);
    }

    public function testExecuteSuggestsTests(): void
    {
        $result = $this->tool->execute(['operation' => 'Rotate credentials']);
        $this->assertFalse($result->isError());
        $data = json_decode($result->getContent(), true);
        $this->assertNotEmpty($data['suggested_tests']);
    }

    public function testToDefinition(): void
    {
        $def = $this->tool->toDefinition();
        $this->assertSame('analyze_impact', $def['name']);
    }
}
