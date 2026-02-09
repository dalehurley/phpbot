<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Listener;

/**
 * JSON-backed state store for watcher watermarks.
 *
 * Tracks the last-seen identifier/timestamp per source so that
 * watchers only return genuinely new events on each poll.
 *
 * Uses atomic writes (temp file + rename) for crash safety.
 */
class StateStore
{
    private array $state = [];
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->load();
    }

    /**
     * Get a watermark value for a watcher.
     */
    public function get(string $watcher, string $key, mixed $default = null): mixed
    {
        return $this->state[$watcher][$key] ?? $default;
    }

    /**
     * Set a watermark value for a watcher.
     */
    public function set(string $watcher, string $key, mixed $value): void
    {
        if (!isset($this->state[$watcher])) {
            $this->state[$watcher] = [];
        }
        $this->state[$watcher][$key] = $value;
    }

    /**
     * Get all state for a watcher.
     *
     * @return array<string, mixed>
     */
    public function getAll(string $watcher): array
    {
        return $this->state[$watcher] ?? [];
    }

    /**
     * Persist the current state to disk (atomic write).
     */
    public function save(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Update the last_saved timestamp
        $this->state['_meta'] = [
            'last_saved' => (new \DateTimeImmutable())->format('c'),
        ];

        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Atomic write: write to temp then rename
        $tmpFile = $this->path . '.tmp.' . getmypid();
        if (file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            rename($tmpFile, $this->path);
        } else {
            @unlink($tmpFile);
        }
    }

    /**
     * Load state from disk.
     */
    private function load(): void
    {
        if (!is_file($this->path)) {
            $this->state = [];

            return;
        }

        $json = file_get_contents($this->path);
        if ($json === false) {
            $this->state = [];

            return;
        }

        $decoded = json_decode($json, true);
        $this->state = is_array($decoded) ? $decoded : [];
    }

    /**
     * Reset all state (useful for testing or re-initialization).
     */
    public function reset(): void
    {
        $this->state = [];
        $this->save();
    }
}
