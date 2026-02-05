<?php

declare(strict_types=1);

/**
 * PhpBot Configuration
 * 
 * Copy this file to config/phpbot.php and customize as needed.
 * Environment variables will override these settings.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Anthropic API Key
    |--------------------------------------------------------------------------
    | Your API key for the Anthropic Claude API.
    | Can also be set via ANTHROPIC_API_KEY environment variable.
    */
    'api_key' => getenv('ANTHROPIC_API_KEY') ?: '',

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    | The Claude model to use for the agent.
    | Options: claude-haiku-4-5 claude-sonnet-4-5, claude-opus-4-5
    */
    'fast_model' => 'claude-haiku-4-5',
    'model' => 'claude-sonnet-4-5',
    'super_model' => 'claude-opus-4-5',

    /*
    |--------------------------------------------------------------------------
    | Max Iterations
    |--------------------------------------------------------------------------
    | Maximum number of iterations the agent can perform before stopping.
    */
    'max_iterations' => 20,

    /*
    |--------------------------------------------------------------------------
    | Max Tokens
    |--------------------------------------------------------------------------
    | Maximum tokens for each response.
    */
    'max_tokens' => 4096,

    /*
    |--------------------------------------------------------------------------
    | Temperature
    |--------------------------------------------------------------------------
    | Temperature for response generation (0.0 - 1.0).
    | Lower = more deterministic, Higher = more creative.
    */
    'temperature' => 0.7,

    /*
    |--------------------------------------------------------------------------
    | Tools Storage Path
    |--------------------------------------------------------------------------
    | Path where custom tools are persisted.
    */
    'tools_storage_path' => dirname(__DIR__) . '/storage/tools',

    /*
    |--------------------------------------------------------------------------
    | Skills Path
    |--------------------------------------------------------------------------
    | Directory where agent skills (SKILL.md) are stored.
    */
    'skills_path' => dirname(__DIR__) . '/skills',

    /*
    |--------------------------------------------------------------------------
    | Keys Storage Path
    |--------------------------------------------------------------------------
    | Path to JSON file for storing API keys.
    */
    'keys_storage_path' => dirname(__DIR__) . '/storage/keys.json',

    /*
    |--------------------------------------------------------------------------
    | Abilities Storage Path
    |--------------------------------------------------------------------------
    | Path where learned abilities are persisted.
    | The bot logs problem-solving patterns it discovers during execution
    | and retrieves relevant ones before future tasks.
    */
    'abilities_storage_path' => dirname(__DIR__) . '/storage/abilities',

    /*
    |--------------------------------------------------------------------------
    | Working Directory
    |--------------------------------------------------------------------------
    | Default working directory for bash commands.
    */
    'working_directory' => getcwd(),

    /*
    |--------------------------------------------------------------------------
    | Blocked Commands
    |--------------------------------------------------------------------------
    | Bash commands that are blocked for safety.
    */
    'blocked_commands' => [
        'rm -rf /',
        'rm -rf /*',
        'mkfs',
        'dd if=',
        ':(){:|:&};:',
        '> /dev/sda',
        'chmod -R 777 /',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Commands (Whitelist)
    |--------------------------------------------------------------------------
    | If not empty, only these command prefixes are allowed.
    | Leave empty to allow all commands except blocked ones.
    */
    'allowed_commands' => [],
];
