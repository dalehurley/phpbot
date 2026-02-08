<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Apple;

use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Stats\TokenLedger;

/**
 * Smart tool result summarization via Apple FM.
 *
 * Intercepts large tool results before they're sent back to the main LLM
 * and summarizes them using the free on-device Apple Intelligence model.
 * This dramatically reduces input tokens to the expensive LLM.
 *
 * Strategies per tool type:
 * - bash: Preserve exit_code, command, working_directory. Summarize stdout.
 * - file_read/read_file: Summarize file contents. Preserve filename, line count.
 * - search_capabilities: Pass through (already compact).
 * - write_file: Pass through (usually small confirmations).
 * - error results: Always pass through (critical context).
 * - Default: Generic summarization with key data preservation.
 */
class ToolResultSummarizer
{
    /** Default char thresholds (aggressive to catch typical bash output). */
    private const DEFAULT_SKIP_THRESHOLD = 500;
    private const DEFAULT_SUMMARIZE_THRESHOLD = 800;

    /** Tools that should never be summarized (already compact). */
    private const PASS_THROUGH_TOOLS = [
        'search_capabilities',
        'write_file',
        'store_keys',
        'retrieve_keys',
    ];

    private int $skipThreshold;
    private int $summarizeThreshold;

    /** Optional logging callback. */
    private ?\Closure $logger = null;

    public function __construct(
        private SmallModelClient $appleFM,
        private ?TokenLedger $ledger = null,
        array $config = [],
    ) {
        $this->skipThreshold = (int) ($config['skip_threshold'] ?? self::DEFAULT_SKIP_THRESHOLD);
        $this->summarizeThreshold = (int) ($config['summarize_threshold'] ?? self::DEFAULT_SUMMARIZE_THRESHOLD);
    }

    /**
     * Set an optional logger.
     */
    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Check if a tool result should be processed (summarized or compressed).
     *
     * Results larger than skip_threshold will be processed:
     * - Between skip and summarize: light compression (no Apple FM call)
     * - Above summarize: full Apple FM summarization
     */
    public function shouldSummarize(string $toolName, ToolResult $result): bool
    {
        // Never summarize errors — they're critical context
        if ($result->isError()) {
            return false;
        }

        // Never summarize pass-through tools
        if (in_array($toolName, self::PASS_THROUGH_TOOLS, true)) {
            return false;
        }

        $contentLength = strlen($result->getContent());

        // Skip small results entirely
        if ($contentLength <= $this->skipThreshold) {
            return false;
        }

        // Process anything above skip threshold
        return true;
    }

    /**
     * Summarize or compress a tool result.
     *
     * For results between skip and summarize thresholds: light PHP compression.
     * For results above summarize threshold: full Apple FM summarization.
     *
     * Returns a new ToolResult with the processed content, or the
     * original result if processing fails.
     */
    public function summarize(string $toolName, array $toolInput, ToolResult $result): ToolResult
    {
        $originalContent = $result->getContent();
        $originalLength = strlen($originalContent);

        try {
            // Between thresholds: light compression (no Apple FM call)
            if ($originalLength < $this->summarizeThreshold) {
                return $this->lightCompress($toolName, $originalContent, $originalLength);
            }

            // Above threshold: full Apple FM summarization
            $summary = $this->summarizeByToolType($toolName, $toolInput, $originalContent);
            $summaryLength = strlen($summary);
            $bytesSaved = $originalLength - $summaryLength;

            // Only use summary if it's actually shorter
            if ($bytesSaved <= 0) {
                return $result;
            }

            // Record savings in ledger
            $this->ledger?->record(
                'apple_fm',
                'summarization',
                (int) ceil($originalLength / 4),
                (int) ceil($summaryLength / 4),
                0.0,
                $bytesSaved,
            );

            $this->log(sprintf(
                'Summarized %s result: %s chars -> %s chars (saved %s)',
                $toolName,
                number_format($originalLength),
                number_format($summaryLength),
                number_format($bytesSaved),
            ));

            // Build the summarized result with metadata prefix
            $prefixed = "[Summarized: {$originalLength} chars -> {$summaryLength} chars]\n{$summary}";

            return ToolResult::success($prefixed);
        } catch (\Throwable $e) {
            $this->log("Summarization failed for {$toolName}: {$e->getMessage()}");

            // Return original on failure — never lose data
            return $result;
        }
    }

