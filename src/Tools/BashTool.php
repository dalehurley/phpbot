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

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->workingDirectory = $config['working_directory'] ?? getcwd();

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

        // Validate command is not empty
        if (empty(trim($command))) {
            return ToolResult::error('Command cannot be empty');
        }

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
                'stdout' => $result['stdout'],
                'stderr' => $result['stderr'],
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

    public function toDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'input_schema' => $this->getInputSchema(),
        ];
    }
}
