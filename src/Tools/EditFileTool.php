<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;

class EditFileTool implements ToolInterface
{
    public function getName(): string
    {
        return 'edit_file';
    }

    public function getDescription(): string
    {
        return 'Edit a file by replacing a string or all occurrences with another string.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Path to the file to edit',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Exact string to search for',
                ],
                'replace' => [
                    'type' => 'string',
                    'description' => 'Replacement string',
                ],
                'replace_all' => [
                    'type' => 'boolean',
                    'description' => 'Replace all occurrences instead of the first',
                    'default' => true,
                ],
            ],
            'required' => ['path', 'search', 'replace'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $path = (string) ($input['path'] ?? '');
        $search = (string) ($input['search'] ?? '');
        $replace = (string) ($input['replace'] ?? '');
        $replaceAll = (bool) ($input['replace_all'] ?? true);

        if ($path === '') {
            return ToolResult::error('Path is required.');
        }

        if ($search === '') {
            return ToolResult::error('Search string cannot be empty.');
        }

        if (!is_file($path)) {
            return ToolResult::error("File not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return ToolResult::error("Failed to read file: {$path}");
        }

        $count = 0;
        if ($replaceAll) {
            $newContent = str_replace($search, $replace, $content, $count);
        } else {
            $pos = strpos($content, $search);
            if ($pos === false) {
                $newContent = $content;
                $count = 0;
            } else {
                $newContent = substr($content, 0, $pos) . $replace . substr($content, $pos + strlen($search));
                $count = 1;
            }
        }

        if ($count === 0) {
            return ToolResult::error('Search string not found in file.');
        }

        $result = file_put_contents($path, $newContent);
        if ($result === false) {
            return ToolResult::error("Failed to write file: {$path}");
        }

        return ToolResult::success(json_encode([
            'path' => $path,
            'replacements' => $count,
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
