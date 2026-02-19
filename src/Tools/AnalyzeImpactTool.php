<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;

class AnalyzeImpactTool implements ToolInterface
{
    public function getName(): string
    {
        return 'analyze_impact';
    }

    public function getDescription(): string
    {
        return 'Analyze the potential impact of a planned operation before executing it. '
            . 'Scans for file dependencies, checks Git status, assesses risk level (low/medium/high), '
            . 'and suggests testing steps. Use this BEFORE destructive operations to understand side effects.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'description' => 'Description of the planned operation (e.g. "replace OpenAI key across codebase")',
                ],
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Files that will be modified',
                ],
                'patterns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Strings or patterns being changed (for dependency scanning)',
                ],
                'working_directory' => [
                    'type' => 'string',
                    'description' => 'Directory to scan for dependencies (defaults to current dir)',
                ],
            ],
            'required' => ['operation'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $operation = (string) ($input['operation'] ?? '');
        $files = array_map('strval', (array) ($input['files'] ?? []));
        $patterns = array_map('strval', (array) ($input['patterns'] ?? []));
        $workingDir = (string) ($input['working_directory'] ?? getcwd());

        $report = [
            'operation' => $operation,
            'files_to_modify' => $files,
            'git_status' => $this->getGitStatus($workingDir),
            'dependencies' => $this->findDependencies($files, $patterns, $workingDir),
            'risk_assessment' => [],
            'suggested_tests' => [],
        ];

        $report['risk_assessment'] = $this->assessRisk($report, $files, $patterns);
        $report['suggested_tests'] = $this->suggestTests($operation, $files, $report['risk_assessment']);

        return ToolResult::success(json_encode($report, JSON_PRETTY_PRINT));
    }

    /**
     * Get the current Git status for context.
     *
     * @return array{is_git_repo: bool, branch: string, uncommitted_files: int, modified_files: string[], status_summary: string}
     */
    private function getGitStatus(string $workingDir): array
    {
        $result = ['is_git_repo' => false, 'branch' => '', 'uncommitted_files' => 0, 'modified_files' => [], 'status_summary' => ''];

        // Check if inside a git repo
        $gitCheck = shell_exec('git -C ' . escapeshellarg($workingDir) . ' rev-parse --is-inside-work-tree 2>/dev/null');
        if (trim((string) $gitCheck) !== 'true') {
            return $result;
        }

        $result['is_git_repo'] = true;

        $branch = trim((string) shell_exec('git -C ' . escapeshellarg($workingDir) . ' branch --show-current 2>/dev/null'));
        $result['branch'] = $branch;

        $statusOutput = shell_exec('git -C ' . escapeshellarg($workingDir) . ' status --porcelain 2>/dev/null');
        $lines = array_filter(explode("\n", trim((string) $statusOutput)));
        $result['uncommitted_files'] = count($lines);
        $result['modified_files'] = array_values(array_slice($lines, 0, 10));

        if ($result['uncommitted_files'] > 0) {
            $result['status_summary'] = "⚠️  {$result['uncommitted_files']} uncommitted change(s) on branch '{$branch}'";
        } else {
            $result['status_summary'] = "✓ Clean working tree on branch '{$branch}'";
        }

        return $result;
    }

    /**
     * Find files that depend on the specified files or patterns.
     *
     * @param string[] $files
     * @param string[] $patterns
     * @return array{pattern_matches: array<string, string[]>, dependent_files: string[]}
     */
    private function findDependencies(array $files, array $patterns, string $workingDir): array
    {
        $patternMatches = [];
        $dependentFiles = [];

        // Scan for files that reference the target filenames
        foreach ($files as $file) {
            $basename = basename($file);
            $escaped = escapeshellarg($basename);
            $grepOutput = shell_exec(
                "grep -rli {$escaped} " . escapeshellarg($workingDir) .
                " --include='*.php' --include='*.env' --include='*.json' --include='*.yaml' --include='*.yml'" .
                " 2>/dev/null | head -20"
            );
            if ($grepOutput !== null && trim($grepOutput) !== '') {
                $refs = array_filter(explode("\n", trim($grepOutput)));
                if (!empty($refs)) {
                    $patternMatches[$basename] = array_values($refs);
                    foreach ($refs as $ref) {
                        if (!in_array($ref, $dependentFiles, true) && $ref !== $file) {
                            $dependentFiles[] = $ref;
                        }
                    }
                }
            }
        }

        // Scan for files that use the given patterns
        foreach ($patterns as $pattern) {
            if (strlen($pattern) < 4) {
                continue;
            }
            $escaped = escapeshellarg($pattern);
            $grepOutput = shell_exec(
                "grep -rli {$escaped} " . escapeshellarg($workingDir) .
                " --include='*.php' --include='*.env' --include='*.json' 2>/dev/null | head -20"
            );
            if ($grepOutput !== null && trim($grepOutput) !== '') {
                $refs = array_filter(explode("\n", trim($grepOutput)));
                $patternMatches[$pattern] = array_values($refs);
                foreach ($refs as $ref) {
                    if (!in_array($ref, $dependentFiles, true)) {
                        $dependentFiles[] = $ref;
                    }
                }
            }
        }

        return [
            'pattern_matches' => $patternMatches,
            'dependent_files' => array_values(array_unique($dependentFiles)),
        ];
    }

    /**
     * Assess the risk level of the planned operation.
     *
     * @return array{level: string, score: int, factors: string[]}
     */
    private function assessRisk(array $report, array $files, array $patterns): array
    {
        $score = 0;
        $factors = [];

        // Uncommitted changes increase risk
        if (($report['git_status']['uncommitted_files'] ?? 0) > 0) {
            $score += 2;
            $factors[] = "Uncommitted changes exist — changes may conflict";
        }

        // Many files to modify
        $fileCount = count($files);
        if ($fileCount > 10) {
            $score += 3;
            $factors[] = "{$fileCount} files will be modified — large blast radius";
        } elseif ($fileCount > 3) {
            $score += 1;
            $factors[] = "{$fileCount} files will be modified";
        }

        // Dependent files found
        $depCount = count($report['dependencies']['dependent_files'] ?? []);
        if ($depCount > 5) {
            $score += 3;
            $factors[] = "{$depCount} other files depend on the targets";
        } elseif ($depCount > 0) {
            $score += 1;
            $factors[] = "{$depCount} dependent file(s) may be affected";
        }

        // Credential patterns are high risk
        foreach ($patterns as $p) {
            if (preg_match('/sk-|api_key|token|secret|password/i', $p)) {
                $score += 2;
                $factors[] = "Operation involves credentials/secrets";
                break;
            }
        }

        // Non-reversible operation keywords
        if (preg_match('/delete|drop|truncate|rm |remove all/i', $report['operation'])) {
            $score += 3;
            $factors[] = "Operation appears destructive/irreversible";
        }

        $level = match (true) {
            $score >= 6 => 'high',
            $score >= 3 => 'medium',
            default => 'low',
        };

        return [
            'level' => $level,
            'score' => $score,
            'factors' => $factors,
        ];
    }

    /**
     * Suggest concrete testing steps based on the operation and risk.
     *
     * @return string[]
     */
    private function suggestTests(string $operation, array $files, array $riskAssessment): array
    {
        $tests = [];
        $level = $riskAssessment['level'];

        if ($level === 'high') {
            $tests[] = 'Create a Git commit or branch before proceeding as a safety checkpoint';
            $tests[] = 'Test on a single file first before bulk operation';
        }

        if (preg_match('/key|token|credential|secret/i', $operation)) {
            $tests[] = 'Validate new credentials against the provider API before mass-replacing';
            $tests[] = 'Keep old credentials accessible until new ones are confirmed working';
        }

        if (!empty($files)) {
            $phpFiles = array_filter($files, fn($f) => str_ends_with($f, '.php'));
            if (!empty($phpFiles)) {
                $tests[] = 'Run `php -l <file>` on modified PHP files to check syntax';
            }
        }

        $tests[] = 'Use verify_operation tool after the bulk change to confirm correctness';
        $tests[] = 'Check application logs for errors after applying changes';

        if ($level !== 'low') {
            $tests[] = 'Rollback is available via the rollback tool if something goes wrong';
        }

        return $tests;
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
