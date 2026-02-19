<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Storage\BackupManager;

class RestoreFileTool implements ToolInterface
{
    public function __construct(private BackupManager $backupManager) {}

    public function getName(): string
    {
        return 'restore_file';
    }

    public function getDescription(): string
    {
        return 'Restore a file from a previous automatic backup. '
            . 'Use "list" action to see available backup versions, '
            . 'or "restore" action to revert to a specific version.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'restore'],
                    'description' => '"list" to see all backups for a file, "restore" to revert to a backup',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Absolute path to the original file',
                ],
                'version' => [
                    'type' => 'integer',
                    'description' => 'Backup version number to restore (omit to restore the most recent backup)',
                ],
            ],
            'required' => ['action', 'path'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $action = (string) ($input['action'] ?? '');
        $path = (string) ($input['path'] ?? '');
        $version = isset($input['version']) ? (int) $input['version'] : null;

        if ($path === '') {
            return ToolResult::error('Path is required.');
        }

        return match ($action) {
            'list' => $this->listBackups($path),
            'restore' => $this->restoreFile($path, $version),
            default => ToolResult::error("Unknown action: {$action}. Use 'list' or 'restore'."),
        };
    }

    private function listBackups(string $path): ToolResultInterface
    {
        $backups = $this->backupManager->listBackups($path);

        if (empty($backups)) {
            return ToolResult::success(json_encode([
                'file' => $path,
                'backups' => [],
                'message' => 'No backups found for this file.',
            ]));
        }

        $formatted = array_map(fn($b) => [
            'version' => $b['version'],
            'date' => $b['date'],
            'backup_path' => $b['path'],
            'size_bytes' => $b['size'],
            'modified' => date('Y-m-d H:i:s', $b['modified']),
        ], $backups);

        return ToolResult::success(json_encode([
            'file' => $path,
            'backup_count' => count($formatted),
            'backups' => $formatted,
        ]));
    }

    private function restoreFile(string $path, ?int $version): ToolResultInterface
    {
        try {
            $restoredFrom = $this->backupManager->restore($path, $version);

            return ToolResult::success(json_encode([
                'restored_file' => $path,
                'restored_from' => $restoredFrom,
                'version' => $version ?? 'latest',
                'success' => true,
            ]));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage());
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
}
