<?php

declare(strict_types=1);

namespace Tests\Tools;

use Dalehurley\Phpbot\Tools\SearchComputerTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SearchComputerTool::class)]
class SearchComputerToolTest extends TestCase
{
    public function testGetName(): void
    {
        $tool = new SearchComputerTool();
        $this->assertSame('search_computer', $tool->getName());
    }

    public function testGetDescription(): void
    {
        $tool = new SearchComputerTool();
        $this->assertStringContainsString('search', strtolower($tool->getDescription()));
    }

    public function testExecuteEmptySearchTermsReturnsError(): void
    {
        $tool = new SearchComputerTool();
        $result = $tool->execute(['search_terms' => []]);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('search_terms', $result->getContent());
    }

    public function testExecuteNonArraySearchTermsReturnsError(): void
    {
        $tool = new SearchComputerTool();
        $result = $tool->execute(['search_terms' => 'string']);
        $this->assertTrue($result->isError());
    }

    public function testExecuteReturnsResults(): void
    {
        $tool = new SearchComputerTool();
        $result = $tool->execute(['search_terms' => ['OPENAI_API_KEY', 'PATH']]);
        $this->assertFalse($result->isError());
        // Result is JSON - don't json_decode expecting SearchCapabilities format
        $content = $result->getContent();
        $this->assertIsString($content);
        $data = json_decode($content, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('found', $data);
        $this->assertArrayHasKey('search_terms', $data);
    }

    public function testExecuteWithIncludeOptions(): void
    {
        $tool = new SearchComputerTool();
        $result = $tool->execute([
            'search_terms' => ['TEST_KEY'],
            'include_env_vars' => true,
            'include_shell_profiles' => false,
            'include_dotenv_files' => false,
            'include_config_files' => false,
        ]);
        $this->assertFalse($result->isError());
    }

    public function testToDefinition(): void
    {
        $tool = new SearchComputerTool();
        $def = $tool->toDefinition();
        $this->assertSame('search_computer', $def['name']);
    }
}
