<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Tools\BrewTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrewTool::class)]
class BrewToolTest extends TestCase
{
    public function testGetName(): void
    {
        $tool = new BrewTool();
        $this->assertSame('brew', $tool->getName());
    }

    public function testGetDescription(): void
    {
        $tool = new BrewTool();
        $desc = $tool->getDescription();
        $this->assertStringContainsString('homebrew', strtolower($desc));
        $this->assertStringContainsString('install', strtolower($desc));
    }

    public function testGetInputSchema(): void
    {
        $tool = new BrewTool();
        $schema = $tool->getInputSchema();
        $this->assertArrayHasKey('action', $schema['properties']);
        $this->assertArrayHasKey('packages', $schema['properties']);
        $this->assertContains('action', $schema['required']);
    }

    public function testExecuteReturnsStringWhenHomebrewNotInstalled(): void
    {
        // If brew isn't at common paths and which fails, we get error
        // We can't guarantee brew is/isn't installed, so test that execute returns a ToolResult
        $tool = new BrewTool();
        $result = $tool->execute(['action' => 'list']);
        $this->assertNotNull($result->getContent());
        // Either success (brew installed) or error (brew not found)
        if ($result->isError()) {
            $this->assertStringContainsString('Homebrew', $result->getContent());
        } else {
            $data = json_decode($result->getContent(), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('action', $data);
        }
    }

    public function testExecuteInvalidPackageNameReturnsError(): void
    {
        // Mock: we'd need to inject a fake findBrew. Since we can't easily mock private methods,
        // we test invalid package when brew might be available - but actually the check happens
        // before findBrew for some actions. For 'install' it checks packages first.
        $tool = new BrewTool();
        $result = $tool->execute([
            'action' => 'install',
            'packages' => ['pkg<script>'],
        ]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Invalid package name', $result->getContent());
    }

    public function testExecuteUnknownActionReturnsError(): void
    {
        $tool = new BrewTool();
        $result = $tool->execute(['action' => 'invalid_action']);
        $this->assertTrue($result->isError());
    }

    public function testToDefinition(): void
    {
        $tool = new BrewTool();
        $def = $tool->toDefinition();
        $this->assertSame('brew', $def['name']);
    }
}
