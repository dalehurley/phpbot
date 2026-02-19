<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Storage;

/**
 * Records successful task executions so they can be browsed, replayed,
 * and referenced as context for similar future requests.
 *
 * Each task is stored as storage/history/{taskId}.json
 */
class TaskHistory
{
    private string $historyDir;

    public function __construct(string $historyDir = '')
    {
        $this->historyDir = $historyDir !== ''
            ? rtrim($historyDir, '/')
            : dirname(__DIR__, 2) . '/storage/history';
    }

    /**
     * Record a successful task execution.
     *
     * @param array<string,mixed> $params Key/value parameters extracted from the task
     * @return string The generated task ID
     */
    public function record(string $task, string $result, array $params = [], array $metadata = []): string
    {
        $this->ensureDir();

        $taskId = date('Ymd-His') . '-' . bin2hex(random_bytes(4));

        $entry = [
            'id' => $taskId,
            'task' => $task,
            'result_summary' => mb_substr($result, 0, 500),
            'params' => $params,
            'metadata' => $metadata,
            'recorded_at' => date('Y-m-d H:i:s'),
            'keywords' => $this->extractKeywords($task),
        ];

        $path = $this->historyDir . '/' . $taskId . '.json';
        file_put_contents($path, json_encode($entry, JSON_PRETTY_PRINT), LOCK_EX);

        return $taskId;
    }

    /**
     * List all recorded tasks, newest first.
     *
     * @return array<array{id: string, task: string, recorded_at: string, result_summary: string}>
     */
    public function list(int $limit = 50): array
    {
        if (!is_dir($this->historyDir)) {
            return [];
        }

        $files = glob($this->historyDir . '/*.json') ?: [];
        rsort($files); // Newest first by filename (date-prefixed)

        $entries = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $entries[] = [
                    'id' => $data['id'] ?? basename($file, '.json'),
                    'task' => $data['task'] ?? '',
                    'recorded_at' => $data['recorded_at'] ?? '',
                    'result_summary' => $data['result_summary'] ?? '',
                ];
            }
        }

        return $entries;
    }

    /**
     * Get the full details of a specific task by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(string $taskId): ?array
    {
        $path = $this->historyDir . '/' . $taskId . '.json';

        if (!is_file($path)) {
            // Try prefix search for partial IDs
            $matches = glob($this->historyDir . '/' . $taskId . '*.json') ?: [];
            if (empty($matches)) {
                return null;
            }
            $path = $matches[0];
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Find tasks similar to the given input using keyword overlap.
     *
     * @return array<array{id: string, task: string, recorded_at: string, score: float}>
     */
    public function findSimilar(string $input, int $limit = 3): array
    {
        if (!is_dir($this->historyDir)) {
            return [];
        }

        $inputKeywords = $this->extractKeywords($input);
        if (empty($inputKeywords)) {
            return [];
        }

        $files = glob($this->historyDir . '/*.json') ?: [];
        $scored = [];

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }

            $storedKeywords = $data['keywords'] ?? $this->extractKeywords($data['task'] ?? '');
            $overlap = count(array_intersect($inputKeywords, $storedKeywords));

            if ($overlap > 0) {
                $score = $overlap / max(count($inputKeywords), count($storedKeywords));
                $scored[] = [
                    'id' => $data['id'] ?? basename($file, '.json'),
                    'task' => $data['task'] ?? '',
                    'recorded_at' => $data['recorded_at'] ?? '',
                    'score' => round($score, 3),
                ];
            }
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Apply parameter overrides to a historical task's input string for replay.
     *
     * Replaces occurrences of historical param values with new values.
     *
     * @param array<string,string> $overrides
     */
    public function applyOverrides(string $task, array $params, array $overrides): string
    {
        $result = $task;
        foreach ($overrides as $key => $newValue) {
            if (isset($params[$key])) {
                $result = str_replace($params[$key], $newValue, $result);
            }
        }
        return $result;
    }

    /**
     * Extract significant keywords from a task string for similarity matching.
     *
     * @return string[]
     */
    private function extractKeywords(string $text): array
    {
        // Lowercase and split on non-alphanumeric
        $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($text)) ?: [];

        // Remove stop words and short tokens
        $stopWords = ['the', 'a', 'an', 'is', 'it', 'in', 'to', 'and', 'or', 'for',
            'of', 'on', 'at', 'by', 'with', 'from', 'as', 'be', 'was', 'are',
            'can', 'my', 'me', 'i', 'you', 'all', 'do', 'did', 'has', 'had'];

        return array_values(array_unique(array_filter($words, function (string $w) use ($stopWords): bool {
            return strlen($w) >= 3 && !in_array($w, $stopWords, true);
        })));
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->historyDir) && !mkdir($this->historyDir, 0755, true) && !is_dir($this->historyDir)) {
            throw new \RuntimeException("Cannot create history directory: {$this->historyDir}");
        }
    }
}
