<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;

class SkillScriptTool implements ToolInterface
{
    private string $name;
    private string $description;
    private string $scriptPath;
    private string $interpreter;

    public function __construct(string $name, string $description, string $scriptPath, string $interpreter)
    {
        $this->name = $name;
        $this->description = $description;
        $this->scriptPath = $scriptPath;
        $this->interpreter = $interpreter;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'args' => [
                    'type' => 'array',
                    'description' => 'Arguments to pass to the script',
                    'items' => ['type' => 'string'],
                    'default' => [],
                ],
                'working_directory' => [
                    'type' => 'string',
                    'description' => 'Optional working directory for the script',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Timeout in seconds',
                    'default' => 120,
                ],
                'env' => [
                    'type' => 'object',
                    'description' => 'Optional environment variables',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        if (!is_file($this->scriptPath) || !is_readable($this->scriptPath)) {
            return ToolResult::error("Script not found or not readable: {$this->scriptPath}");
        }

        $args = $input['args'] ?? [];
        if (!is_array($args)) {
            return ToolResult::error('args must be an array of strings.');
        }

        $workingDir = $input['working_directory'] ?? dirname($this->scriptPath);
        $timeout = (int) ($input['timeout'] ?? 120);
        $env = $input['env'] ?? [];

        $escapedArgs = array_map(fn($arg) => escapeshellarg((string) $arg), $args);
        $command = trim($this->interpreter . ' ' . escapeshellarg($this->scriptPath) . ' ' . implode(' ', $escapedArgs));

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

    public function toDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'input_schema' => $this->getInputSchema(),
        ];
    }

    private function executeCommand(string $command, string $workingDir, int $timeout, array $env): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envVars = array_merge($_ENV, $env);

        if (!is_dir($workingDir)) {
            throw new \RuntimeException("Working directory does not exist: {$workingDir}");
        }

        $process = proc_open($command, $descriptors, $pipes, $workingDir, $envVars);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start process');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if (time() - $startTime > $timeout) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new \RuntimeException("Command timed out after {$timeout} seconds");
            }

            usleep(10000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'exit_code' => $exitCode,
        ];
    }
}
