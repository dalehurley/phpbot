<?php

declare(strict_types=1);

namespace Tests\Skill;

use Dalehurley\Phpbot\Skill\SkillMarkdownBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillMarkdownBuilder::class)]
class SkillMarkdownBuilderTest extends TestCase
{
    public function testBuildMinimalSkill(): void
    {
        $generalized = [
            'description' => 'A minimal skill for testing',
            'procedure' => 'Step 1. Do something.',
            'required_credentials' => [],
            'input_parameters' => [],
        ];

        $md = SkillMarkdownBuilder::build('minimal-skill', $generalized);

        $this->assertStringContainsString('---', $md);
        $this->assertStringContainsString('name: minimal-skill', $md);
        $this->assertStringContainsString('description:', $md);
        $this->assertStringContainsString('# Minimal Skill', $md);
        $this->assertStringContainsString('## Procedure', $md);
        $this->assertStringContainsString('Step 1. Do something.', $md);
    }

    public function testBuildFrontmatterEscapesQuotes(): void
    {
        $generalized = [
            'description' => 'Skill with "quotes" in description',
            'procedure' => '',
        ];

        $md = SkillMarkdownBuilder::build('test-skill', $generalized);
        $this->assertStringContainsString('\\"', $md);
    }

    public function testBuildWithRequiredCredentials(): void
    {
        $generalized = [
            'description' => 'Send SMS',
            'procedure' => '',
            'required_credentials' => [
                ['key_store_key' => 'twilio_account_sid', 'env_var' => 'TWILIO_ACCOUNT_SID', 'description' => 'Account SID'],
                ['key_store_key' => 'twilio_auth_token', 'description' => 'Auth token'],
            ],
        ];

        $md = SkillMarkdownBuilder::build('send-sms', $generalized);
        $this->assertStringContainsString('## Required Credentials', $md);
        $this->assertStringContainsString('twilio_account_sid', $md);
        $this->assertStringContainsString('TWILIO_ACCOUNT_SID', $md);
        $this->assertStringContainsString('Account SID', $md);
        $this->assertStringContainsString('twilio_auth_token', $md);
    }

    public function testBuildWithInputParameters(): void
    {
        $generalized = [
            'description' => 'Convert file',
            'procedure' => '',
            'input_parameters' => [
                ['name' => 'input_file', 'required' => true, 'description' => 'Path to file', 'example' => '/path/to/file.pdf'],
                ['name' => 'output_format', 'required' => false, 'description' => 'Format', 'example' => 'docx'],
            ],
        ];

        $md = SkillMarkdownBuilder::build('convert-file', $generalized);
        $this->assertStringContainsString('## Input Parameters', $md);
        $this->assertStringContainsString('input_file', $md);
        $this->assertStringContainsString('Yes', $md);
        $this->assertStringContainsString('No', $md);
    }

    public function testBuildWithOutputFormat(): void
    {
        $generalized = [
            'description' => 'Generate report',
            'procedure' => '',
            'output_format' => 'PDF file saved to output path',
        ];

        $md = SkillMarkdownBuilder::build('generate-report', $generalized);
        $this->assertStringContainsString('## Output', $md);
        $this->assertStringContainsString('PDF file saved', $md);
    }

    public function testBuildWithBundledScripts(): void
    {
        $generalized = [
            'description' => 'Script skill',
            'procedure' => '',
        ];
        $bundledScripts = [
            ['path' => 'scripts/send.sh', 'extension' => 'sh', 'description' => 'Send script', 'source' => 'write_file'],
        ];

        $md = SkillMarkdownBuilder::build('script-skill', $generalized, $bundledScripts);
        $this->assertStringContainsString('## Bundled Scripts', $md);
        $this->assertStringContainsString('scripts/send.sh', $md);
    }

    public function testBuildWithExamplesArray(): void
    {
        $generalized = [
            'description' => 'Example skill',
            'procedure' => '',
            'examples' => ['Send SMS to +1234567890', 'Send SMS saying hello'],
        ];

        $md = SkillMarkdownBuilder::build('example-skill', $generalized);
        $this->assertStringContainsString('## Example', $md);
        $this->assertStringContainsString('Send SMS to', $md);
    }

    public function testBuildWithLegacyExampleRequest(): void
    {
        $generalized = [
            'description' => 'Legacy skill',
            'procedure' => '',
            'example_request' => 'Single example request',
        ];

        $md = SkillMarkdownBuilder::build('legacy-skill', $generalized);
        $this->assertStringContainsString('Single example request', $md);
    }

    public function testBuildWithNotes(): void
    {
        $generalized = [
            'description' => 'Skill with notes',
            'procedure' => '',
            'notes' => ['Tip 1', 'Tip 2', 'Gotcha to watch'],
        ];

        $md = SkillMarkdownBuilder::build('notes-skill', $generalized);
        $this->assertStringContainsString('## Notes', $md);
        $this->assertStringContainsString('Tip 1', $md);
        $this->assertStringContainsString('Gotcha to watch', $md);
    }

    public function testBuildWithBundledResources(): void
    {
        $generalized = [
            'description' => 'Resource skill',
            'procedure' => '',
            'bundled_resources' => [
                ['path' => 'brand.md', 'description' => 'Brand guidelines'],
            ],
        ];

        $md = SkillMarkdownBuilder::build('resource-skill', $generalized);
        $this->assertStringContainsString('## Resources', $md);
        $this->assertStringContainsString('brand.md', $md);
        $this->assertStringContainsString('Brand guidelines', $md);
    }

    public function testBuildWithSetupNotes(): void
    {
        $generalized = [
            'description' => 'Skill',
            'procedure' => '',
            'required_credentials' => [['key_store_key' => 'api_key', 'description' => 'Key']],
            'setup_notes' => [
                ['service' => 'Twilio', 'instructions' => 'Create account at twilio.com'],
            ],
        ];

        $md = SkillMarkdownBuilder::build('setup-skill', $generalized);
        $this->assertStringContainsString('### Twilio Setup', $md);
        $this->assertStringContainsString('twilio.com', $md);
    }
}