<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Storage;

/**
 * Creates session-scoped snapshots of files before bulk operations so
 * the entire session can be rolled back atomically if something goes wrong.
 *
 * Snapshots are stored under storage/rollback/{sessionId}/
 */
class RollbackManager
{
    private string $rollbackRoot;

    public function __construct(string $rollbackRoot = '')
    {
        $this->rollbackRoot = $rollbackRoot !== ''
            ? rtrim($rollbackRoot, '/')
            : dirname(__DIR__, 2) . '/storage/rollback';
    }

    /**
     * Snapshot one or more files under a session ID.
     *
     * Files that do not exist are recorded as "(new file)" so rollback
     * can delete them if needed.
     *
     * @param string[] $files Absolute paths to snapshot
     */
    public function createSnapshot(string $sessionId, array $files): void
    {
        $sessionDir = $this->sessionDir($sessionId);
        if (!is_dir($sessionDir) && !mkdir($sessionDir, 0755, true) && !is_dir($sessionDir)) {
            throw new \RuntimeException("Cannot create snapshot directory: {$sessionDir}");
        }

        $manifest = $this->loadManifest($sessionId);

        foreach ($files as $path) {
            $path = (string) $path;

            // Skip if already snapshotted in this session
            if (isset($manifest['files'][$path])) {
                continue;
            }

            if (is_file($path)) {
                $snapshotName = $this->snapshotName($path, count($manifest['files']));
                $snapshotPath = $sessionDir . '/' . $snapshotName;
                if (@copy($path, $snapshotPath)) {
                    $manifest['files'][$path] = [
                        'snapshot' => $snapshotPath,
                        'existed' => true,
                    ];
                }
            } else {
                // File didn't exist yet — rollback should delete it
                $manifest['files'][$path] = [
                    'snapshot' => null,
                    'existed' => false,
                ];
            }
        }

        $this->saveManifest($sessionId, $manifest);
    }

    /**
     * Rollback all files in a session to their snapshotted state.
     *
     * @return array{restored: string[], deleted: string[], errors: string[]}
     */
    public function rollback(string $sessionId): array
    {
        $manifest = $this->loadManifest($sessionId);
        $restored = [];
        $deleted = [];
        $errors = [];

        foreach ($manifest['files'] as $path => $info) {
            if ($info['existed'] && $info['snapshot'] !== null) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }

                if (@copy($info['snapshot'], $path)) {
                    $restored[] = $path;
                } else {
                    $errors[] = "Failed to restore: {$path}";
                }
            } elseif (!$info['existed'] && is_file($path)) {
                // File was created during the session — delete it
                if (@unlink($path)) {
                    $deleted[] = $path;
                } else {
                    $errors[] = "Failed to delete: {$path}";
                }
            }
        }

        return compact('restored', 'deleted', 'errors');
    }

    /**
     * List all available rollback sessions.
     *
     * @return array<array{session_id: string, created_at: string, file_count: int}>
     */
    public function listSessions(): array
    {
        if (!is_dir($this->rollbackRoot)) {
            return [];
        }

        $dirs = glob($this->rollbackRoot . '/*', GLOB_ONLYDIR) ?: [];
        $sessions = [];

        foreach ($dirs as $dir) {
            $sessionId = basename($dir);
            $manifest = $this->loadManifest($sessionId);
            $sessions[] = [
                'session_id' => $sessionId,
                'created_at' => $manifest['created_at'] ?? 'unknown',
                'file_count' => count($manifest['files'] ?? []),
                'task_preview' => mb_substr($manifest['task'] ?? '', 0, 80),
            ];
        }

        // Newest first
        usort($sessions, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $sessions;
    }

    /**
     * Store task description in the session manifest (for display in listings).
     */
    public function setSessionTask(string $sessionId, string $task): void
    {
        $sessionDir = $this->sessionDir($sessionId);
        if (!is_dir($sessionDir)) {
            @mkdir($sessionDir, 0755, true);
        }

        $manifest = $this->loadManifest($sessionId);
        $manifest['task'] = $task;
        $this->saveManifest($sessionId, $manifest);
    }

    private function sessionDir(string $sessionId): string
    {
        return $this->rollbackRoot . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sessionId);
    }

    /**
     * @return array{created_at: string, task: string, files: array<string, array{snapshot: ?string, existed: bool}>}
     */
    private function loadManifest(string $sessionId): array
    {
        $path = $this->sessionDir($sessionId) . '/manifest.json';

        if (!is_file($path)) {
            return [
                'created_at' => date('Y-m-d H:i:s'),
                'task' => '',
                'files' => [],
            ];
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : ['created_at' => date('Y-m-d H:i:s'), 'task' => '', 'files' => []];
    }

    private function saveManifest(string $sessionId, array $manifest): void
    {
        $path = $this->sessionDir($sessionId) . '/manifest.json';
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function snapshotName(string $path, int $index): string
    {
        return sprintf('%04d_%s', $index, preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($path)));
    }
}
