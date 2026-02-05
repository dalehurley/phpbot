<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Storage;

class KeyStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function get(string $key): ?string
    {
        $data = $this->readAll();
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $data = $this->readAll();
        $data[$key] = $value;
        $this->writeAll($data);
    }

    private function readAll(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $content = file_get_contents($this->path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return [];
        }

        return $data;
    }

    private function writeAll(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT));
    }
}
