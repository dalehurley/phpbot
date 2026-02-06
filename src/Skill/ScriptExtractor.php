<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Skill;

use Dalehurley\Phpbot\CredentialPatterns;

/**
 * Extracts scripts from tool calls and bundles them into a skill directory.
 *
 * Handles multiple sources of scripts:
 *  - write_file tool calls that produced script files
 *  - bash heredoc patterns (cat/tee/redirect, including <<- tab-stripping)
 *  - tee without redirect (tee file <<MARKER)
 *  - echo/printf redirections that wrote script content
 *  - auto-generated API scripts from CurlScriptBuilder (merged externally)
 */
class ScriptExtractor
{
    /** File extensions recognised as bundleable scripts. */
    private const SCRIPT_EXTENSIONS = ['py', 'sh', 'bash', 'js', 'ts', 'php', 'rb', 'pl'];

    /** Extensions that are always marked executable. */
    private const EXECUTABLE_EXTENSIONS = ['sh', 'bash'];

    // =========================================================================
    // Extraction
    // =========================================================================

    /**
     * Extract script files from tool call records.
     *
     * Each returned entry is normalised with defaults via {@see makeDescriptor}:
     *   original_path, filename, content, extension, source,
     *   description ('Auto-captured from task execution'), parameters ([])
     *
     * @return list<array{original_path: string, filename: string, content: string, extension: string, source: string, description: string, parameters: array}>
     */
    public static function fromToolCalls(array $toolCalls): array
    {
        $scripts = [];

        foreach ($toolCalls as $call) {
            if (!empty($call['is_error'])) {
                continue;
            }

            $tool  = $call['tool'] ?? '';
            $input = self::parseInput($call);

            $extracted = match ($tool) {
                'write_file' => self::extractFromWriteFile($input),
                'bash'       => self::extractFromBash($input),
                default      => [],
            };

            array_push($scripts, ...$extracted);
        }

        return self::deduplicate($scripts);
    }

    // =========================================================================
    // Bundling
    // =========================================================================

    /**
     * Prepare and write scripts into the skill's scripts/ directory.
     *
     * @return list<array{filename: string, path: string, extension: string, original_path: string, description: string, parameters: array, source: string}>
     */
    public static function bundle(string $skillDir, array $scripts): array
    {
        if (empty($scripts)) {
            return [];
        }

        $scriptsDir = $skillDir . '/scripts';
        if (!is_dir($scriptsDir) && !mkdir($scriptsDir, 0755, true)) {
            return [];
        }

        $prepared = self::sanitize($scripts);

        return self::writeToDisk($scriptsDir, $prepared);
    }

    /**
     * Sanitise script contents without writing to disk.
     *
     * Useful for testing or previewing what would be bundled.
     * Credential stripping is handled entirely by {@see CredentialPatterns::strip}
     * which already covers user-specific paths (/Users/*, /home/*, C:\Users\*).
     *
     * @return list<array{filename: string, content: string, extension: string, original_path: string, description: string, parameters: array, source: string}>
     */
    public static function sanitize(array $scripts): array
    {
        return array_map(fn(array $script): array => [
            'filename'      => $script['filename'],
            'content'       => CredentialPatterns::strip($script['content']),
            'extension'     => $script['extension'],
            'original_path' => $script['original_path'],
            'description'   => $script['description'] ?? 'Auto-captured from task execution',
            'parameters'    => $script['parameters'] ?? [],
            'source'        => $script['source'] ?? 'write_file',
        ], $scripts);
    }

    // =========================================================================
    // Input parsing (shared)
    // =========================================================================

    /**
     * Normalise the input field from a tool call record.
     */
    private static function parseInput(array $call): array
    {
        return SkillTextUtils::parseToolInput($call);
    }

    // =========================================================================
    // Extractors
    // =========================================================================

    /**
     * @return list<array>
     */
    private static function extractFromWriteFile(array $input): array
    {
        $path    = (string) ($input['path'] ?? '');
        $content = (string) ($input['content'] ?? '');

        if ($path === '' || $content === '') {
            return [];
        }

        if (!self::isScriptExtension($path)) {
            return [];
        }

        return [self::makeDescriptor($path, $content, 'write_file')];
    }

