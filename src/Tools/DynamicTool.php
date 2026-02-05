<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;

class DynamicTool implements ToolInterface
{
    private string $name;
    private string $description;
    private array $parameters;
    private string $handlerCode;
    private string $category;
    private ?\Closure $handler = null;

    public function __construct(
        string $name,
        string $description,
        array $parameters,
        string $handlerCode,
        string $category = 'general'
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->parameters = $parameters;
        $this->handlerCode = $handlerCode;
        $this->category = $category;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getHandlerCode(): string
    {
        return $this->handlerCode;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getInputSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters as $param) {
            $prop = [
                'type' => $param['type'],
                'description' => $param['description'],
            ];

            if (isset($param['default'])) {
                $prop['default'] = $param['default'];
            }

            if (isset($param['enum'])) {
                $prop['enum'] = $param['enum'];
            }

            $properties[$param['name']] = $prop;

            if (!empty($param['required'])) {
                $required[] = $param['name'];
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    public function validateHandler(): void
    {
        // Try to create the closure to validate syntax
        $this->getHandler();
    }

    private function getHandler(): \Closure
    {
        if ($this->handler === null) {
            $bashHelper = <<<'PHP'
if (!function_exists('bash')) {
    function bash(string $command, int $timeout = 60): string {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, getcwd());
        
        if (!is_resource($process)) {
            return 'ERROR: Failed to execute command';
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
                return 'ERROR: Command timed out';
            }

            usleep(10000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $stdout = trim($stdout);
        $stderr = trim($stderr);
        $exitCode = $status['exitcode'] ?? $exitCode;

        if ($exitCode !== 0 && $stderr !== '') {
            return "ERROR: {$stderr}";
        }

        if ($exitCode !== 0 && $stdout !== '') {
            return "ERROR: {$stdout}";
        }

        return $stdout;
    }
}
PHP;

            // Create the handler closure
            $code = $bashHelper . "\n" . <<<PHP
return function(array \$input) {
    {$this->handlerCode}
};
PHP;

            try {
                $this->handler = eval($code);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Invalid handler code: " . $e->getMessage());
            }

            if (!$this->handler instanceof \Closure) {
                throw new \RuntimeException("Handler code must return a valid result");
            }
        }

        return $this->handler;
    }

    public function execute(array $input): ToolResultInterface
    {
        try {
            // Apply default values for missing optional parameters
            foreach ($this->parameters as $param) {
                if (!isset($input[$param['name']]) && isset($param['default'])) {
                    $input[$param['name']] = $param['default'];
                }
            }

            $handler = $this->getHandler();
            $result = $handler($input);

            if (is_array($result)) {
                return ToolResult::success(json_encode($result));
            }

            return ToolResult::success((string) $result);
        } catch (\Throwable $e) {
            return ToolResult::error("Tool execution failed: " . $e->getMessage());
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

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->parameters,
            'handler_code' => $this->handlerCode,
            'category' => $this->category,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            description: $data['description'],
            parameters: $data['parameters'],
            handlerCode: $data['handler_code'],
            category: $data['category'] ?? 'general'
        );
    }
}
