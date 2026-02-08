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
    | Higher values allow more complex tasks but consume more API tokens.
    */
    'max_iterations' => (int) $env('PHPBOT_MAX_ITERATIONS', 25),

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
    'timeout' => (float) $env('PHPBOT_TIMEOUT', 300.0),

    /*
    |--------------------------------------------------------------------------
    | Router Classifier
    |--------------------------------------------------------------------------
    | Controls which LLM provider classifies requests that don't match the
    | local cache. Most requests are handled by the PHP native TF-IDF
    | classifier (zero tokens). Only ambiguous requests fall through to
    | the LLM classifier cascade:
    |
    | Auto-detection priority:
    |   1. Apple Foundation Models (macOS 26+, on-device, free)
    |   2. MLX server (Apple Silicon, tiny model, free)
    |   3. Ollama (local, free)
    |   4. LM Studio (local, free)
    |   5. Groq (cloud, free tier)
    |   6. Google Gemini (cloud, very cheap)
    |   7. Anthropic Haiku (cloud, always available)
    |
    | Provider options: auto, apple_fm, mlx, ollama, lmstudio, groq, gemini, anthropic
    |
    | Setup guides:
    |   Apple FM:  Requires macOS 26+ with Apple Intelligence. Auto-compiles Swift bridge.
    |   MLX:       pip install mlx-lm && python bin/mlx-classify-server.py
    |   Ollama:    brew install ollama && ollama pull qwen2.5:1.5b
    |   LM Studio: https://lmstudio.ai — load any model, enable local server
    |   Groq:      https://console.groq.com — free tier, get API key
    |   Gemini:    https://aistudio.google.com — get API key
    */
    'classifier_provider' => $env('PHPBOT_CLASSIFIER_PROVIDER', 'auto'),
    'classifier' => [
        'mlx_url'        => $env('PHPBOT_CLASSIFIER_MLX_URL', 'http://localhost:5127'),
        'groq_api_key'   => $env('GROQ_API_KEY', ''),
        'groq_model'     => $env('PHPBOT_CLASSIFIER_GROQ_MODEL', 'llama-3.3-70b-versatile'),
        'gemini_api_key' => $env('GEMINI_API_KEY', ''),
        'gemini_model'   => $env('PHPBOT_CLASSIFIER_GEMINI_MODEL', 'gemini-3-flash-preview'),
        'ollama_url'     => $env('PHPBOT_CLASSIFIER_OLLAMA_URL', 'http://localhost:11434'),
        'ollama_model'   => $env('PHPBOT_CLASSIFIER_OLLAMA_MODEL', 'qwen2.5:1.5b'),
        'lmstudio_url'   => $env('PHPBOT_CLASSIFIER_LMSTUDIO_URL', 'http://localhost:1234'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale Loop Detection
    |--------------------------------------------------------------------------
    | Controls when the agent is stopped for being stuck in a loop.
    | max_errors: consecutive tool errors before halting
    | max_empty: consecutive empty tool calls before halting
    | max_repeated: consecutive identical tool calls before halting
    */
    'stale_loop_max_errors' => (int) $env('PHPBOT_STALE_LOOP_MAX_ERRORS', 5),
    'stale_loop_max_empty' => (int) $env('PHPBOT_STALE_LOOP_MAX_EMPTY', 3),
    'stale_loop_max_repeated' => (int) $env('PHPBOT_STALE_LOOP_MAX_REPEATED', 4),

    /*
    |--------------------------------------------------------------------------
    | Bash Output Limits
    |--------------------------------------------------------------------------
    | Maximum characters for bash stdout/stderr to prevent context explosion.
    | Large outputs are truncated to first+last portions to keep context manageable.
    */
    'bash_max_output_chars' => (int) $env('PHPBOT_BASH_MAX_OUTPUT', 15000),

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
