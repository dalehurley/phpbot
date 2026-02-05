<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Storage;

class AbilityStore
{
    private string $storagePath;

    public function __construct(string $storagePath)
    {
        $this->storagePath = $storagePath;
        $this->ensureDirectory();
    }

    public function save(array $ability): string
    {
        $id = $ability['id'] ?? $this->generateId();
        $ability['id'] = $id;
        $ability['created_at'] = $ability['created_at'] ?? date('c');

        $file = $this->storagePath . '/' . $id . '.json';
        file_put_contents($file, json_encode($ability, JSON_PRETTY_PRINT));

        return $id;
    }

    public function get(string $id): ?array
    {
        $file = $this->storagePath . '/' . $id . '.json';
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return null;
        }

        return $data;
    }

    public function all(): array
    {
        if (!is_dir($this->storagePath)) {
            return [];
        }

        $files = glob($this->storagePath . '/*.json') ?: [];
        $abilities = [];

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $abilities[] = $data;
            }
        }

        // Sort by created_at descending (newest first)
        usort($abilities, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        return $abilities;
    }

    /**
     * Return compact summaries suitable for a retrieval agent to search through.
     */
    public function summaries(): array
    {
        $abilities = $this->all();
        $summaries = [];

        foreach ($abilities as $ability) {
            $summaries[] = [
                'id' => $ability['id'],
                'title' => $ability['title'] ?? '',
                'description' => $ability['description'] ?? '',
                'tags' => $ability['tags'] ?? [],
                'created_at' => $ability['created_at'] ?? '',
            ];
        }

        return $summaries;
    }

    /**
     * Get multiple abilities by their IDs.
     */
    public function getMany(array $ids): array
    {
        $results = [];
        foreach ($ids as $id) {
            $ability = $this->get($id);
            if ($ability !== null) {
                $results[] = $ability;
            }
        }
        return $results;
    }

    public function count(): int
    {
        if (!is_dir($this->storagePath)) {
            return 0;
        }

        $files = glob($this->storagePath . '/*.json') ?: [];
        return count($files);
    }

    public function delete(string $id): bool
    {
        $file = $this->storagePath . '/' . $id . '.json';
        if (is_file($file)) {
            return unlink($file);
        }
        return false;
    }

    private function generateId(): string
    {
        return 'ability_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }
}
