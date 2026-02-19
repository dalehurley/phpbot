<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Storage;

/**
 * Manages automatic file backups before destructive write/edit operations.
 *
 * Backs up files to storage/backups/{date}/{basename}.{n} and keeps
 * only the last N versions per file (configurable, default 5).
 */
class BackupManager
{
    private string $backupRoot;
    private int $versionsToKeep;

    public function __construct(string $backupRoot = '', int $versionsToKeep = 5)
    {
        $this->backupRoot = $backupRoot !== ''
            ? rtrim($backupRoot, '/')
            : dirname(__DIR__, 2) . '/storage/backups';
        $this->versionsToKeep = max(1, $versionsToKeep);
    }

    /**
     * Backup a file before overwriting it.
     *
     * Returns the path of the backup file created, or null if the source
     * does not exist (no backup needed for new files).
     */
    public function backup(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $dateDir = $this->backupRoot . '/' . date('Y-m-d');
        if (!is_dir($dateDir) && !mkdir($dateDir, 0755, true) && !is_dir($dateDir)) {
            return null;
        }

        $basename = basename($path);
        $nextVersion = $this->nextVersion($dateDir, $basename);
        $backupPath = $dateDir . '/' . $basename . '.' . $nextVersion;

        if (@copy($path, $backupPath) === false) {
            return null;
        }

        // Prune old versions beyond the retention limit
        $this->pruneVersions($dateDir, $basename);

        return $backupPath;
    }

    /**
     * List all available backup versions for a given file path.
     *
     * Returns an array of ['version' => int, 'date' => string, 'path' => string, 'size' => int]
     * sorted from newest to oldest.
     *
     * @return array<array{version: int, date: string, path: string, size: int}>
     */
    public function listBackups(string $originalPath): array
    {
        $basename = basename($originalPath);
        $backups = [];

        if (!is_dir($this->backupRoot)) {
            return [];
        }

        $dateDirs = glob($this->backupRoot . '/????-??-??', GLOB_ONLYDIR) ?: [];
        rsort($dateDirs); // Newest date first

        foreach ($dateDirs as $dateDir) {
            $date = basename($dateDir);
            $versions = $this->findVersionsInDir($dateDir, $basename);
            rsort($versions); // Highest version number first

            foreach ($versions as $version) {
                $backupPath = $dateDir . '/' . $basename . '.' . $version;
                $backups[] = [
                    'version' => $version,
                    'date' => $date,
                    'path' => $backupPath,
                    'size' => (int) @filesize($backupPath),
                    'modified' => (int) @filemtime($backupPath),
                ];
            }
        }

        return $backups;
    }

    /**
     * Restore a specific backup version over the original file.
     *
     * If version is null, restores the most recent backup.
     *
     * @throws \RuntimeException when no backup is found or restore fails
     */
    public function restore(string $originalPath, ?int $version = null): string
    {
        $backups = $this->listBackups($originalPath);

        if (empty($backups)) {
            throw new \RuntimeException("No backups found for: {$originalPath}");
        }

        if ($version === null) {
            $backup = $backups[0]; // Most recent
        } else {
            $backup = null;
            foreach ($backups as $b) {
                if ($b['version'] === $version) {
                    $backup = $b;
                    break;
                }
            }

            if ($backup === null) {
                throw new \RuntimeException("Backup version {$version} not found for: {$originalPath}");
            }
        }

        $dir = dirname($originalPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }

        if (@copy($backup['path'], $originalPath) === false) {
            throw new \RuntimeException("Failed to restore {$backup['path']} to {$originalPath}");
        }

        return $backup['path'];
    }

    /**
     * Determine the next version number for a file in a given directory.
     */
    private function nextVersion(string $dir, string $basename): int
    {
        $existing = $this->findVersionsInDir($dir, $basename);
        return empty($existing) ? 1 : max($existing) + 1;
    }

    /**
     * Find all version numbers for a basename in a directory.
     *
     * @return int[]
     */
    private function findVersionsInDir(string $dir, string $basename): array
    {
        $pattern = '#^' . preg_quote($dir . '/' . $basename, '#') . '\\.([0-9]+)$#';
        $files = glob($dir . '/' . $basename . '.*') ?: [];
        $versions = [];

        foreach ($files as $file) {
            if (preg_match($pattern, $file, $m)) {
                $versions[] = (int) $m[1];
            }
        }

        return $versions;
    }

    /**
     * Remove backup versions beyond the retention limit.
     */
    private function pruneVersions(string $dir, string $basename): void
    {
        $versions = $this->findVersionsInDir($dir, $basename);
        if (count($versions) <= $this->versionsToKeep) {
            return;
        }

        rsort($versions); // Keep highest version numbers (newest)
        $toDelete = array_slice($versions, $this->versionsToKeep);

        foreach ($toDelete as $v) {
            @unlink($dir . '/' . $basename . '.' . $v);
        }
    }
}
