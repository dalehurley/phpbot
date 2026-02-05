<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Registry\PersistentToolRegistry;

class ToolBuilderTool implements ToolInterface
{
    private PersistentToolRegistry $registry;

    public function __construct(PersistentToolRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getName(): string
    {
        return 'tool_builder';
    }

    public function getDescription(): string
    {
        return <<<DESC
Create a new reusable tool that will be saved and available in future sessions. 
Use this when you identify a task that would benefit from automation or when you find yourself doing the same operation multiple times.
The tool will be persisted and can be used by future agents.
The handler should be a PHP closure that takes an array of input parameters and returns a string or array result.
Prefer PHP-only implementations; only add composer dependencies or shell commands when necessary.
If dependencies are needed, include them so they can be installed, and provide test inputs to verify the tool works.
Tool creation will install requested composer dependencies and run the provided tests before persisting the tool.
DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Unique name for the tool (snake_case, e.g., "fetch_github_issues")'
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Clear description of what the tool does and when to use it'
                ],
                'parameters' => [
                    'type' => 'array',
                    'description' => 'Array of parameter definitions',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Parameter name'
                            ],
                            'type' => [
                                'type' => 'string',
                                'description' => 'Parameter type: string, integer, number, boolean, array, object',
                                'enum' => ['string', 'integer', 'number', 'boolean', 'array', 'object']
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Description of the parameter'
                            ],
                            'required' => [
                                'type' => 'boolean',
                                'description' => 'Whether the parameter is required',
                                'default' => false
                            ],
                            'default' => [
                                'description' => 'Default value for optional parameters'
                            ]
                        ],
                        'required' => ['name', 'type', 'description']
                    ]
                ],
                'handler_code' => [
                    'type' => 'string',
                    'description' => <<<CODE
PHP code for the tool handler. This should be the body of a function that:
- Receives \$input array with all parameters
- Returns a string or array with the result
- Can use any PHP functions and the bash() helper function for shell commands
- Should handle errors gracefully
Prefer PHP-only logic; use bash() only if required.
The bash() helper returns a string and prefixes errors with "ERROR:".

Example:
\$url = \$input['url'];
\$response = file_get_contents(\$url);
if (\$response === false) {
    return ['error' => 'Failed to fetch URL'];
}
return ['content' => \$response, 'length' => strlen(\$response)];
CODE
                ],
                'composer_dependencies' => [
                    'type' => 'array',
                    'description' => 'Composer packages to install (e.g., "guzzlehttp/guzzle:^7.8")',
                    'items' => [
                        'type' => 'string'
                    ]
                ],
                'test_inputs' => [
                    'type' => 'array',
                    'description' => 'Array of input objects to test the tool after creation',
                    'items' => [
                        'type' => 'object'
                    ]
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Category for organization: general, file_ops, network, data, automation',
                    'enum' => ['general', 'file_ops', 'network', 'data', 'automation'],
                    'default' => 'general'
                ]
            ],
            'required' => ['name', 'description', 'parameters', 'handler_code'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $name = $input['name'] ?? '';
        $description = $input['description'] ?? '';
        $parameters = $input['parameters'] ?? [];
        $handlerCode = $input['handler_code'] ?? '';
        $category = $input['category'] ?? 'general';
        $composerDependencies = $input['composer_dependencies'] ?? [];
        $testInputs = $input['test_inputs'] ?? [];

        // Validate name
        if (empty($name) || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            return ToolResult::error('Invalid tool name. Must be snake_case starting with a letter.');
        }

        // Check if tool already exists
        if ($this->registry->has($name)) {
            return ToolResult::error("Tool '{$name}' already exists. Choose a different name.");
        }

        // Validate parameters
        if (!is_array($parameters)) {
            return ToolResult::error('Parameters must be an array.');
        }

        if (!is_array($composerDependencies)) {
            return ToolResult::error('composer_dependencies must be an array of package strings.');
        }

        foreach ($composerDependencies as $dependency) {
            if (!is_string($dependency) || trim($dependency) === '') {
                return ToolResult::error('composer_dependencies must contain only non-empty strings.');
            }
        }

        if (!is_array($testInputs)) {
            return ToolResult::error('test_inputs must be an array of input objects.');
        }

        // Validate handler code (basic security checks)
        $blockedPatterns = [
            '/\beval\s*\(/i',
            '/\bexec\s*\(/i',
            '/\bsystem\s*\(/i',
            '/\bpassthru\s*\(/i',
            '/\bshell_exec\s*\(/i',
            '/\bproc_open\s*\(/i',
            '/`[^`]+`/',  // Backtick execution
            '/\$_GET/',
            '/\$_POST/',
            '/\$_REQUEST/',
        ];

        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $handlerCode)) {
                return ToolResult::error('Handler code contains blocked functions. Use the bash() helper for shell commands.');
            }
        }

        try {
            // Create the dynamic tool
            $tool = new DynamicTool(
                name: $name,
                description: $description,
                parameters: $parameters,
                handlerCode: $handlerCode,
                category: $category
            );

            // Test that the handler code is valid
            $tool->validateHandler();

            // Install composer dependencies if provided
            if (!empty($composerDependencies)) {
                $escapedDeps = array_map(fn($dep) => escapeshellarg($dep), $composerDependencies);
                $requireCommand = 'composer require --no-interaction --no-progress ' . implode(' ', $escapedDeps);
                $requireResult = $this->runCommand($requireCommand);
                if ($requireResult['exit_code'] !== 0) {
                    return ToolResult::error("Composer require failed: {$requireResult['stderr']}");
                }

                $installResult = $this->runCommand('composer install --no-interaction --no-progress');
                if ($installResult['exit_code'] !== 0) {
                    return ToolResult::error("Composer install failed: {$installResult['stderr']}");
                }
            }

            // Test the tool with provided inputs (or a smoke test if possible)
            $requiredParams = array_filter(
                $parameters,
                fn($param) => !empty($param['required']) && !array_key_exists('default', $param)
            );

            if (empty($testInputs) && !empty($requiredParams)) {
                return ToolResult::error('test_inputs is required when the tool has required parameters.');
            }

            if (empty($testInputs)) {
                $testInputs = [[]];
            }

            foreach ($testInputs as $index => $testInput) {
                if (!is_array($testInput)) {
                    return ToolResult::error("test_inputs[{$index}] must be an object.");
                }

                $result = $tool->execute($testInput);
                $content = $result->getContent();
                if ($result->isError()) {
                    return ToolResult::error("Tool test failed for test_inputs[{$index}]: " . $content);
                }

                if (is_string($content) && str_starts_with($content, 'ERROR:')) {
                    return ToolResult::error("Tool test failed for test_inputs[{$index}]: " . $content);
                }

                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && array_key_exists('error', $decoded)) {
                    return ToolResult::error("Tool test failed for test_inputs[{$index}]: " . $content);
                }
            }

            // Register and persist the tool
            $this->registry->registerCustomTool($tool);

            return ToolResult::success(json_encode([
                'success' => true,
                'message' => "Tool '{$name}' created and registered successfully",
                'tool' => [
                    'name' => $name,
                    'description' => $description,
                    'category' => $category,
                    'parameters' => array_map(fn($p) => $p['name'], $parameters),
                    'composer_dependencies' => array_values($composerDependencies),
                    'test_inputs' => array_values($testInputs),
                ],
            ]));
        } catch (\Throwable $e) {
            return ToolResult::error("Failed to create tool: " . $e->getMessage());
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

    private function runCommand(string $command, int $timeout = 300): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, getcwd());

        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Failed to execute command', 'exit_code' => -1];
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
                return ['stdout' => trim($stdout), 'stderr' => 'Command timed out', 'exit_code' => -1];
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
            'exit_code' => $status['exitcode'] ?? $exitCode,
        ];
    }
}
