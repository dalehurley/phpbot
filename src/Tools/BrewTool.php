<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;

/**
 * Homebrew package manager tool for macOS.
 *
 * Provides install, uninstall, search, list, update, upgrade,
 * info, doctor, tap, and outdated operations.
 */
class BrewTool implements ToolInterface
{
    use ToolDefinitionTrait;
    private int $maxOutputChars;

    public function __construct(array $config = [])
    {
        $this->maxOutputChars = (int) ($config['brew_max_output_chars'] ?? 15000);
    }

    public function getName(): string
    {
        return 'brew';
    }

    public function getDescription(): string
    {
        return 'Manage software packages on macOS using Homebrew. '
            . 'Install CLI tools (formulae) and GUI apps (casks), search for packages, '
            . 'list installed software, update/upgrade, uninstall, and diagnose issues. '
            . 'Use action "install" for CLI tools, "install_cask" for GUI apps (Chrome, Slack, VS Code, etc.). '
            . 'Requires macOS with Homebrew installed.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'The Homebrew action to perform.',
                    'enum' => [
                        'install',
                        'install_cask',
                        'uninstall',
                        'search',
                        'info',
                        'list',
                        'list_casks',
                        'update',
                        'upgrade',
                        'doctor',
                        'tap',
                        'outdated',
                    ],
                ],
                'packages' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Package name(s) for install, uninstall, upgrade, or info actions. '
                        . 'E.g. ["node", "redis"] or ["google-chrome"] for casks.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query string for the "search" action.',
                ],
                'tap_name' => [
                    'type' => 'string',
                    'description' => 'Repository to tap, e.g. "homebrew/cask-fonts". Required for "tap" action.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        // macOS check
        if (PHP_OS_FAMILY !== 'Darwin') {
            return ToolResult::error(
                'Homebrew is only supported on macOS. Current OS: ' . PHP_OS_FAMILY
            );
        }

        // Check Homebrew is installed
        $brewPath = $this->findBrew();
        if ($brewPath === null) {
            return ToolResult::error(
                'Homebrew is not installed. Install it with: '
                    . '/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"'
            );
        }

        $action = $input['action'] ?? '';
        $packages = $input['packages'] ?? [];
        $query = $input['query'] ?? '';
        $tapName = $input['tap_name'] ?? '';

        if (!is_array($packages)) {
            $packages = [$packages];
        }

        // Sanitize package names — only allow alphanumeric, hyphens, underscores, slashes, @
        foreach ($packages as $pkg) {
            if (!preg_match('/^[a-zA-Z0-9@._\/-]+$/', (string) $pkg)) {
                return ToolResult::error("Invalid package name: {$pkg}");
            }
        }

        return match ($action) {
            'install' => $this->install($brewPath, $packages, false),
            'install_cask' => $this->install($brewPath, $packages, true),
            'uninstall' => $this->uninstall($brewPath, $packages),
            'search' => $this->search($brewPath, $query),
            'info' => $this->info($brewPath, $packages),
            'list' => $this->listInstalled($brewPath, false),
            'list_casks' => $this->listInstalled($brewPath, true),
            'update' => $this->update($brewPath),
            'upgrade' => $this->upgrade($brewPath, $packages),
            'doctor' => $this->doctor($brewPath),
            'tap' => $this->tap($brewPath, $tapName),
            'outdated' => $this->outdated($brewPath),
            default => ToolResult::error("Unknown action: {$action}. Use one of: install, install_cask, uninstall, search, info, list, list_casks, update, upgrade, doctor, tap, outdated."),
        };
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    private function install(string $brewPath, array $packages, bool $cask): ToolResultInterface
    {
        if (empty($packages)) {
            return ToolResult::error('No packages specified. Provide at least one package name.');
        }

        $flag = $cask ? ' --cask' : '';
        $pkgList = implode(' ', array_map('escapeshellarg', $packages));
        $command = "{$brewPath} install{$flag} {$pkgList} 2>&1";

        $result = $this->run($command, 300);

        $type = $cask ? 'cask(s)' : 'formula(e)';
        return ToolResult::success(json_encode([
            'action' => $cask ? 'install_cask' : 'install',
            'packages' => $packages,
            'type' => $type,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function uninstall(string $brewPath, array $packages): ToolResultInterface
    {
        if (empty($packages)) {
            return ToolResult::error('No packages specified. Provide at least one package name to uninstall.');
        }

        $pkgList = implode(' ', array_map('escapeshellarg', $packages));
        $command = "{$brewPath} uninstall {$pkgList} 2>&1";

        $result = $this->run($command, 120);

        return ToolResult::success(json_encode([
            'action' => 'uninstall',
            'packages' => $packages,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function search(string $brewPath, string $query): ToolResultInterface
    {
        if (empty(trim($query))) {
            return ToolResult::error('No search query provided. Provide a query string.');
        }

        if (!preg_match('/^[a-zA-Z0-9@._\/ -]+$/', $query)) {
            return ToolResult::error("Invalid search query: {$query}");
        }

        $command = "{$brewPath} search " . escapeshellarg($query) . " 2>&1";
        $result = $this->run($command, 30);

        // Parse results into formulae and casks
        $output = $result['stdout'];
        $formulae = [];
        $casks = [];
        $section = 'formulae';

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '==>')) {
                if (stripos($line, 'Casks') !== false) {
                    $section = 'casks';
                } elseif (stripos($line, 'Formulae') !== false) {
                    $section = 'formulae';
                }
                continue;
            }
            if ($section === 'casks') {
                $casks[] = $line;
            } else {
                $formulae[] = $line;
            }
        }

        return ToolResult::success(json_encode([
            'action' => 'search',
            'query' => $query,
            'formulae' => $formulae,
            'casks' => $casks,
            'total_results' => count($formulae) + count($casks),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function info(string $brewPath, array $packages): ToolResultInterface
    {
        if (empty($packages)) {
            return ToolResult::error('No packages specified. Provide at least one package name.');
        }

        $pkgList = implode(' ', array_map('escapeshellarg', $packages));
        $command = "{$brewPath} info {$pkgList} 2>&1";

        $result = $this->run($command, 30);

        return ToolResult::success(json_encode([
            'action' => 'info',
            'packages' => $packages,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function listInstalled(string $brewPath, bool $casks): ToolResultInterface
    {
        $flag = $casks ? ' --cask' : '';
        $command = "{$brewPath} list{$flag} 2>&1";

        $result = $this->run($command, 30);

        $items = array_filter(
            array_map('trim', explode("\n", $result['stdout'])),
            fn($line) => $line !== ''
        );

        $type = $casks ? 'casks' : 'formulae';

        return ToolResult::success(json_encode([
            'action' => $casks ? 'list_casks' : 'list',
            'type' => $type,
            'installed' => array_values($items),
            'count' => count($items),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function update(string $brewPath): ToolResultInterface
    {
        $command = "{$brewPath} update 2>&1";
        $result = $this->run($command, 120);

        return ToolResult::success(json_encode([
            'action' => 'update',
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function upgrade(string $brewPath, array $packages): ToolResultInterface
    {
        $pkgList = !empty($packages)
            ? ' ' . implode(' ', array_map('escapeshellarg', $packages))
            : '';
        $command = "{$brewPath} upgrade{$pkgList} 2>&1";

        $result = $this->run($command, 300);

        return ToolResult::success(json_encode([
            'action' => 'upgrade',
            'packages' => $packages ?: 'all',
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function doctor(string $brewPath): ToolResultInterface
    {
        $command = "{$brewPath} doctor 2>&1";
        $result = $this->run($command, 60);

        $healthy = $result['exit_code'] === 0;

        return ToolResult::success(json_encode([
            'action' => 'doctor',
            'healthy' => $healthy,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => true, // doctor always "succeeds" — exit code 1 just means issues found
        ]));
    }

    private function tap(string $brewPath, string $tapName): ToolResultInterface
    {
        if (empty(trim($tapName))) {
            return ToolResult::error('No tap name provided. Example: "homebrew/cask-fonts".');
        }

        if (!preg_match('/^[a-zA-Z0-9._\/-]+$/', $tapName)) {
            return ToolResult::error("Invalid tap name: {$tapName}");
        }

        $command = "{$brewPath} tap " . escapeshellarg($tapName) . " 2>&1";
        $result = $this->run($command, 120);

        return ToolResult::success(json_encode([
            'action' => 'tap',
            'tap_name' => $tapName,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function outdated(string $brewPath): ToolResultInterface
    {
        $command = "{$brewPath} outdated --verbose 2>&1";
        $result = $this->run($command, 30);

        $items = array_filter(
            array_map('trim', explode("\n", $result['stdout'])),
            fn($line) => $line !== ''
        );

        return ToolResult::success(json_encode([
            'action' => 'outdated',
            'outdated_packages' => array_values($items),
            'count' => count($items),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Locate the brew binary.
     */
    private function findBrew(): ?string
    {
        // Common Homebrew paths (Apple Silicon and Intel)
        $paths = [
            '/opt/homebrew/bin/brew',    // Apple Silicon
            '/usr/local/bin/brew',       // Intel Mac
            '/home/linuxbrew/.linuxbrew/bin/brew', // Linux
        ];

        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        // Fallback: try which
        $which = trim((string) @shell_exec('which brew 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        return null;
    }

    /**
     * Run a shell command and return stdout, stderr, exit_code.
     */
    private function run(string $command, int $timeout = 60): array
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
     * Truncate output to prevent context window explosion.
     */
    private function truncate(string $output): string
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
