<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\CLI;

use Dalehurley\Phpbot\Platform;

/**
 * Resolves file references from user input.
 *
 * Supports:
 * - @path/to/file syntax (inline file references)
 * - Absolute paths from drag-and-drop into terminal
 * - Native file picker dialog (macOS osascript, Linux zenity/kdialog)
 * - Fuzzy file search via fzf (if installed) or find fallback
 * - Glob patterns (e.g. @src/*.php)
 */
class FileResolver
{
    /** @var array<string, string> Attached files: path => content */
    private array $attachedFiles = [];

    private string $cwd;

    public function __construct(?string $cwd = null)
    {
        $this->cwd = $cwd ?? (getcwd() ?: '/');
    }

    /**
     * Get all currently attached files.
     *
     * @return array<string, string> path => content
     */
    public function getAttachedFiles(): array
    {
        return $this->attachedFiles;
    }

    /**
     * Get a count of attached files.
     */
    public function count(): int
    {
        return count($this->attachedFiles);
    }

    /**
     * Clear all attached files.
     */
    public function clear(): void
    {
        $this->attachedFiles = [];
    }

    /**
     * Remove a specific attached file by path.
     */
    public function detach(string $path): bool
    {
        $resolved = $this->resolvePath($path);
        if (isset($this->attachedFiles[$resolved])) {
            unset($this->attachedFiles[$resolved]);
            return true;
        }

        // Try matching by basename
        foreach ($this->attachedFiles as $attachedPath => $_) {
            if (basename($attachedPath) === basename($path)) {
                unset($this->attachedFiles[$attachedPath]);
                return true;
            }
        }

        return false;
    }

    /**
     * Attach a file by path. Reads and stores its content.
     *
     * @return array{success: bool, path: string, error?: string, bytes?: int}
     */
    public function attach(string $path): array
    {
        $resolved = $this->resolvePath($path);

        if (!is_file($resolved)) {
            return ['success' => false, 'path' => $resolved, 'error' => 'File not found'];
        }

        if (!is_readable($resolved)) {
            return ['success' => false, 'path' => $resolved, 'error' => 'File not readable'];
        }

        $size = filesize($resolved);
        if ($size === false || $size > 500000) {
            return [
                'success' => false,
                'path' => $resolved,
                'error' => 'File too large (max 500KB). Use read_file tool for large files.',
            ];
        }

        $content = file_get_contents($resolved);
        if ($content === false) {
            return ['success' => false, 'path' => $resolved, 'error' => 'Failed to read file'];
        }

        // Detect binary files
        if ($this->isBinary($content)) {
            return [
                'success' => false,
                'path' => $resolved,
                'error' => 'Binary file detected. Only text files can be attached.',
            ];
        }

        $this->attachedFiles[$resolved] = $content;
        return ['success' => true, 'path' => $resolved, 'bytes' => strlen($content)];
    }

    /**
     * Attach multiple files from a glob pattern.
     *
     * @return array{attached: string[], errors: string[]}
     */
    public function attachGlob(string $pattern): array
    {
        $resolvedPattern = $this->resolvePath($pattern);
        $files = glob($resolvedPattern);

        if ($files === false || empty($files)) {
            return ['attached' => [], 'errors' => ["No files matched pattern: {$pattern}"]];
        }

        $attached = [];
        $errors = [];

        foreach ($files as $file) {
            if (is_dir($file)) {
                continue;
            }
            $result = $this->attach($file);
            if ($result['success']) {
                $attached[] = $result['path'];
            } else {
                $errors[] = "{$result['path']}: {$result['error']}";
            }
        }

        return ['attached' => $attached, 'errors' => $errors];
    }

