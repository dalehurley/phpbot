<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Storage\BackupManager;

class VerifyOperationTool implements ToolInterface
{
    private ?BackupManager $backupManager;

    public function __construct(?BackupManager $backupManager = null)
    {
        $this->backupManager = $backupManager;
    }

    public function getName(): string
    {
        return 'verify_operation';
    }

    public function getDescription(): string
    {
        return 'Verify the outcome of a bulk file operation by sampling changed files. '
            . 'Checks that expected patterns appear (or disappear) in modified files '
            . 'and reports pass/fail per file. Call this after bulk edits to confirm correctness.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of file paths to verify (up to 10 are sampled)',
                ],
                'expected_pattern' => [
                    'type' => 'string',
                    'description' => 'String or regex pattern that SHOULD exist in the files after the operation',
                ],
                'forbidden_pattern' => [
                    'type' => 'string',
                    'description' => 'String or regex pattern that should NOT exist in the files after the operation',
                ],
                'use_regex' => [
                    'type' => 'boolean',
                    'description' => 'Treat patterns as regular expressions (default: false, exact string match)',
                    'default' => false,
                ],
            ],
            'required' => ['files'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $files = (array) ($input['files'] ?? []);
        $expectedPattern = (string) ($input['expected_pattern'] ?? '');
        $forbiddenPattern = (string) ($input['forbidden_pattern'] ?? '');
        $useRegex = (bool) ($input['use_regex'] ?? false);

        if (empty($files)) {
            return ToolResult::error('At least one file path is required.');
        }

        // Sample up to 10 files
        $sample = array_slice($files, 0, 10);
        $results = [];
        $passCount = 0;
        $failCount = 0;

        foreach ($sample as $path) {
            $path = (string) $path;
            $fileResult = ['file' => $path, 'status' => 'pass', 'issues' => []];

            if (!is_file($path)) {
                $fileResult['status'] = 'error';
                $fileResult['issues'][] = 'File not found';
                $failCount++;
                $results[] = $fileResult;
                continue;
            }

            $content = (string) file_get_contents($path);

            // Check expected pattern
            if ($expectedPattern !== '') {
                $found = $useRegex
                    ? (bool) @preg_match($expectedPattern, $content)
                    : str_contains($content, $expectedPattern);

                if (!$found) {
                    $fileResult['status'] = 'fail';
                    $fileResult['issues'][] = "Expected pattern not found: {$expectedPattern}";
                }
            }

            // Check forbidden pattern
            if ($forbiddenPattern !== '') {
                $found = $useRegex
                    ? (bool) @preg_match($forbiddenPattern, $content)
                    : str_contains($content, $forbiddenPattern);

                if ($found) {
                    $fileResult['status'] = 'fail';
                    $fileResult['issues'][] = "Forbidden pattern still present: {$forbiddenPattern}";
                }
            }

            // Compare against backup if available
            if ($this->backupManager !== null && $fileResult['status'] === 'pass') {
                $backups = $this->backupManager->listBackups($path);
                if (!empty($backups)) {
                    $backupContent = @file_get_contents($backups[0]['path']);
                    if ($backupContent !== false && $backupContent === $content) {
                        $fileResult['issues'][] = 'Warning: file content identical to backup (no changes detected)';
                    }
                }
            }

            if ($fileResult['status'] === 'pass') {
                $passCount++;
            } else {
                $failCount++;
            }

            $results[] = $fileResult;
        }

        $totalSampled = count($sample);
        $totalFiles = count($files);

        $summary = sprintf(
            '%d/%d sampled files passed. %d failed.',
            $passCount,
            $totalSampled,
            $failCount,
        );

        if ($totalFiles > $totalSampled) {
            $summary .= sprintf(' (%d files not sampled)', $totalFiles - $totalSampled);
        }

        return ToolResult::success(json_encode([
            'summary' => $summary,
            'total_files' => $totalFiles,
            'sampled' => $totalSampled,
            'passed' => $passCount,
            'failed' => $failCount,
            'overall' => $failCount === 0 ? 'PASS' : 'FAIL',
            'results' => $results,
        ]));
    }

    public function toDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'input_schema' => $this->getInputSchema(),
        ];
    }
}