    /**
     * Light compression without an Apple FM call.
     *
     * Strips redundant whitespace, blank lines, and truncates
     * overly long lines. No LLM call, microsecond latency.
     */
    private function lightCompress(string $toolName, string $content, int $originalLength): ToolResult
    {
        // Collapse multiple blank lines to one
        $compressed = preg_replace('/\n{3,}/', "\n\n", $content);

        // Collapse multiple spaces (but not indentation at start of line)
        $compressed = preg_replace('/(?<=\S) {2,}/', ' ', $compressed);

        // Trim trailing whitespace from each line
        $compressed = preg_replace('/[ \t]+$/m', '', $compressed);

        // Truncate very long lines (> 500 chars) — these are often data dumps
        $lines = explode("\n", $compressed);
        $lines = array_map(function ($line) {
            if (strlen($line) > 500) {
                return substr($line, 0, 497) . '...';
            }

            return $line;
        }, $lines);
        $compressed = implode("\n", $lines);

        $compressed = trim($compressed);
        $compressedLength = strlen($compressed);
        $bytesSaved = $originalLength - $compressedLength;

        if ($bytesSaved <= 0) {
            return ToolResult::success($content);
        }

        $this->log(sprintf(
            'Light-compressed %s result: %s chars -> %s chars (saved %s)',
            $toolName,
            number_format($originalLength),
            number_format($compressedLength),
            number_format($bytesSaved),
        ));

        // Record as PHP native savings (no cost)
        $this->ledger?->record(
            'php_native',
            'summarization',
            0,
            0,
            0.0,
            $bytesSaved,
        );

        return ToolResult::success($compressed);
    }

    // -------------------------------------------------------------------------
    // Tool-specific summarization
    // -------------------------------------------------------------------------

    /**
     * Route to the appropriate summarization strategy based on tool type.
     */
    private function summarizeByToolType(string $toolName, array $toolInput, string $content): string
    {
        return match ($toolName) {
            'bash' => $this->summarizeBash($toolInput, $content),
            'file_read', 'read_file' => $this->summarizeFileRead($toolInput, $content),
            default => $this->summarizeGeneric($toolName, $toolInput, $content),
        };
    }

    /**
     * Summarize bash tool output.
     *
     * Preserves exit_code, command, working_directory.
     * Summarizes stdout. Keeps stderr as-is.
     */
    private function summarizeBash(array $toolInput, string $content): string
    {
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return $this->summarizeGeneric('bash', $toolInput, $content);
        }

        $exitCode = $decoded['exit_code'] ?? $decoded['exitCode'] ?? null;
        $command = $decoded['command'] ?? ($toolInput['command'] ?? '');
        $workDir = $decoded['working_directory'] ?? '';
        $stdout = $decoded['stdout'] ?? $decoded['output'] ?? '';
        $stderr = $decoded['stderr'] ?? '';

        // Only summarize if stdout is large
        if (strlen($stdout) > $this->skipThreshold) {
            $context = "Bash command output. Command: {$command}";
            $stdout = $this->appleFM->summarize($stdout, $context, 256);
        }

        // Reconstruct the result preserving structured data
        $result = [];
        if ($command !== '') {
            $result['command'] = $command;
        }
        if ($exitCode !== null) {
            $result['exit_code'] = $exitCode;
        }
        if ($workDir !== '') {
            $result['working_directory'] = $workDir;
        }
        $result['stdout'] = $stdout;
        if ($stderr !== '') {
            $result['stderr'] = $stderr;
        }
        $result['success'] = ($exitCode ?? 0) === 0;

        return json_encode($result);
    }

    /**
     * Summarize file read output.
     *
     * Preserves filename, line count, language. Summarizes file contents
     * with structural overview (classes, functions, key sections).
     */
    private function summarizeFileRead(array $toolInput, string $content): string
    {
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            // Raw file content (not JSON-wrapped)
            $filename = $toolInput['path'] ?? $toolInput['file'] ?? 'unknown';
            $context = "File contents of: {$filename}";

            return $this->appleFM->summarize($content, $context, 384);
        }

        $filename = $decoded['filename'] ?? $decoded['path'] ?? ($toolInput['path'] ?? '');
        $fileContent = $decoded['content'] ?? $decoded['contents'] ?? '';
        $lineCount = $decoded['line_count'] ?? substr_count($fileContent, "\n") + 1;
        $truncated = $decoded['truncated'] ?? false;

        // Only summarize if content is large
        if (strlen($fileContent) > $this->skipThreshold) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $context = "File contents of {$filename} ({$ext}, {$lineCount} lines)";
            $fileContent = $this->appleFM->summarize($fileContent, $context, 384);
        }

        $result = ['filename' => $filename, 'line_count' => $lineCount];
        if ($truncated) {
            $result['truncated'] = true;
        }
        $result['content'] = $fileContent;

        return json_encode($result);
    }

    /**
     * Generic summarization for any other tool.
     */
    private function summarizeGeneric(string $toolName, array $toolInput, string $content): string
    {
        $inputSummary = json_encode($toolInput);
        if (strlen($inputSummary) > 200) {
            $inputSummary = substr($inputSummary, 0, 197) . '...';
        }

        $context = "Output from tool '{$toolName}' with input: {$inputSummary}";

        return $this->appleFM->summarize($content, $context, 384);
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