    /**
     * Parse user input for @file references and attach them.
     * Returns the input with @references replaced by a note about attached context.
     *
     * @return array{input: string, attached: string[], errors: string[]}
     */
    public function parseAndAttach(string $input): array
    {
        $attached = [];
        $errors = [];

        // Match @path references (not preceded by a word char, not an email)
        // Handles: @file.txt, @src/file.php, @./relative, @~/home, @/absolute/path
        // Also handles quoted paths: @"path with spaces/file.txt"
        $pattern = '/(?<!\w)@("([^"]+)"|(\S+))/';

        $cleanedInput = preg_replace_callback($pattern, function ($matches) use (&$attached, &$errors) {
            // Prefer the quoted path, fall back to unquoted
            $path = $matches[2] !== '' ? $matches[2] : $matches[3];

            // Skip if it looks like an email domain or social handle
            if (preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $path)) {
                return $matches[0]; // Leave as-is
            }

            // Check if it's a glob pattern
            if (str_contains($path, '*') || str_contains($path, '?')) {
                $result = $this->attachGlob($path);
                $attached = array_merge($attached, $result['attached']);
                $errors = array_merge($errors, $result['errors']);
                return ''; // Remove from input
            }

            $result = $this->attach($path);
            if ($result['success']) {
                $attached[] = $result['path'];
            } else {
                $errors[] = "{$path}: {$result['error']}";
            }

            return ''; // Remove the @reference from input
        }, $input);

        // Clean up extra whitespace from removed references
        $cleanedInput = trim(preg_replace('/\s{2,}/', ' ', $cleanedInput ?? $input));

