<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\CLI;

use Dalehurley\Phpbot\Storage\KeyStore;

class SetupWizard
{
    /** @var callable(string): void */
    private $output;

    /** @var callable(string): string|false */
    private $prompt;

    private string $projectRoot;
    private KeyStore $keyStore;

    /** Collected settings during wizard run */
    private string $anthropicKey = '';
    private string $openaiKey = '';
    private string $geminiKey = '';
    private string $groqKey = '';
    private string $classifierProvider = 'auto';

    public function __construct(callable $output, callable $prompt, string $projectRoot, KeyStore $keyStore)
    {
        $this->output = $output;
        $this->prompt = $prompt;
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->keyStore = $keyStore;
    }

    /**
     * Run the full setup wizard.
     *
     * @return bool true if setup completed successfully
     */
    public function run(): bool
    {
        $this->stepWelcome();

        if (!$this->stepAnthropicKey()) {
            $this->out("\n  Setup cancelled.\n\n");
            return false;
        }

        $this->stepOptionalKeys();
        $this->stepClassifierProvider();
        $this->stepWriteEnv();
        $this->stepSummary();

        return true;
    }

    // -------------------------------------------------------------------------
    // Step 1: Welcome
    // -------------------------------------------------------------------------

    private function stepWelcome(): void
    {
        $this->out("\n");
        $this->out("╔══════════════════════════════════════════════════════════╗\n");
        $this->out("║                    PhpBot Setup                          ║\n");
        $this->out("╚══════════════════════════════════════════════════════════╝\n");
        $this->out("\n");
        $this->out("  This wizard will configure your environment and API keys.\n");
        $this->out("  Press Enter to accept defaults shown in [brackets].\n");

        $envPath = $this->projectRoot . '/.env';
        if (is_file($envPath)) {
            $this->out("\n");
            $this->out("  ⚠️  An existing .env file was found.\n");
            $this->out("  Existing values will be shown as defaults.\n");
        }

        $this->out("\n");
    }

    // -------------------------------------------------------------------------
    // Step 2: Anthropic API Key (required)
    // -------------------------------------------------------------------------

