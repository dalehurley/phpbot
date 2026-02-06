<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;

/**
 * Searches the local computer for API keys, credentials, and configuration
 * values using common file locations, environment variables, shell profiles,
 * .env files, and config directories.
 */
class SearchComputerTool implements ToolInterface
{
    use ToolDefinitionTrait;
    private string $homeDir;

    public function __construct()
    {
        $this->homeDir = getenv('HOME') ?: (getenv('USERPROFILE') ?: '/tmp');
    }

    public function getName(): string
    {
        return 'search_computer';
    }

    public function getDescription(): string
    {
        return 'Search the local computer for API keys, credentials, tokens, and config values. '
            . 'Searches environment variables, shell profiles (.zshrc, .bashrc, .bash_profile), '
            . '.env files in common project directories, config files, and macOS Keychain. '
            . 'Use this AFTER get_keys returns missing keys and BEFORE ask_user — the key might already exist on the machine. '
            . 'Example search_terms: ["OPENAI_API_KEY", "openai"], ["TWILIO", "twilio_account_sid"].';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search_terms' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Key names or patterns to search for (e.g. ["OPENAI_API_KEY", "openai", "ANTHROPIC_API_KEY"]). Case-insensitive matching is used.',
                ],
                'search_locations' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional extra directories to search for .env files (e.g. ["/Users/me/projects/myapp"]). Home directory and common locations are always searched.',
                ],
                'include_env_vars' => [
                    'type' => 'boolean',
                    'description' => 'Whether to search current environment variables. Default: true.',
                ],
                'include_shell_profiles' => [
                    'type' => 'boolean',
                    'description' => 'Whether to search shell profile files (.zshrc, .bashrc, etc.). Default: true.',
                ],
                'include_dotenv_files' => [
                    'type' => 'boolean',
                    'description' => 'Whether to search .env files in project directories. Default: true.',
                ],
                'include_config_files' => [
                    'type' => 'boolean',
                    'description' => 'Whether to search common config file locations. Default: true.',
                ],
                'max_depth' => [
                    'type' => 'integer',
                    'description' => 'Max directory depth when searching for .env files. Default: 3.',
                ],
            ],
            'required' => ['search_terms'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $searchTerms = $input['search_terms'] ?? [];
        if (!is_array($searchTerms) || empty($searchTerms)) {
            return ToolResult::error('search_terms must be a non-empty array of strings.');
        }

        $extraLocations = $input['search_locations'] ?? [];
        $includeEnv = $input['include_env_vars'] ?? true;
        $includeProfiles = $input['include_shell_profiles'] ?? true;
        $includeDotenv = $input['include_dotenv_files'] ?? true;
        $includeConfig = $input['include_config_files'] ?? true;
        $maxDepth = (int) ($input['max_depth'] ?? 3);

        $results = [];
        $searchedLocations = [];

        // 1. Search current environment variables
        if ($includeEnv) {
            $envResults = $this->searchEnvironmentVariables($searchTerms);
            if (!empty($envResults)) {
                $results['environment_variables'] = $envResults;
            }
            $searchedLocations[] = 'environment variables';
        }

        // 2. Search shell profile files
        if ($includeProfiles) {
            $profileResults = $this->searchShellProfiles($searchTerms);
            if (!empty($profileResults)) {
                $results['shell_profiles'] = $profileResults;
            }
            $searchedLocations[] = 'shell profiles';
        }

        // 3. Search .env files
        if ($includeDotenv) {
            $dotenvResults = $this->searchDotenvFiles($searchTerms, $extraLocations, $maxDepth);
            if (!empty($dotenvResults)) {
                $results['dotenv_files'] = $dotenvResults;
            }
            $searchedLocations[] = '.env files';
        }

        // 4. Search config files
        if ($includeConfig) {
            $configResults = $this->searchConfigFiles($searchTerms);
            if (!empty($configResults)) {
                $results['config_files'] = $configResults;
            }
            $searchedLocations[] = 'config files';
        }

        // 5. Search macOS Keychain (if on macOS)
        if (PHP_OS_FAMILY === 'Darwin') {
            $keychainResults = $this->searchKeychain($searchTerms);
            if (!empty($keychainResults)) {
                $results['macos_keychain'] = $keychainResults;
            }
            $searchedLocations[] = 'macOS Keychain';
        }

        // Build a flat summary of found key=value pairs for easy consumption
        $foundKeys = $this->flattenResults($results);

        return ToolResult::success(json_encode([
            'found' => $foundKeys,
            'found_count' => count($foundKeys),
            'details' => $results,
            'searched' => $searchedLocations,
            'search_terms' => $searchTerms,
            'hint' => count($foundKeys) > 0
                ? 'Found credentials. Use store_keys to save them for future use.'
                : 'No credentials found. Use ask_user to request them from the user.',
        ]));
    }

    // -------------------------------------------------------------------------
    // Search strategies
    // -------------------------------------------------------------------------

    /**
     * Search current process environment variables.
     */
    private function searchEnvironmentVariables(array $searchTerms): array
    {
        $matches = [];
        $envVars = getenv();
        if (!is_array($envVars)) {
            return $matches;
        }

        foreach ($envVars as $key => $value) {
            if ($this->matchesAnyTerm($key, $searchTerms)) {
                $matches[] = [
                    'source' => 'env',
                    'key' => $key,
                    'value' => $value,
                    'preview' => $this->maskValue($value),
                ];
            }
        }

        return $matches;
    }

    /**
     * Search shell profile files for export statements.
     */
    private function searchShellProfiles(array $searchTerms): array
    {
        $profileFiles = [
            $this->homeDir . '/.zshrc',
            $this->homeDir . '/.bashrc',
            $this->homeDir . '/.bash_profile',
            $this->homeDir . '/.zprofile',
            $this->homeDir . '/.profile',
            $this->homeDir . '/.zshenv',
        ];

        $matches = [];
        foreach ($profileFiles as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $lineNum => $line) {
                $line = trim($line);

                // Skip comments
                if (str_starts_with($line, '#')) {
                    continue;
                }

                // Match export KEY=VALUE or KEY=VALUE patterns
                if (preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=["\']?(.+?)["\']?\s*$/', $line, $m)) {
                    $key = $m[1];
                    $value = $m[2];

                    if ($this->matchesAnyTerm($key, $searchTerms)) {
                        $matches[] = [
                            'source' => basename($file),
                            'file' => $file,
                            'line' => $lineNum + 1,
                            'key' => $key,
                            'value' => $value,
                            'preview' => $this->maskValue($value),
                        ];
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Search .env files in common project directories and any extra locations.
     */
    private function searchDotenvFiles(array $searchTerms, array $extraLocations, int $maxDepth): array
    {
        // Common directories to search for .env files
        $searchDirs = array_filter(array_merge([
            $this->homeDir,
            $this->homeDir . '/Code',
            $this->homeDir . '/Projects',
            $this->homeDir . '/Sites',
            $this->homeDir . '/code',
            $this->homeDir . '/projects',
            $this->homeDir . '/dev',
            $this->homeDir . '/Developer',
            $this->homeDir . '/workspace',
            $this->homeDir . '/src',
            getcwd(),
        ], $extraLocations), fn($dir) => is_dir($dir));

        // Deduplicate by realpath
        $seen = [];
        $uniqueDirs = [];
        foreach ($searchDirs as $dir) {
            $real = realpath($dir);
            if ($real !== false && !isset($seen[$real])) {
                $seen[$real] = true;
                $uniqueDirs[] = $real;
            }
        }

        $envFiles = $this->findEnvFiles($uniqueDirs, $maxDepth);
        $matches = [];

        foreach ($envFiles as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $lineNum => $line) {
                $line = trim($line);
                if (str_starts_with($line, '#') || $line === '') {
                    continue;
                }

                if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=["\']?(.+?)["\']?\s*$/', $line, $m)) {
                    $key = $m[1];
                    $value = $m[2];

                    if ($this->matchesAnyTerm($key, $searchTerms)) {
                        $matches[] = [
                            'source' => '.env',
                            'file' => $file,
                            'line' => $lineNum + 1,
                            'key' => $key,
                            'value' => $value,
                            'preview' => $this->maskValue($value),
                        ];
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Search common config file locations (npm, pip, composer, etc.).
     */
    private function searchConfigFiles(array $searchTerms): array
    {
        $configFiles = array_filter([
            $this->homeDir . '/.npmrc',
            $this->homeDir . '/.composer/auth.json',
            $this->homeDir . '/.config/composer/auth.json',
            $this->homeDir . '/.netrc',
            $this->homeDir . '/.gitconfig',
            $this->homeDir . '/.aws/credentials',
            $this->homeDir . '/.config/gh/hosts.yml',
            $this->homeDir . '/.docker/config.json',
        ], fn($f) => is_file($f) && is_readable($f));

        $matches = [];
        foreach ($configFiles as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            foreach ($searchTerms as $term) {
                if (stripos($content, $term) !== false) {
                    // Extract lines that match
                    $lines = explode("\n", $content);
                    foreach ($lines as $lineNum => $line) {
                        if (stripos($line, $term) !== false) {
                            // Try to extract key=value from the line
                            $extracted = $this->extractKeyValue($line);
                            $matches[] = [
                                'source' => 'config',
                                'file' => $file,
                                'line' => $lineNum + 1,
                                'key' => $extracted['key'] ?? $term,
                                'value' => $extracted['value'] ?? trim($line),
                                'preview' => $this->maskValue($extracted['value'] ?? trim($line)),
                                'raw_line' => $this->maskValue(trim($line)),
                            ];
                        }
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Search macOS Keychain for matching items.
     */
    private function searchKeychain(array $searchTerms): array
    {
        $matches = [];

        foreach ($searchTerms as $term) {
            // Use security command to search keychain
            $command = sprintf(
                'security find-generic-password -l %s -w 2>/dev/null',
                escapeshellarg($term)
            );
            $output = @shell_exec($command);

            if ($output !== null && trim($output) !== '') {
                $matches[] = [
                    'source' => 'keychain',
                    'key' => $term,
                    'value' => trim($output),
                    'preview' => $this->maskValue(trim($output)),
                ];
                continue;
            }

            // Try searching by account name
            $command = sprintf(
                'security find-generic-password -a %s -w 2>/dev/null',
                escapeshellarg($term)
            );
            $output = @shell_exec($command);

            if ($output !== null && trim($output) !== '') {
                $matches[] = [
                    'source' => 'keychain',
                    'key' => $term,
                    'value' => trim($output),
                    'preview' => $this->maskValue(trim($output)),
                ];
            }
        }

        return $matches;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Find .env files recursively up to maxDepth.
     */
    private function findEnvFiles(array $directories, int $maxDepth): array
    {
        $envFiles = [];

        foreach ($directories as $dir) {
            // Check for .env directly in the directory
            $envNames = ['.env', '.env.local', '.env.production', '.env.development'];
            foreach ($envNames as $envName) {
                $path = $dir . '/' . $envName;
                if (is_file($path) && is_readable($path)) {
                    $envFiles[] = $path;
                }
            }

            // Use find command to locate .env files in subdirectories
            if ($maxDepth > 0) {
                $command = sprintf(
                    'find %s -maxdepth %d -name ".env" -o -name ".env.local" -o -name ".env.production" 2>/dev/null | head -50',
                    escapeshellarg($dir),
                    $maxDepth
                );
                $output = @shell_exec($command);
                if ($output !== null) {
                    $files = array_filter(array_map('trim', explode("\n", $output)));
                    foreach ($files as $file) {
                        if (is_file($file) && is_readable($file) && !in_array($file, $envFiles, true)) {
                            $envFiles[] = $file;
                        }
                    }
                }
            }
        }

        return array_unique($envFiles);
    }

    /**
     * Check if a key name matches any of the search terms (case-insensitive).
     */
    private function matchesAnyTerm(string $key, array $searchTerms): bool
    {
        $keyLower = strtolower($key);
        foreach ($searchTerms as $term) {
            if (stripos($keyLower, strtolower($term)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Try to extract key=value from various formats (JSON, YAML, ini-style, etc.).
     */
    private function extractKeyValue(string $line): array
    {
        $line = trim($line);

        // KEY=VALUE
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=["\']?(.+?)["\']?\s*$/', $line, $m)) {
            return ['key' => $m[1], 'value' => $m[2]];
        }

        // "key": "value" (JSON)
        if (preg_match('/"([^"]+)"\s*:\s*"([^"]*)"/', $line, $m)) {
            return ['key' => $m[1], 'value' => $m[2]];
        }

        // key: value (YAML)
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*):\s*(.+)$/', $line, $m)) {
            return ['key' => $m[1], 'value' => trim($m[2], " \t\"'")];
        }

        return [];
    }

    /**
     * Mask a value for safe preview — show first 4 and last 2 chars.
     */
    private function maskValue(string $value): string
    {
        $len = strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($value, 0, 4) . str_repeat('*', min($len - 6, 20)) . substr($value, -2);
    }

    /**
     * Flatten all results into a simple key=>value map for easy consumption.
     * If the same key is found in multiple places, the first occurrence wins.
     */
    private function flattenResults(array $results): array
    {
        $flat = [];
        foreach ($results as $source => $entries) {
            foreach ($entries as $entry) {
                $key = $entry['key'] ?? '';
                if ($key !== '' && !isset($flat[$key])) {
                    $flat[$key] = [
                        'value' => $entry['value'],
                        'source' => $entry['source'] ?? $source,
                        'file' => $entry['file'] ?? null,
                    ];
                }
            }
        }
        return $flat;
    }

}
