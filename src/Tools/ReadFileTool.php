<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;

class ReadFileTool implements ToolInterface
{
    public function getName(): string
    {
        return 'read_file';
    }

    public function getDescription(): string
    {
        return 'Read a file from disk and return its contents. Use for small to medium files.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the file to read',
                ],
                'max_bytes' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of bytes to read',
                    'default' => 200000,
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $path = (string) ($input['path'] ?? '');
        $maxBytes = (int) ($input['max_bytes'] ?? 200000);

        if ($path === '') {
            return ToolResult::error('Path is required.');
        }

        if (!is_file($path)) {
            return ToolResult::error("File not found: {$path}");
        }

        if ($maxBytes <= 0) {
            return ToolResult::error('max_bytes must be greater than 0.');
        }

        $size = filesize($path);
        $bytesToRead = $size !== false ? min($size, $maxBytes) : $maxBytes;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ToolResult::error("Failed to open file: {$path}");
        }

        $content = fread($handle, $bytesToRead);
        fclose($handle);

        if ($content === false) {
            return ToolResult::error("Failed to read file: {$path}");
        }

        return ToolResult::success(json_encode([
            'path' => $path,
            'bytes_read' => strlen($content),
            'truncated' => $size !== false ? $size > $maxBytes : false,
            'content' => $content,
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
