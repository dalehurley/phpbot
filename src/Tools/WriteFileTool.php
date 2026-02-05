<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;

class WriteFileTool implements ToolInterface
{
    public function getName(): string
    {
        return 'write_file';
    }

    public function getDescription(): string
    {
        return 'Write content to a file on disk. Use for creating or overwriting files.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the file to write',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Content to write to the file',
                ],
                'append' => [
                    'type' => 'boolean',
                    'description' => 'Append to the file instead of overwriting',
                    'default' => false,
                ],
                'create_dirs' => [
                    'type' => 'boolean',
                    'description' => 'Create parent directories if they do not exist',
                    'default' => true,
                ],
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $path = (string) ($input['path'] ?? '');
        $content = (string) ($input['content'] ?? '');
        $append = (bool) ($input['append'] ?? false);
        $createDirs = (bool) ($input['create_dirs'] ?? true);

        if ($path === '') {
            return ToolResult::error('Path is required.');
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!$createDirs) {
                return ToolResult::error("Directory does not exist: {$dir}");
            }
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return ToolResult::error("Failed to create directory: {$dir}");
            }
        }

        $flags = $append ? FILE_APPEND : 0;
        $result = file_put_contents($path, $content, $flags);

        if ($result === false) {
            return ToolResult::error("Failed to write file: {$path}");
        }

        return ToolResult::success(json_encode([
            'path' => $path,
            'bytes_written' => $result,
            'append' => $append,
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
