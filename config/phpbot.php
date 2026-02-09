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

    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($item) => $item !== ''));
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
    | Apple Foundation Models (On-Device Intelligence)
    |--------------------------------------------------------------------------
    | When available (macOS 26+), Apple FM provides free, private, on-device
    | AI for classification, tool result summarization, and progress summaries.
    |
    | Tool result summarization intercepts large tool outputs (bash stdout,
    | file contents, etc.) and compresses them via Apple FM before sending
    | to Claude, dramatically reducing input tokens to the expensive LLM.
    |
    | Thresholds (in characters):
    |   skip_threshold:      Results smaller than this pass through unchanged (default 500)
    |   summarize_threshold: Results larger than this get summarized via Apple FM (default 800)
    |   Between thresholds:  Light PHP compression (no LLM call, microsecond latency)
    */
    'apple_fm' => [
        'enabled'              => (bool) $env('PHPBOT_APPLE_FM_ENABLED', true),
        'summarize_tool_results' => (bool) $env('PHPBOT_APPLE_FM_SUMMARIZE', true),
        'summarize_threshold'  => (int) $env('PHPBOT_APPLE_FM_SUMMARIZE_THRESHOLD', 800),
        'skip_threshold'       => (int) $env('PHPBOT_APPLE_FM_SKIP_THRESHOLD', 500),
        'summarize_progress'   => (bool) $env('PHPBOT_APPLE_FM_PROGRESS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Pricing (per million tokens)
    |--------------------------------------------------------------------------
    | Override the default per-million-token pricing for cost tracking.
    | Each entry is [input_cost, output_cost].
    | Only set the providers whose pricing you want to override;
    | unset providers keep their built-in defaults.
    |
    | Defaults (as of Feb 2026):
    |   Anthropic Haiku 4.5:  $1.00 / $5.00
    |   Anthropic Sonnet 4.5: $3.00 / $15.00
    |   Anthropic Opus 4.5:   $5.00 / $25.00
    |   Groq (70B Versatile):  $0.59 / $0.79
    |   Gemini 3 Flash:       $0.50 / $3.00
    |   Local providers:      $0.00 / $0.00
    */
    'pricing' => array_filter([
        'anthropic_haiku'  => $env('PHPBOT_PRICE_ANTHROPIC_HAIKU', false)
            ? array_map('floatval', explode(',', $env('PHPBOT_PRICE_ANTHROPIC_HAIKU')))
            : null,
        'anthropic_sonnet' => $env('PHPBOT_PRICE_ANTHROPIC_SONNET', false)
            ? array_map('floatval', explode(',', $env('PHPBOT_PRICE_ANTHROPIC_SONNET')))
            : null,
        'anthropic_opus'   => $env('PHPBOT_PRICE_ANTHROPIC_OPUS', false)
            ? array_map('floatval', explode(',', $env('PHPBOT_PRICE_ANTHROPIC_OPUS')))
            : null,
        'groq'             => $env('PHPBOT_PRICE_GROQ', false)
            ? array_map('floatval', explode(',', $env('PHPBOT_PRICE_GROQ')))
            : null,
        'gemini'           => $env('PHPBOT_PRICE_GEMINI', false)
            ? array_map('floatval', explode(',', $env('PHPBOT_PRICE_GEMINI')))
            : null,
    ], static fn($v) => $v !== null),

    /*
    |--------------------------------------------------------------------------
    | Cross-Vendor Dynamic Model Fusion (DMF)
    |--------------------------------------------------------------------------
    | Enables Claude to orchestrate other LLM vendors (OpenAI, Gemini) as
    | callable tools during agent execution. API keys are loaded from the
    | KeyStore (storage/keys.json) and/or environment variables.
    |
    | Available tools when keys are set:
    |   OPENAI_API_KEY  -> openai_web_search, openai_image_generation, openai_text_to_speech
    |   GEMINI_API_KEY  -> gemini_grounding, gemini_code_execution, gemini_image_generation
    |   Both            -> vendor_chat (cross-vendor model delegation)
    |
    | Keys can be stored via: store_keys tool, keys.json, or env variables.
    */
    'vendor_tools_enabled' => (bool) $env('PHPBOT_VENDOR_TOOLS_ENABLED', true),
    'vendor_configs' => [
        'openai' => array_filter([
            'default_chat_model'  => $env('PHPBOT_OPENAI_CHAT_MODEL', null),
            'default_image_model' => $env('PHPBOT_OPENAI_IMAGE_MODEL', null),
            'default_tts_model'   => $env('PHPBOT_OPENAI_TTS_MODEL', null),
            'timeout'             => $env('PHPBOT_OPENAI_TIMEOUT', null),
        ], static fn($v) => $v !== null),
        'google' => array_filter([
            'default_chat_model'  => $env('PHPBOT_GEMINI_CHAT_MODEL', null),
            'default_image_model' => $env('PHPBOT_GEMINI_IMAGE_MODEL', null),
            'timeout'             => $env('PHPBOT_GEMINI_TIMEOUT', null),
        ], static fn($v) => $v !== null),
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
    | Logging
    |--------------------------------------------------------------------------
    | Enable file logging for each run (written to storage/logs/).
    | When enabled, every CLI and web API run creates a timestamped log file
    | with progress events, internal decisions, and results.
    */
    'log_enabled' => (bool) $env('PHPBOT_LOG_ENABLED', false),
    'log_path' => $env('PHPBOT_LOG_PATH', dirname(__DIR__) . '/storage/logs'),

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
    | Listener (Event Watcher)
    |--------------------------------------------------------------------------
    | Watches for new messages, emails, calendar events, and notifications
    | then routes actionable items to the bot for automated handling.
    |
    | poll_interval: seconds between each poll cycle (min: 10)
    | watchers: which sources to monitor (mail, calendar, messages, notifications)
    | state_path: where to persist watcher watermarks between restarts
    */
    'listener' => [
        'enabled' => (bool) $env('PHPBOT_LISTENER_ENABLED', true),
        'poll_interval' => (int) $env('PHPBOT_LISTENER_POLL_INTERVAL', 30),
        'watchers' => $envList('PHPBOT_LISTENER_WATCHERS', ['mail', 'calendar', 'messages', 'notifications']),
        'state_path' => $env('PHPBOT_LISTENER_STATE_PATH', dirname(__DIR__) . '/storage/listener-state.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler (Task Runner)
    |--------------------------------------------------------------------------
    | Runs scheduled tasks on a per-minute tick. Tasks can be one-time,
    | recurring (cron expression), or interval-based.
    |
    | tick_interval: seconds between each scheduler tick (min: 30)
    | tasks_path: where to persist scheduled tasks
    */
    'scheduler' => [
        'enabled' => (bool) $env('PHPBOT_SCHEDULER_ENABLED', true),
        'tick_interval' => (int) $env('PHPBOT_SCHEDULER_TICK_INTERVAL', 60),
        'tasks_path' => $env('PHPBOT_SCHEDULER_TASKS_PATH', dirname(__DIR__) . '/storage/scheduler/tasks.json'),
    ],

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
