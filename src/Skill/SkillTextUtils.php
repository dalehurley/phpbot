<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Skill;

/**
 * Static text-processing utilities shared across skill creation.
 *
 * Includes input sanitisation, slug generation, tool-recipe extraction,
 * and JSON parsing from LLM responses.
 */
class SkillTextUtils
{
    /**
     * File extension → human-readable label map used when sanitising
     * user input that contains file paths.
     */
    private const FILE_TYPE_MAP = [
        'pdf'  => 'a PDF file',
        'docx' => 'a Word document',
        'doc'  => 'a Word document',
        'xlsx' => 'a spreadsheet',
        'xls'  => 'a spreadsheet',
        'csv'  => 'a CSV file',
        'pptx' => 'a presentation',
        'ppt'  => 'a presentation',
        'txt'  => 'a text file',
        'json' => 'a JSON file',
        'xml'  => 'an XML file',
        'html' => 'an HTML file',
        'htm'  => 'an HTML file',
        'md'   => 'a Markdown file',
        'png'  => 'an image',
        'jpg'  => 'an image',
        'jpeg' => 'an image',
        'gif'  => 'an image',
        'svg'  => 'an image',
        'mp4'  => 'a video file',
        'mp3'  => 'an audio file',
        'wav'  => 'an audio file',
        'zip'  => 'an archive',
        'tar'  => 'an archive',
    ];

    // =========================================================================
    // Input sanitisation
    // =========================================================================

    /**
     * Remove user-specific file paths and replace with generic descriptions.
     */
    public static function sanitizeInput(string $input): string
    {
        $fileTypeMap = self::FILE_TYPE_MAP;

        $sanitized = preg_replace_callback(
            '#(?:/(?:Users|home|var|tmp|opt|etc|mnt|srv|Volumes)/[^\s,;)}\]]*|~/[^\s,;)}\]]*|[A-Z]:\\\\[^\s,;)}\]]*)#i',
            function ($match) use ($fileTypeMap) {
                $path = rtrim($match[0], '.');
                if (preg_match('/\.(\w+)$/', $path, $extMatch)) {
                    $ext = strtolower($extMatch[1]);
                    return $fileTypeMap[$ext] ?? 'a file';
                }
                return 'a file on the user\'s computer';
            },
            $input
        );

        return preg_replace('/\s+/', ' ', trim($sanitized));
    }

    // =========================================================================
    // Slug generation
    // =========================================================================

    /**
     * Convert a skill name into a filesystem-safe kebab-case slug.
     */
    public static function slugify(string $input): string
    {
        $input = strtolower(trim($input));
        if ($input === '') {
            return '';
        }

        $input = preg_replace('/[^a-z0-9]+/', '-', $input);
        $input = trim($input, '-');

        if (strlen($input) > 48) {
            $input = substr($input, 0, 48);
            $input = preg_replace('/-[^-]*$/', '', $input) ?: $input;
        }

        return $input;
    }

    /**
     * Generate a short (2-4 word) generic name from user input.
     */
    public static function generateShortName(string $input): string
    {
        $lower = strtolower(trim($input));

        // Remove common filler words and specific content
        $lower = preg_replace('/\b(saying|that|the|a|an|to|for|with|from|into|about|please|can you|could you)\b/i', ' ', $lower);

        // Remove quoted strings (specific content like message bodies)
        $lower = preg_replace('/"[^"]*"/', '', $lower);
        $lower = preg_replace("/'[^']*'/", '', $lower);

        // Remove file paths
        $lower = preg_replace('#(?:/[\w./-]+|~/[\w./-]+)#', '', $lower);

        // Clean up whitespace
        $lower = preg_replace('/\s+/', ' ', trim($lower));

        // Take first 4 meaningful words
        $words = array_filter(explode(' ', $lower), fn($w) => strlen($w) > 1);
        $words = array_values(array_slice($words, 0, 4));

        if (empty($words)) {
            return 'general-task';
        }

        return implode('-', $words);
    }

    // =========================================================================
    // Tool call helpers
    // =========================================================================

    /**
     * Normalise the input field from a tool call record.
     *
     * Tool call inputs may arrive as an array or a JSON-encoded string.
     */
    public static function parseToolInput(array $call): array
    {
        $input = $call['input'] ?? [];

        if (is_string($input)) {
            return json_decode($input, true) ?? [];
        }

        return is_array($input) ? $input : [];
    }

    // =========================================================================
    // Tool recipe extraction
    // =========================================================================

    /**
     * Extract a concise recipe of successful tool calls.
     */
    public static function extractToolRecipe(array $toolCalls): string
    {
        $commands = [];

        foreach ($toolCalls as $call) {
            if (!empty($call['is_error'])) {
                continue;
            }

            $tool = $call['tool'] ?? '';
            $input = self::parseToolInput($call);

            if ($tool === 'bash') {
                $command = trim((string) ($input['command'] ?? ''));
                if ($command === '' || in_array($command, $commands, true)) {
                    continue;
                }
                $commands[] = $command;
            } elseif ($tool === 'write_file') {
                $path = (string) ($input['path'] ?? '');
                if ($path !== '') {
                    $cmd = "# write_file: {$path}";
                    if (!in_array($cmd, $commands, true)) {
                        $commands[] = $cmd;
                    }
                }
            }
        }

        if (count($commands) > 20) {
            $commands = array_slice($commands, 0, 20);
            $commands[] = '# ... additional commands omitted';
        }

        return implode("\n", $commands);
    }

    // =========================================================================
    // JSON extraction from LLM responses
    // =========================================================================

    /**
     * Extract a JSON object from an LLM response that may be wrapped in
     * markdown fences, have leading/trailing text, or other noise.
     */
    public static function extractJsonFromResponse(?string $response): ?array
    {
        if ($response === null || $response === '') {
            return null;
        }

        // Try direct parse first (ideal case)
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        // Strip markdown code fences: ```json ... ``` or ``` ... ```
        $stripped = $response;
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $stripped, $m)) {
            $stripped = trim($m[1]);
            $data = json_decode($stripped, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }

        // Try to find the first { ... } block in the response
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $jsonCandidate = substr($response, $start, $end - $start + 1);
            $data = json_decode($jsonCandidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }

        return null;
    }
}
