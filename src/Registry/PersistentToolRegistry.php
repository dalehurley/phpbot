<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Registry;

use ClaudeAgents\Contracts\ToolInterface;
use Dalehurley\Phpbot\Tools\DynamicTool;

class PersistentToolRegistry
{
    private array $tools = [];
    private array $customTools = [];
    private string $storagePath;

    public function __construct(string $storagePath)
    {
        $this->storagePath = $storagePath;
        $this->ensureStorageDirectory();
    }

    private function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function registerCustomTool(DynamicTool $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
        $this->customTools[$tool->getName()] = $tool;
        $this->persistTool($tool);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function all(): array
    {
        return array_values($this->tools);
    }

    public function getCustomTools(): array
    {
        return array_values($this->customTools);
    }

    public function execute(string $name, array $input): mixed
    {
        $tool = $this->get($name);

        if ($tool === null) {
            throw new \RuntimeException("Tool '{$name}' not found");
        }

        return $tool->execute($input);
    }

    private function persistTool(DynamicTool $tool): void
    {
        $filename = $this->storagePath . '/' . $tool->getName() . '.json';
        $data = json_encode($tool->toArray(), JSON_PRETTY_PRINT);
        file_put_contents($filename, $data);
    }

    public function loadPersistedTools(): void
    {
        if (!is_dir($this->storagePath)) {
            return;
        }

        $files = glob($this->storagePath . '/*.json');

        foreach ($files as $file) {
            try {
                $data = json_decode(file_get_contents($file), true);

                if (json_last_error() === JSON_ERROR_NONE && isset($data['name'])) {
                    $tool = DynamicTool::fromArray($data);
                    $this->tools[$tool->getName()] = $tool;
                    $this->customTools[$tool->getName()] = $tool;
                }
            } catch (\Throwable $e) {
                // Log error but continue loading other tools
                error_log("Failed to load tool from {$file}: " . $e->getMessage());
            }
        }
    }

    public function removeTool(string $name): bool
    {
        if (!isset($this->customTools[$name])) {
            return false; // Can only remove custom tools
        }

        unset($this->tools[$name]);
        unset($this->customTools[$name]);

        $filename = $this->storagePath . '/' . $name . '.json';
        if (file_exists($filename)) {
            unlink($filename);
        }

        return true;
    }

    public function listCustomTools(): array
    {
        $tools = [];

        foreach ($this->customTools as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'category' => $tool->getCategory(),
                'parameters' => array_map(fn($p) => [
                    'name' => $p['name'],
                    'type' => $p['type'],
                    'required' => $p['required'] ?? false,
                ], $tool->getParameters()),
            ];
        }

        return $tools;
    }

    public function getToolsByCategory(string $category): array
    {
        $tools = [];

        foreach ($this->customTools as $tool) {
            if ($tool->getCategory() === $category) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    public function exportTools(): string
    {
        $export = [];

        foreach ($this->customTools as $tool) {
            $export[] = $tool->toArray();
        }

        return json_encode($export, JSON_PRETTY_PRINT);
    }

    public function importTools(string $json): int
    {
        $tools = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException("Invalid JSON: " . json_last_error_msg());
        }

        $count = 0;

        foreach ($tools as $data) {
            if (isset($data['name']) && !$this->has($data['name'])) {
                $tool = DynamicTool::fromArray($data);
                $this->registerCustomTool($tool);
                $count++;
            }
        }

        return $count;
    }
}