    private function stepAnthropicKey(): bool
    {
        $this->out("─── Step 1: Anthropic API Key (required) ──────────────────\n\n");
        $this->out("  Claude is the core LLM that powers PhpBot.\n");
        $this->out("  Get your key at: https://console.anthropic.com/settings/keys\n\n");

        $existing = $this->resolveExistingKey('anthropic_api_key', 'ANTHROPIC_API_KEY');

        $attempts = 0;
        while ($attempts < 3) {
            $defaultHint = $existing !== '' ? ' [' . $this->maskKey($existing) . ']' : '';
            $input = $this->ask("  Anthropic API key{$defaultHint}: ");

            if ($input === false) {
                return false;
            }

            $input = trim($input);

            // Accept default
            if ($input === '' && $existing !== '') {
                $this->anthropicKey = $existing;
                $this->out("  ✓ Using existing key.\n\n");
                return true;
            }

            if ($input === '') {
                $this->out("  ✗ API key is required. Please enter your key.\n\n");
                $attempts++;
                continue;
            }

            // Validate format
            if (!str_starts_with($input, 'sk-ant-')) {
                $this->out("  ⚠️  Key doesn't start with 'sk-ant-'. Are you sure? (y/n) ");
                $confirm = $this->ask('');
                if ($confirm === false || strtolower(trim($confirm)) !== 'y') {
                    $attempts++;
                    continue;
                }
            }

            // Test the key
            $this->out("  ⏳ Verifying key...");
            $error = $this->testAnthropicKey($input);
            if ($error !== null) {
                $this->out(" ✗\n");
                $this->out("  ⚠️  Verification failed: {$error}\n");
                $confirm = $this->ask("  Save anyway? (y/n) ");
                if ($confirm === false || strtolower(trim($confirm)) !== 'y') {
                    $attempts++;
                    $this->out("\n");
                    continue;
                }
            } else {
                $this->out(" ✓\n");
            }

            $this->anthropicKey = $input;
            $this->keyStore->set('anthropic_api_key', $input);
            $this->out("  ✓ Anthropic API key saved.\n\n");
            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Step 3: Optional API Keys
    // -------------------------------------------------------------------------

    private function stepOptionalKeys(): void
    {
        $this->out("─── Step 2: Optional API Keys ──────────────────────────────\n\n");
        $this->out("  These keys enable additional tools and cheaper routing.\n");
        $this->out("  Press Enter to skip any you don't have.\n\n");

        // OpenAI
        $this->out("  OpenAI (enables web search, image generation, text-to-speech)\n");
        $this->out("  Get key at: https://platform.openai.com/api-keys\n");
        $this->openaiKey = $this->askOptionalKey('openai_api_key', 'OPENAI_API_KEY', 'OpenAI API key');

        // Gemini
        $this->out("  Google Gemini (enables grounding, code execution, image generation)\n");
        $this->out("  Get key at: https://aistudio.google.com/apikey\n");
        $this->geminiKey = $this->askOptionalKey('gemini_api_key', 'GEMINI_API_KEY', 'Gemini API key');

        // Groq
        $this->out("  Groq (free cloud classifier — reduces Claude token usage)\n");
        $this->out("  Get key at: https://console.groq.com/keys\n");
        $this->groqKey = $this->askOptionalKey('groq_api_key', 'GROQ_API_KEY', 'Groq API key');
    }

    private function askOptionalKey(string $keyStoreKey, string $envVar, string $label): string
    {
        $existing = $this->resolveExistingKey($keyStoreKey, $envVar);
        $defaultHint = $existing !== '' ? ' [' . $this->maskKey($existing) . ']' : '';
        $input = $this->ask("  {$label}{$defaultHint}: ");

        if ($input === false) {
            $input = '';
        }

        $input = trim($input);

        if ($input === '' && $existing !== '') {
            $this->out("  ✓ Using existing key.\n\n");
            return $existing;
        }

        if ($input === '') {
            $this->out("  – Skipped.\n\n");
            return '';
        }

        // Test the key
        $this->out("  ⏳ Verifying key...");
        $error = $this->testKey($keyStoreKey, $input);
        if ($error !== null) {
            $this->out(" ✗\n");
            $this->out("  ⚠️  Verification failed: {$error}\n");
            $confirm = $this->ask("  Save anyway? (y/n) ");
            if ($confirm === false || strtolower(trim($confirm)) !== 'y') {
                $this->out("  – Skipped.\n\n");
                return '';
            }
        } else {
            $this->out(" ✓\n");
        }

        $this->keyStore->set($keyStoreKey, $input);
        $this->out("  ✓ Saved.\n\n");
        return $input;
    }

    // -------------------------------------------------------------------------
    // Step 4: Classifier Provider
    // -------------------------------------------------------------------------

    private function stepClassifierProvider(): void
    {
        $this->out("─── Step 3: Classifier Provider ────────────────────────────\n\n");
        $this->out("  The classifier routes requests through cheaper methods before\n");
        $this->out("  falling back to Claude, saving you money.\n\n");

        $options = [
            '1' => ['auto',      'Auto-detect best available (recommended)'],
            '2' => ['apple_fm',  'Apple Intelligence (macOS 26+, on-device, free)'],
            '3' => ['ollama',    'Ollama (local, free) — requires: brew install ollama'],
            '4' => ['groq',      'Groq (cloud, free tier)' . ($this->groqKey !== '' ? '' : ' — requires API key')],
            '5' => ['gemini',    'Gemini (cloud, cheap)' . ($this->geminiKey !== '' ? '' : ' — requires API key')],
            '6' => ['anthropic', 'Anthropic Haiku (always works, costs tokens)'],
        ];

        // Determine current default
        $currentProvider = $this->resolveExistingEnvValue('PHPBOT_CLASSIFIER_PROVIDER', 'auto');
        $currentNum = '1';
        foreach ($options as $num => [$value, $desc]) {
            if ($value === $currentProvider) {
                $currentNum = $num;
                break;
            }
        }

        foreach ($options as $num => [$value, $desc]) {
            $marker = ($num === $currentNum) ? ' ← current' : '';
            $this->out("    {$num}. {$desc}{$marker}\n");
        }

        $this->out("\n");
        $input = $this->ask("  Choose classifier [{$currentNum}]: ");

        if ($input === false) {
            $input = '';
        }

        $input = trim($input);
        if ($input === '') {
            $input = $currentNum;
        }

        if (isset($options[$input])) {
            $this->classifierProvider = $options[$input][0];
            $this->out("  ✓ Classifier set to: {$this->classifierProvider}\n\n");
        } else {
            $this->classifierProvider = 'auto';
            $this->out("  ✓ Invalid selection, defaulting to: auto\n\n");
        }
    }

    // -------------------------------------------------------------------------
    // Step 5: Write .env
    // -------------------------------------------------------------------------

    private function stepWriteEnv(): void
    {
        $this->out("─── Step 4: Writing Configuration ──────────────────────────\n\n");

        $envPath = $this->projectRoot . '/.env';

        // Auto-detect paths
        $toolsPath = $this->projectRoot . '/storage/tools';
        $skillsPath = $this->projectRoot . '/skills';
        $keysPath = $this->projectRoot . '/storage/keys.json';
        $workingDir = getcwd() ?: $this->projectRoot;

        // Build .env content
        $lines = [];
        $lines[] = '# PhpBot Configuration';
        $lines[] = '# Generated by /setup on ' . date('Y-m-d H:i:s');
        $lines[] = '# Re-run /setup at any time to reconfigure.';
        $lines[] = '';

        // API key reference (actual key is in keys.json, but .env needs it for boot)
        $lines[] = '# Required: Anthropic API Key';
        $lines[] = '# The key is also stored in keys.json for tool access.';
        $lines[] = 'ANTHROPIC_API_KEY=' . $this->anthropicKey;
        $lines[] = '';

        // Models
        $lines[] = '# Models';
        $lines[] = 'PHPBOT_FAST_MODEL=' . $this->resolveExistingEnvValue('PHPBOT_FAST_MODEL', 'claude-haiku-4-5');
        $lines[] = 'PHPBOT_MODEL=' . $this->resolveExistingEnvValue('PHPBOT_MODEL', 'claude-sonnet-4-5');
        $lines[] = 'PHPBOT_SUPER_MODEL=' . $this->resolveExistingEnvValue('PHPBOT_SUPER_MODEL', 'claude-opus-4-5');
        $lines[] = '';

        // Limits
        $lines[] = '# Limits';
        $lines[] = 'PHPBOT_MAX_ITERATIONS=' . $this->resolveExistingEnvValue('PHPBOT_MAX_ITERATIONS', '20');
        $lines[] = 'PHPBOT_MAX_TOKENS=' . $this->resolveExistingEnvValue('PHPBOT_MAX_TOKENS', '4096');
        $lines[] = 'PHPBOT_TEMPERATURE=' . $this->resolveExistingEnvValue('PHPBOT_TEMPERATURE', '0.7');
        $lines[] = 'PHPBOT_TIMEOUT=' . $this->resolveExistingEnvValue('PHPBOT_TIMEOUT', '120');
        $lines[] = '';

        // WebSocket
        $lines[] = '# WebSocket';
        $lines[] = 'PHPBOT_WS_PORT=' . $this->resolveExistingEnvValue('PHPBOT_WS_PORT', '8788');
        $lines[] = 'PHPBOT_WS_UDP_PORT=' . $this->resolveExistingEnvValue('PHPBOT_WS_UDP_PORT', '8789');
        $lines[] = '';

        // Paths
        $lines[] = '# Paths';
        $lines[] = 'PHPBOT_TOOLS_STORAGE_PATH=' . $this->resolveExistingEnvValue('PHPBOT_TOOLS_STORAGE_PATH', $toolsPath);
        $lines[] = 'PHPBOT_SKILLS_PATH=' . $this->resolveExistingEnvValue('PHPBOT_SKILLS_PATH', $skillsPath);
        $lines[] = 'PHPBOT_KEYS_STORAGE_PATH=' . $this->resolveExistingEnvValue('PHPBOT_KEYS_STORAGE_PATH', $keysPath);
        $lines[] = 'PHPBOT_WORKING_DIRECTORY=' . $this->resolveExistingEnvValue('PHPBOT_WORKING_DIRECTORY', $workingDir);
        $lines[] = '';

        // Classifier
        $lines[] = '# Router Classifier';
        $lines[] = '# Provider: auto, apple_fm, mlx, ollama, lmstudio, groq, gemini, anthropic';
        $lines[] = 'PHPBOT_CLASSIFIER_PROVIDER=' . $this->classifierProvider;
        $lines[] = '';

        // Apple FM
        $lines[] = '# Apple Foundation Models (macOS 26+)';
        $lines[] = 'PHPBOT_APPLE_FM_ENABLED=' . $this->resolveExistingEnvValue('PHPBOT_APPLE_FM_ENABLED', 'true');
        $lines[] = 'PHPBOT_APPLE_FM_SUMMARIZE=' . $this->resolveExistingEnvValue('PHPBOT_APPLE_FM_SUMMARIZE', 'true');
        $lines[] = 'PHPBOT_APPLE_FM_SUMMARIZE_THRESHOLD=' . $this->resolveExistingEnvValue('PHPBOT_APPLE_FM_SUMMARIZE_THRESHOLD', '800');
        $lines[] = 'PHPBOT_APPLE_FM_SKIP_THRESHOLD=' . $this->resolveExistingEnvValue('PHPBOT_APPLE_FM_SKIP_THRESHOLD', '500');
        $lines[] = 'PHPBOT_APPLE_FM_PROGRESS=' . $this->resolveExistingEnvValue('PHPBOT_APPLE_FM_PROGRESS', 'true');
        $lines[] = '';

        // Optional cloud classifier keys (in .env for config loading)
        if ($this->groqKey !== '') {
            $lines[] = '# Groq (classifier)';
            $lines[] = 'GROQ_API_KEY=' . $this->groqKey;
            $lines[] = 'PHPBOT_CLASSIFIER_GROQ_MODEL=' . $this->resolveExistingEnvValue('PHPBOT_CLASSIFIER_GROQ_MODEL', 'llama-3.3-70b-versatile');
            $lines[] = '';
        }

        if ($this->geminiKey !== '') {
            $lines[] = '# Google Gemini (classifier + tools)';
            $lines[] = 'GEMINI_API_KEY=' . $this->geminiKey;
            $lines[] = 'PHPBOT_CLASSIFIER_GEMINI_MODEL=' . $this->resolveExistingEnvValue('PHPBOT_CLASSIFIER_GEMINI_MODEL', 'gemini-3-flash-preview');
            $lines[] = '';
        }

        // Safety
        $lines[] = '# Safety';
        $lines[] = 'PHPBOT_BLOCKED_COMMANDS=' . $this->resolveExistingEnvValue(
            'PHPBOT_BLOCKED_COMMANDS',
            '["rm -rf /","rm -rf /*","mkfs","dd if=","(){:|:&};:","> /dev/sda","chmod -R 777 /"]'
        );
        $lines[] = 'PHPBOT_ALLOWED_COMMANDS=';
        $lines[] = '';

        $content = implode("\n", $lines);
        file_put_contents($envPath, $content);

        $this->out("  ✓ .env written to: {$envPath}\n");
        $this->out("  ✓ API keys stored in: {$keysPath}\n\n");
    }

    // -------------------------------------------------------------------------
    // Step 6: Summary
    // -------------------------------------------------------------------------

    private function stepSummary(): void
    {
        $this->out("─── Setup Complete ─────────────────────────────────────────\n\n");

        $this->out("  Services configured:\n\n");

        // Anthropic (always configured at this point)
        $this->out("    ✅ Anthropic Claude     — Core LLM\n");

        // OpenAI
        if ($this->openaiKey !== '') {
            $this->out("    ✅ OpenAI              — Web search, image gen, TTS\n");
        } else {
            $this->out("    ○  OpenAI              — Not configured (optional)\n");
        }

        // Gemini
        if ($this->geminiKey !== '') {
            $this->out("    ✅ Google Gemini       — Grounding, code exec, image gen\n");
        } else {
            $this->out("    ○  Google Gemini       — Not configured (optional)\n");
        }

        // Groq
        if ($this->groqKey !== '') {
            $this->out("    ✅ Groq               — Free classifier\n");
        } else {
            $this->out("    ○  Groq               — Not configured (optional)\n");
        }

        // Classifier
        $this->out("\n    Classifier: {$this->classifierProvider}\n");

        $this->out("\n");
        $this->out("  You can re-run this wizard anytime with /setup\n");
        $this->out("  or manually edit .env and storage/keys.json.\n");
        $this->out("\n");
    }

    // -------------------------------------------------------------------------
    // API key validation
    // -------------------------------------------------------------------------

    /**
     * Route key validation to the appropriate test method.
     *
     * @return string|null Error message, or null if valid
     */
    private function testKey(string $keyStoreKey, string $apiKey): ?string
    {
        return match ($keyStoreKey) {
            'openai_api_key' => $this->testOpenAiKey($apiKey),
            'gemini_api_key' => $this->testGeminiKey($apiKey),
            'groq_api_key'   => $this->testGroqKey($apiKey),
            default          => null,
        };
    }

    /**
     * Test Anthropic key with a minimal messages API call (1 max_token).
     *
     * @return string|null Error message, or null if valid
     */
    private function testAnthropicKey(string $apiKey): ?string
    {
        $body = json_encode([
            'model' => 'claude-haiku-4-5',
            'max_tokens' => 1,
            'messages' => [
                ['role' => 'user', 'content' => 'Hi'],
            ],
        ]);

        $response = $this->httpRequest('POST', 'https://api.anthropic.com/v1/messages', $body, [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ]);

        if ($response === null) {
            return 'Connection failed (network error or timeout)';
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            return $data['error']['message'] ?? 'Unknown API error';
        }

        if (isset($data['id'])) {
            return null; // Success
        }

        return 'Unexpected response';
    }

    /**
     * Test OpenAI key with a GET /v1/models call (free, no tokens used).
     *
     * @return string|null Error message, or null if valid
     */
    private function testOpenAiKey(string $apiKey): ?string
    {
        $response = $this->httpRequest('GET', 'https://api.openai.com/v1/models', null, [
            'Authorization: Bearer ' . $apiKey,
        ]);

        if ($response === null) {
            return 'Connection failed (network error or timeout)';
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            return $data['error']['message'] ?? 'Invalid API key';
        }

        if (isset($data['data']) && is_array($data['data'])) {
            return null; // Success
        }

        return 'Unexpected response';
    }

    /**
     * Test Gemini key with a minimal generateContent call (1 max_token).
     *
     * @return string|null Error message, or null if valid
     */
    private function testGeminiKey(string $apiKey): ?string
    {
        $model = 'gemini-2.0-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $body = json_encode([
            'contents' => [
                ['parts' => [['text' => 'Hi']]],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 1,
            ],
        ]);

        $response = $this->httpRequest('POST', $url, $body, [
            'Content-Type: application/json',
        ]);

        if ($response === null) {
            return 'Connection failed (network error or timeout)';
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            return $data['error']['message'] ?? 'Invalid API key';
        }

        if (isset($data['candidates'])) {
            return null; // Success
        }

        return 'Unexpected response';
    }

