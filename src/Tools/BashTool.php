<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;

class BashTool implements ToolInterface
{
    private array $config;
    private array $allowedCommands;
    private array $blockedCommands;
    private string $workingDirectory;
    private int $consecutiveEmptyCount = 0;
    private int $maxOutputChars;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->workingDirectory = $config['working_directory'] ?? getcwd();
        $this->maxOutputChars = (int) ($config['bash_max_output_chars'] ?? 15000);

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

    public function toDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'input_schema' => $this->getInputSchema(),
        ];
    }
}
