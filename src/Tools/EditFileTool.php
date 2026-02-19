<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\DryRun\DryRunContext;
use Dalehurley\Phpbot\Storage\BackupManager;
use Dalehurley\Phpbot\Storage\RollbackManager;

class EditFileTool implements ToolInterface
{
    private ?BackupManager $backupManager;
    private ?RollbackManager $rollbackManager;
    private ?string $sessionId;

    public function __construct(
        ?BackupManager $backupManager = null,
        ?RollbackManager $rollbackManager = null,
        ?string $sessionId = null,
    ) {
        $this->backupManager = $backupManager;
        $this->rollbackManager = $rollbackManager;
        $this->sessionId = $sessionId;
    }

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

        // Dry-run: simulate without writing
        if (DryRunContext::isActive()) {
            DryRunContext::record('edit_file', 'Edit file', [
                'path' => $path,
                'search' => mb_substr($search, 0, 60),
                'replace' => mb_substr($replace, 0, 60),
                'occurrences' => $count,
            ]);
            return ToolResult::success(json_encode([
                'path' => $path,
                'replacements' => $count,
                'dry_run' => true,
                'message' => '[DRY-RUN] File edit simulated — no changes made.',
            ]));
        }

        // Snapshot in rollback manager (first edit of this file per session)
        if ($this->rollbackManager !== null && $this->sessionId !== null) {
            try {
                $this->rollbackManager->createSnapshot($this->sessionId, [$path]);
            } catch (\Throwable) {
                // Non-fatal: continue even if snapshot fails
            }
        }

        // Auto-backup before writing
        if ($this->backupManager !== null) {
            $this->backupManager->backup($path);
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
