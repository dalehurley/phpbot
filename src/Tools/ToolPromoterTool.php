<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Registry\PersistentToolRegistry;

class ToolPromoterTool implements ToolInterface
{
    private PersistentToolRegistry $registry;

    public function __construct(PersistentToolRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getName(): string
    {
        return 'tool_promoter';
    }

    public function getDescription(): string
    {
        return <<<DESC
Promote a persisted JSON tool into a first-class PHP tool class.
Use this when a dynamic tool has stabilized and should be versioned, linted, and reviewed like normal code.
DESC;
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Name of the existing custom tool to promote',
                ],
                'class_name' => [
                    'type' => 'string',
                    'description' => 'Optional PHP class name for the promoted tool (e.g., ReadWordFileTool)',
                ],
                'namespace' => [
                    'type' => 'string',
                    'description' => 'Optional namespace for the promoted tool class',
                    'default' => 'Dalehurley\\Phpbot\\Tools\\Promoted',
                ],
                'destination_dir' => [
                    'type' => 'string',
                    'description' => 'Optional destination directory for the new PHP file',
                    'default' => 'src/Tools/Promoted',
                ],
                'keep_json' => [
                    'type' => 'boolean',
                    'description' => 'Whether to keep the original JSON tool file',
                    'default' => true,
                ],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $name = trim((string) ($input['name'] ?? ''));
        $className = trim((string) ($input['class_name'] ?? ''));
        $namespace = trim((string) ($input['namespace'] ?? 'Dalehurley\\Phpbot\\Tools\\Promoted'));
        $destinationDir = trim((string) ($input['destination_dir'] ?? 'src/Tools/Promoted'));
        $keepJson = (bool) ($input['keep_json'] ?? true);

        if ($name === '') {
            return ToolResult::error('Tool name is required.');
        }

        if (!$this->registry->has($name)) {
            return ToolResult::error("Tool '{$name}' not found.");
        }

        $tool = $this->registry->get($name);
        if (!$tool instanceof DynamicTool) {
            return ToolResult::error("Tool '{$name}' is not a dynamic tool and cannot be promoted.");
        }

        if ($className === '') {
            $className = $this->toClassName($name);
        }

        if (!preg_match('/^[A-Z][A-Za-z0-9_]*$/', $className)) {
            return ToolResult::error('Invalid class_name. Must be a valid PHP class name.');
        }

        if ($namespace === '') {
            return ToolResult::error('Namespace cannot be empty.');
        }

        $baseDir = dirname(__DIR__, 2);
        $destinationDir = $this->normalizePath($destinationDir, $baseDir);
        if (!is_dir($destinationDir)) {
            if (!mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
                return ToolResult::error("Failed to create destination directory: {$destinationDir}");
            }
        }

        $filePath = $destinationDir . '/' . $className . '.php';
        if (file_exists($filePath)) {
            return ToolResult::error("File already exists: {$filePath}");
        }

        $code = $this->generateClassCode($tool, $className, $namespace);

        if (file_put_contents($filePath, $code) === false) {
            return ToolResult::error("Failed to write file: {$filePath}");
        }

        if (!$keepJson) {
            $this->registry->removeTool($name);
        }

        require_once $filePath;
        $fqcn = $namespace . '\\' . $className;

        if (!class_exists($fqcn)) {
            return ToolResult::error("Generated class not found: {$fqcn}");
        }

        $this->registry->register(new $fqcn());

        return ToolResult::success(json_encode([
            'success' => true,
            'message' => "Tool '{$name}' promoted to PHP class {$fqcn}",
            'file' => $filePath,
            'class' => $fqcn,
            'kept_json' => $keepJson,
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

    private function toClassName(string $name): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $name);
        $parts = array_filter($parts, fn($part) => $part !== '');
        $parts = array_map(fn($part) => ucfirst(strtolower($part)), $parts);

        return implode('', $parts) . 'Tool';
    }

    private function normalizePath(string $path, string $baseDir): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        return rtrim($baseDir . '/' . trim($path, '/'), '/');
    }

    private function generateClassCode(DynamicTool $tool, string $className, string $namespace): string
    {
        $description = var_export($tool->getDescription(), true);
        $name = var_export($tool->getName(), true);
        $category = var_export($tool->getCategory(), true);
        $parameters = var_export($tool->getParameters(), true);
        $handlerCode = $tool->getHandlerCode();

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use ClaudeAgents\Contracts\\ToolInterface;
use ClaudeAgents\Contracts\\ToolResultInterface;
use ClaudeAgents\\Tools\\ToolResult;

if (!function_exists('bash')) {
    function bash(string \$command, int \$timeout = 60): string {
        \$descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        \$process = proc_open(\$command, \$descriptors, \$pipes, getcwd());
        
        if (!is_resource(\$process)) {
            return 'ERROR: Failed to execute command';
        }

        fclose(\$pipes[0]);
        
        stream_set_blocking(\$pipes[1], false);
        stream_set_blocking(\$pipes[2], false);

        \$stdout = '';
        \$stderr = '';
        \$startTime = time();

        while (true) {
            \$status = proc_get_status(\$process);
            \$stdout .= stream_get_contents(\$pipes[1]);
            \$stderr .= stream_get_contents(\$pipes[2]);

            if (!\$status['running']) {
                break;
            }

            if (time() - \$startTime > \$timeout) {
                proc_terminate(\$process, 9);
                fclose(\$pipes[1]);
                fclose(\$pipes[2]);
                proc_close(\$process);
                return 'ERROR: Command timed out';
            }

            usleep(10000);
        }

        \$stdout .= stream_get_contents(\$pipes[1]);
        \$stderr .= stream_get_contents(\$pipes[2]);

        fclose(\$pipes[1]);
        fclose(\$pipes[2]);
        \$exitCode = proc_close(\$process);

        \$stdout = trim(\$stdout);
        \$stderr = trim(\$stderr);
        \$exitCode = \$status['exitcode'] ?? \$exitCode;

        if (\$exitCode !== 0 && \$stderr !== '') {
            return "ERROR: {\$stderr}";
        }

        if (\$exitCode !== 0 && \$stdout !== '') {
            return "ERROR: {\$stdout}";
        }

        return \$stdout;
    }
}

class {$className} implements ToolInterface
{
    private array \$parameters = {$parameters};

    public function getName(): string
    {
        return {$name};
    }

    public function getDescription(): string
    {
        return {$description};
    }

    public function getCategory(): string
    {
        return {$category};
    }

    public function getInputSchema(): array
    {
        \$properties = [];
        \$required = [];

        foreach (\$this->parameters as \$param) {
            \$prop = [
                'type' => \$param['type'],
                'description' => \$param['description'],
            ];

            if (isset(\$param['default'])) {
                \$prop['default'] = \$param['default'];
            }

            if (isset(\$param['enum'])) {
                \$prop['enum'] = \$param['enum'];
            }

            \$properties[\$param['name']] = \$prop;

            if (!empty(\$param['required'])) {
                \$required[] = \$param['name'];
            }
        }

        return [
            'type' => 'object',
            'properties' => \$properties,
            'required' => \$required,
        ];
    }

    public function execute(array \$input): ToolResultInterface
    {
        try {
            foreach (\$this->parameters as \$param) {
                if (!isset(\$input[\$param['name']]) && isset(\$param['default'])) {
                    \$input[\$param['name']] = \$param['default'];
                }
            }

            \$handler = function(array \$input) {
                {$handlerCode}
            };

            \$result = \$handler(\$input);

            if (is_array(\$result)) {
                return ToolResult::success(json_encode(\$result));
            }

            return ToolResult::success((string) \$result);
        } catch (\\Throwable \$e) {
            return ToolResult::error("Tool execution failed: " . \$e->getMessage());
        }
    }

    public function toDefinition(): array
    {
        return [
            'name' => \$this->getName(),
            'description' => \$this->getDescription(),
            'input_schema' => \$this->getInputSchema(),
        ];
    }
}
PHP;
    }
}
