<?php

declare(strict_types=1);

/**
 * PhpBot Configuration
 * 
 * Copy this file to config/phpbot.php and customize as needed.
 * Environment variables will override these settings.
 */

// Env helpers (string, int, float, list)
$env = static function (string $key, mixed $default = null): mixed {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
};

$envList = static function (string $key, array $default = []): array {
    $value = getenv($key);
    if ($value === false || trim($value) === '') {
        return $default;
    }

    // Allow JSON arrays for complex entries.
    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($item) => $item !== ''));
};

return [
    /*
    |--------------------------------------------------------------------------
    | Anthropic API Key
    |--------------------------------------------------------------------------
    | Your API key for the Anthropic Claude API.
    | Can also be set via ANTHROPIC_API_KEY environment variable.
    */
    'api_key' => $env('ANTHROPIC_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    | The Claude model to use for the agent.
    | Options: claude-haiku-4-5 claude-sonnet-4-5, claude-opus-4-5
    */
    'fast_model' => $env('PHPBOT_FAST_MODEL', 'claude-haiku-4-5'),
    'model' => $env('PHPBOT_MODEL', 'claude-sonnet-4-5'),
    'super_model' => $env('PHPBOT_SUPER_MODEL', 'claude-opus-4-5'),

    /*
    |--------------------------------------------------------------------------
    | Max Iterations
    |--------------------------------------------------------------------------
    | Maximum number of iterations the agent can perform before stopping.
    */
    'max_iterations' => (int) $env('PHPBOT_MAX_ITERATIONS', 20),

    /*
    |--------------------------------------------------------------------------
    | Max Tokens
    |--------------------------------------------------------------------------
    | Maximum tokens for each response.
    */
    'max_tokens' => (int) $env('PHPBOT_MAX_TOKENS', 4096),

    /*
    |--------------------------------------------------------------------------
    | Temperature
    |--------------------------------------------------------------------------
    | Temperature for response generation (0.0 - 1.0).
    | Lower = more deterministic, Higher = more creative.
    */
    'temperature' => (float) $env('PHPBOT_TEMPERATURE', 0.7),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    | Timeout for API requests to Anthropic (seconds).
    */
    'timeout' => (float) $env('PHPBOT_TIMEOUT', 120.0),

    /*
    |--------------------------------------------------------------------------
    | Tools Storage Path
    |--------------------------------------------------------------------------
    | Path where custom tools are persisted.
    */
    'tools_storage_path' => $env('PHPBOT_TOOLS_STORAGE_PATH', dirname(__DIR__) . '/storage/tools'),

    /*
    |--------------------------------------------------------------------------
    | Skills Path
    |--------------------------------------------------------------------------
    | Directory where agent skills (SKILL.md) are stored.
    */
    'skills_path' => $env('PHPBOT_SKILLS_PATH', dirname(__DIR__) . '/skills'),

    /*
    |--------------------------------------------------------------------------
    | Keys Storage Path
    |--------------------------------------------------------------------------
    | Path to JSON file for storing API keys.
    */
    'keys_storage_path' => $env('PHPBOT_KEYS_STORAGE_PATH', dirname(__DIR__) . '/storage/keys.json'),

    /*
    |--------------------------------------------------------------------------
    | Working Directory
    |--------------------------------------------------------------------------
    | Default working directory for bash commands.
    */
    'working_directory' => $env('PHPBOT_WORKING_DIRECTORY', getcwd()),

    /*
    |--------------------------------------------------------------------------
    | Blocked Commands
    |--------------------------------------------------------------------------
    | Bash commands that are blocked for safety.
    */
    'blocked_commands' => $envList('PHPBOT_BLOCKED_COMMANDS', [
        'rm -rf /',
        'rm -rf /*',
        'mkfs',
        'dd if=',
        ':(){:|:&};:',
        '> /dev/sda',
        'chmod -R 777 /',
    ]),

    /*
    |--------------------------------------------------------------------------
    | Allowed Commands (Whitelist)
    |--------------------------------------------------------------------------
    | If not empty, only these command prefixes are allowed.
    | Leave empty to allow all commands except blocked ones.
    */
    'allowed_commands' => $envList('PHPBOT_ALLOWED_COMMANDS', []),
];
