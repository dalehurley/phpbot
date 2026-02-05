<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tests;

use PHPUnit\Framework\TestCase;
use Dalehurley\Phpbot\Ability\AbilityLogger;
use Dalehurley\Phpbot\Storage\AbilityStore;
use ClaudePhp\ClaudePhp;
use ReflectionMethod;

class AbilityLoggerTest extends TestCase
{
    private string $tempDir;
    private AbilityStore $store;
    private AbilityLogger $logger;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phpbot_test_logger_' . uniqid();
        $this->store = new AbilityStore($this->tempDir);

        // Create logger with a dummy client (won't be used in unit tests)
        $client = $this->createMock(ClaudePhp::class);
        $this->logger = new AbilityLogger($client, $this->store, 'claude-haiku-4-5');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
    }

    // --- Tests for parseAndStore (private method via reflection) ---

    private function invokeParseAndStore(string $answer): array
    {
        $method = new ReflectionMethod(AbilityLogger::class, 'parseAndStore');
        $method->setAccessible(true);
        return $method->invoke($this->logger, $answer);
    }

    public function testParseAndStoreWithValidJson(): void
    {
        $json = json_encode([
            [
                'title' => 'Use retry for flaky API',
                'description' => 'When API calls fail intermittently, retry with backoff',
                'obstacle' => 'API returned 503 errors',
                'strategy' => 'Added exponential backoff retry logic',
                'outcome' => 'API calls succeeded after 2 retries',
                'tags' => ['api', 'retry', 'reliability'],
            ],
        ]);

        $result = $this->invokeParseAndStore($json);

        $this->assertCount(1, $result);
        $this->assertSame('Use retry for flaky API', $result[0]['title']);
        $this->assertSame('When API calls fail intermittently, retry with backoff', $result[0]['description']);
        $this->assertSame('API returned 503 errors', $result[0]['obstacle']);
        $this->assertArrayHasKey('id', $result[0]);

        // Verify it was actually stored
        $this->assertSame(1, $this->store->count());
    }

    public function testParseAndStoreWithMarkdownCodeBlock(): void
    {
        $answer = "Here are the abilities found:\n\n```json\n" . json_encode([
            ['title' => 'Markdown wrapped ability', 'description' => 'Extracted from code block'],
        ]) . "\n```\n\nThose are the abilities.";

        $result = $this->invokeParseAndStore($answer);

        $this->assertCount(1, $result);
        $this->assertSame('Markdown wrapped ability', $result[0]['title']);
    }

    public function testParseAndStoreWithBareCodeBlock(): void
    {
        $answer = "```\n" . json_encode([
            ['title' => 'Bare code block', 'description' => 'No json tag'],
        ]) . "\n```";

        $result = $this->invokeParseAndStore($answer);

        $this->assertCount(1, $result);
        $this->assertSame('Bare code block', $result[0]['title']);
    }

    public function testParseAndStoreWithEmptyArray(): void
    {
        $result = $this->invokeParseAndStore('[]');

        $this->assertSame([], $result);
        $this->assertSame(0, $this->store->count());
    }

    public function testParseAndStoreWithInvalidJson(): void
    {
        $result = $this->invokeParseAndStore('not valid json at all');

        $this->assertSame([], $result);
        $this->assertSame(0, $this->store->count());
    }

    public function testParseAndStoreSkipsEntriesWithoutTitle(): void
    {
        $json = json_encode([
            ['title' => 'Valid ability', 'description' => 'Has a title'],
            ['description' => 'No title here'],
            ['title' => '', 'description' => 'Empty title'],
        ]);

        $result = $this->invokeParseAndStore($json);

        $this->assertCount(1, $result);
        $this->assertSame('Valid ability', $result[0]['title']);
    }

    public function testParseAndStoreMultipleAbilities(): void
    {
        $json = json_encode([
            ['title' => 'First ability', 'description' => 'Desc 1', 'tags' => ['a']],
            ['title' => 'Second ability', 'description' => 'Desc 2', 'tags' => ['b']],
            ['title' => 'Third ability', 'description' => 'Desc 3', 'tags' => ['c']],
        ]);

        $result = $this->invokeParseAndStore($json);

        $this->assertCount(3, $result);
        $this->assertSame(3, $this->store->count());
    }

    public function testParseAndStoreHandlesMissingFields(): void
    {
        $json = json_encode([
            ['title' => 'Minimal ability'],
        ]);

        $result = $this->invokeParseAndStore($json);

        $this->assertCount(1, $result);
        $this->assertSame('Minimal ability', $result[0]['title']);
        $this->assertSame('', $result[0]['description']);
        $this->assertSame('', $result[0]['obstacle']);
        $this->assertSame('', $result[0]['strategy']);
        $this->assertSame('', $result[0]['outcome']);
        $this->assertSame([], $result[0]['tags']);
    }

    public function testParseAndStoreWithSurroundingText(): void
    {
        $answer = "After analyzing the execution, I found these abilities:\n"
            . json_encode([['title' => 'Embedded in text', 'description' => 'Found it']])
            . "\nThat's all I found.";

        $result = $this->invokeParseAndStore($answer);

        $this->assertCount(1, $result);
        $this->assertSame('Embedded in text', $result[0]['title']);
    }

    // --- Tests for buildExecutionTrace (private method via reflection) ---

    private function invokeBuildExecutionTrace(string $input, array $analysis, object $agentResult): string
    {
        $method = new ReflectionMethod(AbilityLogger::class, 'buildExecutionTrace');
        $method->setAccessible(true);
        return $method->invoke($this->logger, $input, $analysis, $agentResult);
    }

    private function createMockAgentResult(
        bool $success = true,
        int $iterations = 3,
        array $toolCalls = [],
        ?string $answer = 'Task completed',
        ?string $error = null
    ): object {
        $result = new class($success, $iterations, $toolCalls, $answer, $error) {
            public function __construct(
                private bool $success,
                private int $iterations,
                private array $toolCalls,
                private ?string $answer,
                private ?string $error
            ) {}
            public function isSuccess(): bool { return $this->success; }
            public function getIterations(): int { return $this->iterations; }
            public function getToolCalls(): array { return $this->toolCalls; }
            public function getAnswer(): ?string { return $this->answer; }
            public function getError(): ?string { return $this->error; }
        };

        return $result;
    }

    public function testBuildExecutionTraceBasic(): void
    {
        $result = $this->createMockAgentResult();
        $analysis = [
            'task_type' => 'coding',
            'complexity' => 'medium',
            'estimated_steps' => 3,
        ];

        $trace = $this->invokeBuildExecutionTrace('Fix the bug', $analysis, $result);

        $this->assertStringContainsString('## Original Request', $trace);
        $this->assertStringContainsString('Fix the bug', $trace);
        $this->assertStringContainsString('Type: coding', $trace);
        $this->assertStringContainsString('Complexity: medium', $trace);
        $this->assertStringContainsString('Steps estimated: 3', $trace);
        $this->assertStringContainsString('Success: yes', $trace);
        $this->assertStringContainsString('Iterations used: 3', $trace);
        $this->assertStringContainsString('Task completed', $trace);
    }

    public function testBuildExecutionTraceWithToolCalls(): void
    {
        $result = $this->createMockAgentResult(
            toolCalls: [
                ['tool' => 'bash', 'input' => ['command' => 'ls'], 'result' => 'file1.php file2.php'],
                ['tool' => 'read_file', 'input' => ['path' => '/tmp/test.php'], 'result' => '<?php echo "hi";'],
                ['tool' => 'bash', 'input' => ['command' => 'php test.php'], 'result' => 'hi'],
            ]
        );

        $trace = $this->invokeBuildExecutionTrace('Run tests', ['task_type' => 'automation'], $result);

        $this->assertStringContainsString('Tools called: bash, read_file', $trace);
        $this->assertStringContainsString('## Tool Call Sequence', $trace);
        $this->assertStringContainsString('**bash**', $trace);
        $this->assertStringContainsString('**read_file**', $trace);
    }

    public function testBuildExecutionTraceWithError(): void
    {
        $result = $this->createMockAgentResult(
            success: false,
            answer: null,
            error: 'Permission denied'
        );

        $trace = $this->invokeBuildExecutionTrace('Delete files', [], $result);

        $this->assertStringContainsString('Success: no', $trace);
        $this->assertStringContainsString('## Error', $trace);
        $this->assertStringContainsString('Permission denied', $trace);
    }

    public function testBuildExecutionTraceTruncatesLongAnswer(): void
    {
        $longAnswer = str_repeat('x', 1000);
        $result = $this->createMockAgentResult(answer: $longAnswer);

        $trace = $this->invokeBuildExecutionTrace('Long task', [], $result);

        $this->assertStringContainsString('...', $trace);
        // Should not contain the full 1000-char answer
        $this->assertStringNotContainsString($longAnswer, $trace);
    }

    public function testBuildExecutionTraceTruncatesLongToolInput(): void
    {
        $longInput = str_repeat('y', 500);
        $result = $this->createMockAgentResult(
            toolCalls: [
                ['tool' => 'bash', 'input' => ['command' => $longInput], 'result' => 'ok'],
            ]
        );

        $trace = $this->invokeBuildExecutionTrace('Task', [], $result);

        // The encoded JSON of input will be >300 chars, so should be truncated
        $this->assertStringContainsString('...', $trace);
    }

    public function testBuildExecutionTraceWithMissingAnalysisFields(): void
    {
        $result = $this->createMockAgentResult();

        $trace = $this->invokeBuildExecutionTrace('Simple task', [], $result);

        $this->assertStringContainsString('Type: unknown', $trace);
        $this->assertStringContainsString('Complexity: unknown', $trace);
        $this->assertStringContainsString('Steps estimated: ?', $trace);
    }
}