    /**
     * Test Groq key with a minimal chat completion call (1 max_token).
     *
     * @return string|null Error message, or null if valid
     */
    private function testGroqKey(string $apiKey): ?string
    {
        $body = json_encode([
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [
                ['role' => 'user', 'content' => 'Hi'],
            ],
            'max_tokens' => 1,
        ]);

        $response = $this->httpRequest('POST', 'https://api.groq.com/openai/v1/chat/completions', $body, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);

        if ($response === null) {
            return 'Connection failed (network error or timeout)';
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            return $data['error']['message'] ?? 'Invalid API key';
        }

        if (isset($data['choices'])) {
            return null; // Success
        }

        return 'Unexpected response';
    }

    /**
     * Make an HTTP request with a short timeout.
     *
     * @return string|null Response body, or null on failure
     */
    private function httpRequest(string $method, string $url, ?string $body, array $headers, int $timeout = 10): ?string
    {
        $httpOptions = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
        ];

        if ($body !== null) {
            $httpOptions['content'] = $body;
        }

        $context = stream_context_create([
            'http' => $httpOptions,
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve an existing key from KeyStore or environment variable.
     */
    private function resolveExistingKey(string $keyStoreKey, string $envVar): string
    {
        // Check KeyStore first
        $stored = $this->keyStore->get($keyStoreKey);
        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        // Check environment variable
        $envValue = getenv($envVar);
        if (is_string($envValue) && $envValue !== '' && $envValue !== 'YOUR-ANTHROPIC-API-KEY-HERE') {
            return $envValue;
        }

        return '';
    }

    /**
     * Resolve an existing .env value or return default.
     */
    private function resolveExistingEnvValue(string $envVar, string $default): string
    {
        $value = getenv($envVar);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $default;
    }

    /**
     * Mask an API key for display, showing first 7 and last 4 characters.
     */
    private function maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 11) {
            return str_repeat('*', $len);
        }

        return substr($key, 0, 7) . str_repeat('*', $len - 11) . substr($key, -4);
    }

    private function out(string $message): void
    {
        ($this->output)($message);
    }

    /**
     * @return string|false
     */
    private function ask(string $promptText): string|false
    {
        return ($this->prompt)($promptText);
    }
}
