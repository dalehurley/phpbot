<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Storage;

/**
 * Saves agent progress checkpoints during long-running tasks so execution
 * can be resumed after a crash, timeout, or Ctrl+C interrupt.
 *
 * Checkpoint files: storage/checkpoints/{sessionId}.json
 */
class CheckpointManager
{
    private string $checkpointDir;

    public function __construct(string $checkpointDir = '')
    {
        $this->checkpointDir = $checkpointDir !== ''
            ? rtrim($checkpointDir, '/')
            : dirname(__DIR__, 2) . '/storage/checkpoints';
    }

    /**
     * Save the current agent state as a checkpoint.
     *
     * @param array<string,mixed> $state
     */
    public function save(string $sessionId, array $state): void
    {
        $this->ensureDir();

        $state['checkpoint_at'] = date('Y-m-d H:i:s');
        $state['session_id'] = $sessionId;

        $path = $this->checkpointPath($sessionId);
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Load a checkpoint by session ID.
     *
     * @return array<string,mixed>|null
     */
    public function load(string $sessionId): ?array
    {
        $path = $this->checkpointPath($sessionId);

        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * List all available checkpoint sessions, newest first.
     *
     * @return array<array{session_id: string, task: string, checkpoint_at: string, iteration: int}>
     */
    public function listSessions(): array
    {
        if (!is_dir($this->checkpointDir)) {
            return [];
        }

        $files = glob($this->checkpointDir . '/*.json') ?: [];
        $sessions = [];

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }

            $sessions[] = [
                'session_id' => $data['session_id'] ?? basename($file, '.json'),
                'task' => mb_substr($data['task'] ?? '', 0, 100),
                'checkpoint_at' => $data['checkpoint_at'] ?? '',
                'iteration' => $data['iteration'] ?? 0,
            ];
        }

        usort($sessions, fn($a, $b) => strcmp($b['checkpoint_at'], $a['checkpoint_at']));

        return $sessions;
    }

    /**
     * Delete a checkpoint after successful completion.
     */
    public function clear(string $sessionId): void
    {
        $path = $this->checkpointPath($sessionId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Check if a checkpoint exists for the given session.
     */
    public function exists(string $sessionId): bool
    {
        return is_file($this->checkpointPath($sessionId));
    }

    private function checkpointPath(string $sessionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $sessionId);
        return $this->checkpointDir . '/' . $safe . '.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->checkpointDir) && !mkdir($this->checkpointDir, 0755, true) && !is_dir($this->checkpointDir)) {
            throw new \RuntimeException("Cannot create checkpoint directory: {$this->checkpointDir}");
        }
    }
}
