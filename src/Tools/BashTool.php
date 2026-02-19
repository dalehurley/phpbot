<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\DryRun\DryRunContext;
use Dalehurley\Phpbot\Storage\BackupManager;
use Dalehurley\Phpbot\Storage\RollbackManager;

class BashTool implements ToolInterface
{
    private array $config;
    private array $allowedCommands;
    private array $blockedCommands;
    private string $workingDirectory;
    private int $consecutiveEmptyCount = 0;
    private int $maxOutputChars;
    private ?BackupManager $backupManager;
    private ?RollbackManager $rollbackManager;
    private ?string $sessionId;

    public function __construct(array $config = [], ?BackupManager $backupManager = null, ?RollbackManager $rollbackManager = null, ?string $sessionId = null)
    {
        $this->config = $config;
        $this->workingDirectory = $config['working_directory'] ?? getcwd();
        $this->maxOutputChars = (int) ($config['bash_max_output_chars'] ?? 15000);
        $this->backupManager = $backupManager;
        $this->rollbackManager = $rollbackManager;
        $this->sessionId = $sessionId;

        // Commands that are explicitly allowed (empty = all allowed except blocked)
        $this->allowedCommands = $config['allowed_commands'] ?? [];

        // Commands that are blocked for safety
        $this->blockedCommands = $config['blocked_commands'] ?? [
            'rm -rf /',
            'rm -rf /*',
            'mkfs',
            'dd if=',
            ':(){:|:&};:',  // Fork bomb
            '> /dev/sda',
            'chmod -R 777 /',
            'chown -R',
        ];
    }

    public function getName(): string
    {
        return 'bash';
    }

    public function getDescription(): string
    {
        return 'Execute bash commands on the system. Use this to run shell commands, interact with files, install packages, run scripts, and perform system operations. Returns stdout, stderr, and exit code.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The bash command to execute. Can be a single command or multiple commands separated by && or ;'
                ],
                'working_directory' => [
                    'type' => 'string',
                    'description' => 'Optional working directory for the command. Defaults to current directory.'
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Timeout in seconds. Default is 60 seconds.',
                    'default' => 60
                ],
                'env' => [
                    'type' => 'object',
                    'description' => 'Optional environment variables to set for the command.',
                    'additionalProperties' => ['type' => 'string']
                ]
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $command = $input['command'] ?? '';
        $workingDir = $input['working_directory'] ?? $this->workingDirectory;
        $timeout = $input['timeout'] ?? 60;
        $env = $input['env'] ?? [];

        // Validate command is not empty — escalating messages guide the model
        if (empty(trim($command))) {
            $this->consecutiveEmptyCount++;
            return ToolResult::error($this->getEmptyCommandMessage());
        }

        // Reset counter on valid command
        $this->consecutiveEmptyCount = 0;

        // Dry-run: simulate without executing
        if (DryRunContext::isActive()) {
            DryRunContext::record('bash', 'Execute command', [
                'command' => $command,
                'working_directory' => $workingDir,
            ]);
            return ToolResult::success(json_encode([
                'stdout' => '',
                'stderr' => '',
                'exit_code' => 0,
                'command' => $command,
                'working_directory' => $workingDir,
                'success' => true,
                'dry_run' => true,
                'message' => '[DRY-RUN] Command execution simulated — not run.',
            ]));
        }

        // Auto-backup files that bash is about to overwrite
        $this->backupTargetFiles($command, $workingDir);

        // Check for blocked commands
        foreach ($this->blockedCommands as $blocked) {
            if (stripos($command, $blocked) !== false) {
                return ToolResult::error("Command blocked for safety: contains '{$blocked}'");
            }
        }

        // Check allowed commands if whitelist is defined
        if (!empty($this->allowedCommands)) {
            $allowed = false;
            foreach ($this->allowedCommands as $allowedCmd) {
                if (strpos($command, $allowedCmd) === 0) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return ToolResult::error('Command not in allowed list');
            }
        }

