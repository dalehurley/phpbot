<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Apple;

/**
 * Shared helper for executing AppleScript and shell commands.
 *
 * Extracted from AppleServicesTool so that watchers, the event router,
 * and any other component can run AppleScript without instantiating
 * the full tool.
 */
class AppleScriptRunner
{
    private int $maxOutputChars;

    public function __construct(int $maxOutputChars = 15000)
    {
        $this->maxOutputChars = $maxOutputChars;
    }

    /**
     * Execute an AppleScript via osascript.
     *
     * Returns the result array or null if a permission error is detected.
     *
     * @return array{stdout: string, stderr: string, exit_code: int}|null
     */
    public function runOsascript(string $script, int $timeout = 30): ?array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'phpbot_as_');
        if ($tmpFile === false) {
            return ['stdout' => '', 'stderr' => 'Failed to create temp file', 'exit_code' => 1];
        }
        file_put_contents($tmpFile, $script);

        $command = 'osascript ' . escapeshellarg($tmpFile) . ' 2>&1';
        $result = $this->runCommand($command, $timeout);

        @unlink($tmpFile);

        if ($this->isPermissionError($result)) {
            return null;
        }

        return $result;
    }

    /**
     * Run a shell command and return stdout, stderr, exit_code.
     *
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    public function runCommand(string $command, int $timeout = 60): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, getcwd() ?: '/tmp');

        if (!is_resource($process)) {
            return ['stdout' => '', 'stderr' => 'Failed to start process', 'exit_code' => 1];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if (time() - $startTime > $timeout) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return [
                    'stdout' => trim($stdout),
                    'stderr' => "Command timed out after {$timeout} seconds",
                    'exit_code' => 124,
                ];
            }

            usleep(10000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'exit_code' => $status['exitcode'] ?? $exitCode,
        ];
    }

    /**
     * Detect macOS Automation permission errors in osascript output.
     */
    public function isPermissionError(array $result): bool
    {
        $output = $result['stdout'] . ' ' . $result['stderr'];
        $patterns = [
            'not allowed assistive access',
            'is not allowed to send keystrokes',
            'not authorized to send Apple events',
            'execution error: Not authorized',
            'CommandProcess completed with a non-zero exit code',
            'assistive access',
        ];

        foreach ($patterns as $pattern) {
            if (stripos($output, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Escape a string for safe embedding in AppleScript double-quoted strings.
     */
    public function escapeAppleScript(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);

        return $value;
    }

    /**
     * Parse tab-separated output lines into structured arrays.
     *
     * @param string $output Raw TSV output
     * @param array<string> $fields Field names for each column
     * @return array<array<string, string>>
     */
    public function parseTsvOutput(string $output, array $fields): array
    {
        $rows = [];
        $lines = array_filter(array_map('trim', explode("\n", $output)), fn($l) => $l !== '');

        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            $row = [];
            foreach ($fields as $i => $field) {
                $row[$field] = $parts[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Truncate output to prevent context window explosion.
     */
    public function truncate(string $output): string
    {
        if (strlen($output) <= $this->maxOutputChars) {
            return $output;
        }

        $half = (int) ($this->maxOutputChars / 2);
        $totalLines = substr_count($output, "\n") + 1;

        return substr($output, 0, $half)
            . "\n\n... [output truncated: ~{$totalLines} lines total] ...\n\n"
            . substr($output, -$half);
    }
}