    /**
     * Extract scripts from bash commands — supports multiple patterns:
     *
     *  1. Heredoc with cat/tee and redirect (> or >>), including <<- variant
     *     `cat > script.py <<'EOF' ... EOF`
     *     `tee >> run.sh <<-MARKER ... MARKER`
     *
     *  2. Tee without redirect (writes to file AND stdout)
     *     `tee script.sh <<'EOF' ... EOF`
     *
     *  3. Redirect-first heredoc
     *     `<<'EOF' > script.sh ... EOF`
     *
     *  4. echo/printf with single or double-quoted content redirected to a file
     *     `echo '#!/bin/bash ...' > script.sh`
     *     `printf "..." > run.py`
     *
     * Patterns are tried in order; the first match wins to avoid double-counting.
     *
     * @return list<array>
     */
    private static function extractFromBash(array $input): array
    {
        $command = (string) ($input['command'] ?? '');
        if ($command === '') {
            return [];
        }

        $extPattern  = implode('|', self::SCRIPT_EXTENSIONS);
        $filePattern = '([^\s<>|"]+\.(?:' . $extPattern . '))';

        // Pattern 1: cat/tee > file <<MARKER ... MARKER (with optional >> and <<-)
        if (preg_match(
            '/(?:cat|tee)\s+>>?\s*' . $filePattern . '\s*<<-?\s*[\'"]?(\w+)[\'"]?\n(.*?)\n\2/s',
            $command,
            $m
        )) {
            return [self::makeDescriptor($m[1], $m[3], 'bash_heredoc')];
        }

        // Pattern 2: tee without redirect (tee file <<MARKER)
        if (preg_match(
            '/\btee\s+' . $filePattern . '\s*<<-?\s*[\'"]?(\w+)[\'"]?\n(.*?)\n\2/s',
            $command,
            $m
        )) {
            return [self::makeDescriptor($m[1], $m[3], 'bash_heredoc')];
        }

        // Pattern 3: redirect-first heredoc (<<'EOF' > file ... EOF)
        if (preg_match(
            '/<<-?\s*[\'"]?(\w+)[\'"]?\s*>>?\s*' . $filePattern . '\n(.*?)\n\1/s',
            $command,
            $m
        )) {
            return [self::makeDescriptor($m[2], $m[3], 'bash_heredoc')];
        }

        // Pattern 4a: echo/printf single-quoted content > file
        if (preg_match(
            "/(?:echo|printf)\\s+'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'\s*>>?\s*" . $filePattern . '/s',
            $command,
            $m
        )) {
            $content = stripcslashes($m[1]);
            if (strlen($content) > 20) {
                return [self::makeDescriptor($m[2], $content, 'bash_echo')];
            }
        }

        // Pattern 4b: echo/printf double-quoted content > file
        if (preg_match(
            '/(?:echo|printf)\s+"((?:[^"\\\\]|\\\\.)*)"\s*>>?\s*' . $filePattern . '/s',
            $command,
            $m
        )) {
            $content = stripcslashes($m[1]);
            if (strlen($content) > 20) {
                return [self::makeDescriptor($m[2], $content, 'bash_echo')];
            }
        }

        return [];
    }

    // =========================================================================
    // Deduplication
    // =========================================================================

    /**
     * Deduplicate scripts by original path (last version of the same file wins).
     *
     * When two different paths produce the same basename, the second is
     * disambiguated by prepending the parent directory name, or failing
     * that, a numeric suffix.
     */
    private static function deduplicate(array $scripts): array
    {
        // Phase 1: by original_path — last version of the same file wins
        $byPath = [];
        foreach ($scripts as $script) {
            $byPath[$script['original_path']] = $script;
        }
        $scripts = array_values($byPath);

        // Phase 2: resolve filename collisions from different paths
        $taken = [];

        foreach ($scripts as &$script) {
            $name = $script['filename'];

            if (!isset($taken[$name])) {
                $taken[$name] = true;
                continue;
            }

            // Try parent_filename first (e.g. api_run.sh vs scripts_run.sh)
            $parent    = basename(dirname($script['original_path']));
            $candidate = ($parent !== '' && $parent !== '.')
                ? $parent . '_' . $name
                : null;

            if ($candidate !== null && !isset($taken[$candidate])) {
                $script['filename'] = $candidate;
                $taken[$candidate] = true;
                continue;
            }

            // Fallback: numeric suffix
            $ext  = pathinfo($name, PATHINFO_EXTENSION);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $i    = 2;
            do {
                $candidate = "{$base}_{$i}.{$ext}";
                $i++;
            } while (isset($taken[$candidate]));

            $script['filename'] = $candidate;
            $taken[$candidate] = true;
        }
        unset($script);

        return $scripts;
    }

    // =========================================================================
    // Disk I/O
    // =========================================================================

    /**
     * Write sanitised scripts to the scripts directory.
     *
     * @return list<array{filename: string, path: string, extension: string, original_path: string, description: string, parameters: array, source: string}>
     */
    private static function writeToDisk(string $scriptsDir, array $prepared): array
    {
        $bundled = [];

        foreach ($prepared as $script) {
            $targetPath = $scriptsDir . '/' . $script['filename'];

            if (file_put_contents($targetPath, $script['content']) === false) {
                continue;
            }

            if (self::shouldBeExecutable($script['extension'], $script['content'])) {
                chmod($targetPath, 0755);
            }

            $bundled[] = [
                'filename'      => $script['filename'],
                'path'          => 'scripts/' . $script['filename'],
                'extension'     => $script['extension'],
                'original_path' => $script['original_path'],
                'description'   => $script['description'],
                'parameters'    => $script['parameters'],
                'source'        => $script['source'],
            ];
        }

        return $bundled;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a normalised script descriptor with sensible defaults.
     *
     * This is the single place where the array shape is defined, eliminating
     * scattered ?? defaults in downstream code.
     */
    private static function makeDescriptor(string $path, string $content, string $source): array
    {
        return [
            'original_path' => $path,
            'filename'      => basename($path),
            'content'       => $content,
            'extension'     => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'source'        => $source,
            'description'   => 'Auto-captured from task execution',
            'parameters'    => [],
        ];
    }

    /**
     * Check if a file path has a recognised script extension.
     */
    private static function isScriptExtension(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, self::SCRIPT_EXTENSIONS, true);
    }

    /**
     * Determine if a script should be marked executable (chmod +x).
     *
     * Shell scripts are always executable. Any other script with a shebang
     * line (e.g. #!/usr/bin/env python3) is also marked executable.
     */
    private static function shouldBeExecutable(string $extension, string $content): bool
    {
        if (in_array($extension, self::EXECUTABLE_EXTENSIONS, true)) {
            return true;
        }

        return str_starts_with(ltrim($content), '#!');
    }
}