        return [
            'input' => $cleanedInput,
            'attached' => $attached,
            'errors' => $errors,
        ];
    }

    /**
     * Detect absolute file paths in input (from drag-and-drop).
     * Terminal drag-and-drop typically pastes absolute paths, often with escaping.
     *
     * @return array{input: string, attached: string[], errors: string[]}
     */
    public function detectDragAndDrop(string $input): array
    {
        $attached = [];
        $errors = [];

        // Detect absolute paths (macOS/Linux) - including escaped spaces
        // Matches: /Users/foo/bar/file.txt or /Users/foo/bar\ baz/file.txt
        // Also matches paths wrapped in single quotes from drag-and-drop
        $patterns = [
            // Single-quoted absolute path (common macOS drag-drop format)
            "/'(\/[^']+)'/",
            // Absolute path with escaped spaces
            '/(?:^|\s)(\/(?:[^\s\\\\]|\\\\.)+)/',
            // ~/path
            '/(?:^|\s)(~\/(?:[^\s\\\\]|\\\\.)+)/',
        ];

        $processedInput = $input;

        foreach ($patterns as $pattern) {
            $processedInput = preg_replace_callback($pattern, function ($matches) use (&$attached, &$errors) {
                $path = $matches[1];
                // Unescape spaces
                $path = str_replace('\\ ', ' ', $path);
                $path = str_replace("'", '', $path);

                // Expand ~
                if (str_starts_with($path, '~/')) {
                    $home = getenv('HOME') ?: '/tmp';
                    $path = $home . substr($path, 1);
                }

                // Only attach if it looks like a real file path (has extension or exists)
                if (!is_file($path)) {
                    return $matches[0]; // Leave as-is
                }

                $result = $this->attach($path);
                if ($result['success']) {
                    $attached[] = $result['path'];
                    return ''; // Remove path from input
                } else {
                    $errors[] = "{$path}: {$result['error']}";
                    return $matches[0];
                }
            }, $processedInput) ?? $processedInput;
        }

        $processedInput = trim(preg_replace('/\s{2,}/', ' ', $processedInput));

        return [
            'input' => $processedInput,
            'attached' => $attached,
            'errors' => $errors,
        ];
    }

    /**
     * Open native file picker dialog (macOS, Linux with zenity/kdialog).
     *
     * @return string|null Selected file path, or null if cancelled/unavailable
     */
    public function openFilePicker(string $prompt = 'Select a file', ?string $defaultDir = null): ?string
    {
        $backend = Platform::filePickerBackend();
        if ($backend === null) {
            return null;
        }

        $dir = $defaultDir ?? $this->cwd;

        $command = match ($backend) {
            'osascript' => $this->buildOsascriptPickerCommand($prompt, $dir, false),
            'zenity' => sprintf(
                'zenity --file-selection --title=%s --filename=%s 2>/dev/null',
                escapeshellarg($prompt),
                escapeshellarg($dir . '/')
            ),
            'kdialog' => sprintf(
                'kdialog --getopenfilename %s 2>/dev/null',
                escapeshellarg($dir)
            ),
            default => null,
        };

        if ($command === null) {
            return null;
        }

        $output = @shell_exec($command);

        if ($output === null || trim($output) === '') {
            return null;
        }

        return trim($output);
    }

    /**
     * Open native multi-file picker dialog (macOS, Linux with zenity/kdialog).
     *
     * @return string[] Selected file paths
     */
    public function openMultiFilePicker(string $prompt = 'Select files', ?string $defaultDir = null): array
    {
        $backend = Platform::filePickerBackend();
        if ($backend === null) {
            return [];
        }

        $dir = $defaultDir ?? $this->cwd;

        if ($backend === 'osascript') {
            $command = $this->buildOsascriptPickerCommand($prompt, $dir, true);
            $separator = '|||';
        } elseif ($backend === 'zenity') {
            $command = sprintf(
                'zenity --file-selection --multiple --separator=%s --title=%s --filename=%s 2>/dev/null',
                escapeshellarg('|||'),
                escapeshellarg($prompt),
                escapeshellarg($dir . '/')
            );
            $separator = '|||';
        } elseif ($backend === 'kdialog') {
            $command = sprintf(
                'kdialog --getopenfilename %s --multiple 2>/dev/null',
                escapeshellarg($dir)
            );
            $separator = "\n";
        } else {
            return [];
        }

        $output = @shell_exec($command);

        if ($output === null || trim($output) === '') {
            return [];
        }

        return array_filter(array_map('trim', explode($separator, trim($output))));
    }

    /**
     * Build an osascript command for macOS file picker.
     */
    private function buildOsascriptPickerCommand(string $prompt, string $dir, bool $multiple): string
    {
        $escapedDir = str_replace('"', '\\"', $dir);
        $escapedPrompt = str_replace('"', '\\"', $prompt);

        if ($multiple) {
            $script = <<<APPLESCRIPT
            tell application "System Events"
                activate
                set theFiles to choose file with prompt "{$escapedPrompt}" default location POSIX file "{$escapedDir}" with multiple selections allowed
                set pathList to {}
                repeat with f in theFiles
                    set end of pathList to POSIX path of f
                end repeat
                set AppleScript's text item delimiters to "|||"
                return pathList as text
            end tell
            APPLESCRIPT;
        } else {
            $script = <<<APPLESCRIPT
            tell application "System Events"
                activate
                set theFile to choose file with prompt "{$escapedPrompt}" default location POSIX file "{$escapedDir}"
                return POSIX path of theFile
            end tell
            APPLESCRIPT;
        }

        return sprintf('osascript -e %s 2>/dev/null', escapeshellarg($script));
    }

    /**
     * Search for files using fzf (if installed) or find fallback.
     *
     * @return string|null Selected file path, or null if cancelled
     */
    public function searchFiles(?string $query = null, ?string $dir = null): ?string
    {
        $searchDir = $dir ?? $this->cwd;

        // Try fzf first (much better UX)
        if ($this->commandExists('fzf')) {
            return $this->searchWithFzf($query, $searchDir);
        }

        // Fallback to find + simple selection
        return $this->searchWithFind($query, $searchDir);
    }

    /**
     * Build a context block from all attached files for inclusion in the prompt.
     */
    public function buildContextBlock(): string
    {
        if (empty($this->attachedFiles)) {
            return '';
        }

        $context = "## Attached File Context\n";
        $context .= "The following files have been provided by the user as context:\n\n";

        foreach ($this->attachedFiles as $path => $content) {
            $relativePath = $this->relativePath($path);
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $lines = substr_count($content, "\n") + 1;
            $bytes = strlen($content);

            $context .= "### File: {$relativePath}\n";
            $context .= "({$lines} lines, {$bytes} bytes)\n";
            $context .= "```{$ext}\n{$content}\n```\n\n";
        }

        return $context;
    }

    /**
     * Get a short summary of attached files for display.
     */
    public function getSummary(): string
    {
        if (empty($this->attachedFiles)) {
            return 'No files attached.';
        }

        $lines = [];
        foreach ($this->attachedFiles as $path => $content) {
            $relativePath = $this->relativePath($path);
            $bytes = strlen($content);
            $size = $this->formatBytes($bytes);
            $lines[] = "  {$relativePath} ({$size})";
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolvePath(string $path): string
    {
        // Expand ~
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME') ?: '/tmp';
            $path = $home . substr($path, 1);
        }

        // Make relative paths absolute
        if (!str_starts_with($path, '/')) {
            $path = $this->cwd . '/' . $path;
        }

        // Resolve ../ and ./ segments
        $realpath = realpath($path);
        return $realpath !== false ? $realpath : $path;
    }

    private function relativePath(string $absolutePath): string
    {
        if (str_starts_with($absolutePath, $this->cwd . '/')) {
            return substr($absolutePath, strlen($this->cwd) + 1);
        }

        $home = getenv('HOME') ?: '';
        if ($home !== '' && str_starts_with($absolutePath, $home . '/')) {
            return '~/' . substr($absolutePath, strlen($home) + 1);
        }

        return $absolutePath;
    }

    private function isBinary(string $content): bool
    {
        // Check first 8KB for null bytes (strong binary indicator)
        $sample = substr($content, 0, 8192);
        return str_contains($sample, "\0");
    }

    private function commandExists(string $command): bool
    {
        $output = @shell_exec("which {$command} 2>/dev/null");
        return $output !== null && trim($output) !== '';
    }

    private function searchWithFzf(?string $query, string $dir): ?string
    {
        $excludes = '--exclude .git --exclude node_modules --exclude vendor --exclude .DS_Store';

        // Use fd if available (faster), otherwise find
        if ($this->commandExists('fd')) {
            $findCmd = sprintf('fd --type f %s . %s', $excludes, escapeshellarg($dir));
        } else {
            $findCmd = sprintf(
                'find %s -type f -not -path "*/.git/*" -not -path "*/node_modules/*" -not -path "*/vendor/*" 2>/dev/null',
                escapeshellarg($dir)
            );
        }

        $fzfQuery = $query !== null ? sprintf('--query %s', escapeshellarg($query)) : '';
        $fzfOpts = "--height 40% --reverse --border --prompt 'Select file: ' {$fzfQuery}";

        // fzf needs a real terminal, so we connect it to /dev/tty
        $command = "{$findCmd} | fzf {$fzfOpts} < /dev/tty";
        $output = @shell_exec($command);

        if ($output === null || trim($output) === '') {
            return null;
        }

        return trim($output);
    }

    private function searchWithFind(?string $query, string $dir): ?string
    {
        $nameFilter = $query !== null
            ? sprintf('-iname %s', escapeshellarg("*{$query}*"))
            : '';

        $command = sprintf(
            'find %s -type f %s -not -path "*/.git/*" -not -path "*/node_modules/*" -not -path "*/vendor/*" 2>/dev/null | head -20',
            escapeshellarg($dir),
            $nameFilter
        );

        $output = @shell_exec($command);
        if ($output === null || trim($output) === '') {
            return null;
        }

        $files = array_filter(array_map('trim', explode("\n", trim($output))));
        if (empty($files)) {
            return null;
        }

        // Display numbered list for selection
        echo "\n  Search results:\n";
        foreach ($files as $i => $file) {
            $relative = $this->relativePath($file);
            echo sprintf("  %2d) %s\n", $i + 1, $relative);
        }
        echo "   0) Cancel\n\n";

        $prompt = "Select file (1-" . count($files) . "): ";
        if (function_exists('readline')) {
            $choice = readline($prompt);
        } else {
            echo $prompt;
            $choice = fgets(STDIN);
        }

        $index = (int) trim($choice ?: '') - 1;
        if ($index < 0 || $index >= count($files)) {
            return null;
        }

        return $files[$index];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes}B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . 'KB';
        }
        return round($bytes / 1048576, 1) . 'MB';
    }
}
