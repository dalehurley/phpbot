<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\DryRun\DryRunContext;
use Dalehurley\Phpbot\Storage\BackupManager;

class WriteFileTool implements ToolInterface
{
    private string $storagePath;

    /** @var array<string> Paths of files created during this session */
    private array $createdFiles = [];

    private ?BackupManager $backupManager;

    public function __construct(string $storagePath = '', ?BackupManager $backupManager = null)
    {
        $this->storagePath = $storagePath;
        $this->backupManager = $backupManager;
    }

    public function getName(): string
    {
        return 'write_file';
    }

    public function getDescription(): string
    {
        return 'Write content to a file. Files are saved to the storage folder automatically. '
            . 'Just provide a filename (e.g. "report.md") or a relative path (e.g. "reports/summary.md"). '
            . 'The full storage path will be returned so the user can access the file.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Filename or relative path for the file (e.g. "report.md", "output/data.csv"). '
                        . 'Files are saved to the storage directory automatically.',
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
            ],
            'required' => ['path', 'content'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $path = (string) ($input['path'] ?? '');
        $content = (string) ($input['content'] ?? '');
        $append = (bool) ($input['append'] ?? false);

        if ($path === '') {
            return ToolResult::error('Path is required.');
        }

        // Resolve the path to the storage directory
        $resolvedPath = $this->resolveStoragePath($path);

        // Dry-run: simulate without writing
        if (DryRunContext::isActive()) {
            DryRunContext::record('write_file', 'Write file', [
                'path' => $resolvedPath,
                'bytes' => strlen($content),
                'append' => $append ? 'yes' : 'no',
            ]);
            return ToolResult::success(json_encode([
                'path' => $resolvedPath,
                'bytes_written' => strlen($content),
                'append' => $append,
                'dry_run' => true,
                'message' => '[DRY-RUN] File write simulated — no changes made.',
            ]));
        }

        // Ensure parent directories exist
        $dir = dirname($resolvedPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return ToolResult::error("Failed to create directory: {$dir}");
            }
        }

        // Auto-backup existing file before overwriting
        if (!$append && $this->backupManager !== null) {
            $this->backupManager->backup($resolvedPath);
        }

        $flags = $append ? FILE_APPEND : 0;
        $result = file_put_contents($resolvedPath, $content, $flags);

        if ($result === false) {
            return ToolResult::error("Failed to write file: {$resolvedPath}");
        }

        // Track created files
        if (!in_array($resolvedPath, $this->createdFiles, true)) {
            $this->createdFiles[] = $resolvedPath;
        }

        return ToolResult::success(json_encode([
            'path' => $resolvedPath,
            'bytes_written' => $result,
            'append' => $append,
            'storage_location' => $resolvedPath,
        ]));
    }

    /**
     * Resolve a user-provided path to the storage directory.
     *
     * Extracts the filename (or last path components) and places them
     * under the configured storage path. If no storage path is set,
     * returns the original path unchanged.
     */
    private function resolveStoragePath(string $path): string
    {
        if ($this->storagePath === '') {
            return $path;
        }

        // If it's already inside the storage path, leave it as-is
        if (str_starts_with($path, $this->storagePath)) {
            return $path;
        }

        // Extract the meaningful part of the path
        // For absolute paths: take the basename
        // For relative paths: keep the relative structure
        if (str_starts_with($path, '/')) {
            $relativePart = basename($path);
        } else {
            $relativePart = ltrim($path, './');
        }

        return rtrim($this->storagePath, '/') . '/' . $relativePart;
    }

    /**
     * Get all file paths created during this session.
     *
     * @return array<string>
     */
    public function getCreatedFiles(): array
    {
        return $this->createdFiles;
    }

    /**
     * Reset the created files tracker (e.g. between runs).
     */
    public function resetCreatedFiles(): void
    {
        $this->createdFiles = [];
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