        try {
            $result = $this->executeCommand($command, $workingDir, $timeout, $env);

            return ToolResult::success(json_encode([
                'stdout' => $this->truncateOutput($result['stdout']),
                'stderr' => $this->truncateOutput($result['stderr'], 3000),
                'exit_code' => $result['exit_code'],
                'command' => $command,
                'working_directory' => $workingDir,
                'success' => $result['exit_code'] === 0,
            ]));
        } catch (\Throwable $e) {
            return ToolResult::error("Command execution failed: " . $e->getMessage());
        }
    }

    private function executeCommand(string $command, string $workingDir, int $timeout, array $env): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        // Merge environment variables
        $envVars = array_merge($_ENV, $env);

        // Validate working directory
        if (!is_dir($workingDir)) {
            throw new \RuntimeException("Working directory does not exist: {$workingDir}");
        }

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            $workingDir,
            $envVars
        );

        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start process");
        }

        // Close stdin
        fclose($pipes[0]);

        // Set up non-blocking reads
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            // Read available output
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            // Check if process has finished
            if (!$status['running']) {
                break;
            }

            // Check for timeout
            if (time() - $startTime > $timeout) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new \RuntimeException("Command timed out after {$timeout} seconds");
            }

            usleep(10000); // 10ms sleep to prevent busy waiting
        }

        // Final read
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'exit_code' => $status['exitcode'] ?? $exitCode,
        ];
    }

    /**
     * Return an escalating error message for consecutive empty commands.
     * Progressively stronger language nudges the model to change approach.
     */
    private function getEmptyCommandMessage(): string
    {
        if ($this->consecutiveEmptyCount >= 3) {
            return "CRITICAL: {$this->consecutiveEmptyCount} consecutive empty commands detected. " .
                "You are stuck in a loop. STOP and reassess your approach immediately. " .
                "Options: (1) Provide a complete, specific bash command. " .
                "(2) Use write_file tool instead of bash for creating files. " .
                "(3) If the task is complete, provide your final summary without calling more tools.";
        }
        if ($this->consecutiveEmptyCount >= 2) {
            return "WARNING: Second consecutive empty command. Provide a complete, valid bash command. " .
                "If you are trying to create a large file, use the write_file tool or break it into smaller commands.";
        }
        return "Command cannot be empty. Please provide a valid bash command to execute.";
    }

    /**
     * Truncate large outputs to prevent context window explosion.
     * Keeps first and last portions so the model sees the beginning and end.
     */
    private function truncateOutput(string $output, int $maxChars = 0): string
    {
        $max = $maxChars > 0 ? $maxChars : $this->maxOutputChars;
        if (strlen($output) <= $max) {
            return $output;
        }

        $halfMax = (int) ($max / 2);
        $totalLines = substr_count($output, "\n") + 1;

        return substr($output, 0, $halfMax) .
            "\n\n... [output truncated: ~{$totalLines} lines total, showing first and last portions] ...\n\n" .
            substr($output, -$halfMax);
    }

    /**
     * Detect bash write patterns and backup any existing target files before
     * the command overwrites them.
     *
     * Patterns detected:
     *   cat > /path, cat >> /path, echo ... > /path, echo ... >> /path
     *   tee /path, tee -a /path, sed -i ... /path, cp /src /dest
     *   heredoc: command > /path << 'EOF', cat > /path << EOF
     */
    private function backupTargetFiles(string $command, string $workingDir): void
    {
        if ($this->backupManager === null) {
            return;
        }

        $targets = $this->extractWriteTargets($command, $workingDir);

        foreach ($targets as $path) {
            if (!is_file($path)) {
                continue;
            }

            $this->backupManager->backup($path);

            if ($this->rollbackManager !== null && $this->sessionId !== null) {
                try {
                    $this->rollbackManager->createSnapshot($this->sessionId, [$path]);
                } catch (\Throwable) {
                    // Non-fatal
                }
            }
        }
    }

    /**
     * Extract file paths that a bash command will overwrite.
     *
     * @return string[] Resolved absolute file paths
     */
    private function extractWriteTargets(string $command, string $workingDir): array
    {
        $targets = [];

        // Patterns that write to a file path:
        // 1. Redirect: anything > /path or >> /path  (excluding variable assignments like FOO=bar)
        // 2. tee [options] /path
        // 3. sed -i [options] /path (last non-flag argument)
        // 4. cp /src /dest  (last argument)

        // Redirect: > path or >> path — capture the path token after > or >>
        if (preg_match_all('/(?<![=<>])[>]{1,2}\s*([^\s|&;><]+)/', $command, $m)) {
            foreach ($m[1] as $raw) {
                $resolved = $this->resolvePath($raw, $workingDir);
                if ($resolved !== null) {
                    $targets[] = $resolved;
                }
            }
        }

        // tee: `tee [-a] path`
        if (preg_match('/\btee\s+(?:-a\s+)?([^\s|&;><]+)/', $command, $m)) {
            $resolved = $this->resolvePath($m[1], $workingDir);
            if ($resolved !== null) {
                $targets[] = $resolved;
            }
        }

        // sed -i: last non-option token is the file
        if (preg_match('/\bsed\s+.*?-i\b.*?\s+([^\s\'"]+)\s*$/', $command, $m)) {
            $resolved = $this->resolvePath($m[1], $workingDir);
            if ($resolved !== null) {
                $targets[] = $resolved;
            }
        }

        // cp src dest: last two path-looking tokens
        if (preg_match('/\bcp\s+(?:-[^\s]+\s+)*([^\s]+)\s+([^\s]+)\s*$/', $command, $m)) {
            $resolved = $this->resolvePath($m[2], $workingDir);
            if ($resolved !== null) {
                $targets[] = $resolved;
            }
        }

        // mv src dest: destination may be overwritten
        if (preg_match('/\bmv\s+(?:-[^\s]+\s+)*([^\s]+)\s+([^\s]+)\s*$/', $command, $m)) {
            $resolved = $this->resolvePath($m[2], $workingDir);
            if ($resolved !== null) {
                $targets[] = $resolved;
            }
        }

        return array_unique($targets);
    }

    /**
     * Resolve a raw path token (may contain ~, be relative, etc.) to an absolute path.
     * Returns null if the path looks like a special file, pipe, or device.
     */
    private function resolvePath(string $raw, string $workingDir): ?string
    {
        // Strip quotes
        $raw = trim($raw, '"\'');

        // Skip obvious non-paths
        if ($raw === '' || str_starts_with($raw, '/dev/') || str_starts_with($raw, '/proc/')) {
            return null;
        }

        // Expand ~
        if (str_starts_with($raw, '~/') || $raw === '~') {
            $home = getenv('HOME') ?: '/tmp';
            $raw = $home . substr($raw, 1);
        }

        // Make relative paths absolute
        if (!str_starts_with($raw, '/')) {
            $raw = rtrim($workingDir, '/') . '/' . $raw;
        }

        // Resolve .. etc without requiring the path to exist yet
        return $raw;
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
