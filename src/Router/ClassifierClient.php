<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Router;

use ClaudeAgents\Agent;
use Dalehurley\Phpbot\Apple\SmallModelClient;
use Dalehurley\Phpbot\Stats\TokenLedger;

/**
 * Provider-agnostic LLM client for classification.
 *
 * Supports 7 backends with auto-detection priority:
 * 1. Apple Foundation Models (macOS 26+, on-device, free)
 * 2. MLX (Apple Silicon, tiny model on Metal/GPU, free)
 * 3. Ollama (local, free)
 * 4. LM Studio (local, free)
 * 5. Groq (cloud, free tier)
 * 6. Google Gemini (cloud, very cheap)
 * 7. Anthropic Haiku (cloud, always available as fallback)
 *
 * All non-Anthropic providers use simple HTTP POST — no SDK needed.
 */
class ClassifierClient
{
    private const PROVIDER_APPLE_FM = 'apple_fm';
    private const PROVIDER_MLX = 'mlx';
    private const PROVIDER_OLLAMA = 'ollama';
    private const PROVIDER_LMSTUDIO = 'lmstudio';
    private const PROVIDER_GROQ = 'groq';
    private const PROVIDER_GEMINI = 'gemini';
    private const PROVIDER_ANTHROPIC = 'anthropic';

    /** Resolved provider (cached after first detection). */
    private ?string $resolvedProvider = null;

    /** Optional logging callback: fn(string $message): void */
    private ?\Closure $logger = null;

    /** Optional small model client (Apple FM or Haiku fallback). */
    private ?SmallModelClient $appleFM = null;

    /** Optional token ledger for recording usage. */
    private ?TokenLedger $ledger = null;

    /**
     * @param array $config The 'classifier' config array from phpbot.php
     * @param string $providerOverride The 'classifier_provider' value ('auto' or explicit)
     * @param \Closure(): \ClaudePhp\ClaudePhp $clientFactory Anthropic client factory (fallback)
     * @param string $fastModel Anthropic fast model name (for fallback)
     */
    public function __construct(
        private array $config,
        private string $providerOverride,
        private \Closure $clientFactory,
        private string $fastModel,
    ) {}

    /**
     * Set a small model client (Apple FM or Haiku) to delegate calls to.
     */
    public function setAppleFMClient(?SmallModelClient $appleFM): void
    {
        $this->appleFM = $appleFM;
    }

    /**
     * Set a token ledger for recording usage.
     */
    public function setTokenLedger(?TokenLedger $ledger): void
    {
        $this->ledger = $ledger;
    }

    /**
     * Set an optional logger for provider selection messages.
     */
    public function setLogger(\Closure $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Send a classification prompt and return the raw text response.
     *
     * @param string $prompt The classification prompt
     * @param int $maxTokens Maximum tokens for the response
     * @return string Raw text response from the LLM
     */
    public function classify(string $prompt, int $maxTokens = 256): string
    {
        $provider = $this->resolveProvider();

        return match ($provider) {
            self::PROVIDER_APPLE_FM => $this->callAppleFM($prompt, $maxTokens),
            self::PROVIDER_MLX => $this->callMlx($prompt, $maxTokens),
            self::PROVIDER_OLLAMA => $this->callOllama($prompt, $maxTokens),
            self::PROVIDER_LMSTUDIO => $this->callLmStudio($prompt, $maxTokens),
            self::PROVIDER_GROQ => $this->callGroq($prompt, $maxTokens),
            self::PROVIDER_GEMINI => $this->callGemini($prompt, $maxTokens),
            default => $this->callAnthropic($prompt, $maxTokens),
        };
    }

    /**
     * Get the resolved provider name.
     */
    public function getProvider(): string
    {
        return $this->resolveProvider();
    }

    /**
     * Check if the resolved provider is reachable.
     */
    public function isAvailable(): bool
    {
        try {
            $provider = $this->resolveProvider();

            return match ($provider) {
                self::PROVIDER_APPLE_FM => $this->isAppleFMAvailable(),
                self::PROVIDER_MLX => $this->isLocalServerReachable($this->config['mlx_url'] ?? 'http://localhost:5127'),
                self::PROVIDER_OLLAMA => $this->isLocalServerReachable($this->config['ollama_url'] ?? 'http://localhost:11434'),
                self::PROVIDER_LMSTUDIO => $this->isLocalServerReachable($this->config['lmstudio_url'] ?? 'http://localhost:1234'),
                self::PROVIDER_GROQ => !empty($this->config['groq_api_key'] ?? ''),
                self::PROVIDER_GEMINI => !empty($this->config['gemini_api_key'] ?? ''),
                self::PROVIDER_ANTHROPIC => true,
                default => true,
            };
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Provider resolution
    // -------------------------------------------------------------------------

    private function resolveProvider(): string
    {
        if ($this->resolvedProvider !== null) {
            return $this->resolvedProvider;
        }

        if ($this->providerOverride !== 'auto') {
            $this->resolvedProvider = $this->providerOverride;
            $this->log("Classifier provider: {$this->resolvedProvider} (explicit)");

            return $this->resolvedProvider;
        }

        // Auto-detect priority chain
        // Priority 1: Apple Foundation Models (on-device, zero cost)
        if ($this->isAppleFMAvailable()) {
            $this->resolvedProvider = self::PROVIDER_APPLE_FM;
            $this->log('Classifier provider: apple_fm (on-device Foundation Models)');

            return $this->resolvedProvider;
        }

        // Priority 2: MLX server (local, tiny model on Metal/GPU)
        $mlxUrl = $this->config['mlx_url'] ?? 'http://localhost:5127';
        if ($this->isLocalServerReachable($mlxUrl)) {
            $this->resolvedProvider = self::PROVIDER_MLX;
            $this->log("Classifier provider: mlx ({$mlxUrl})");

            return $this->resolvedProvider;
        }

        // Priority 3: Ollama (local, free)
        $ollamaUrl = $this->config['ollama_url'] ?? 'http://localhost:11434';
        if ($this->isLocalServerReachable($ollamaUrl)) {
            $this->resolvedProvider = self::PROVIDER_OLLAMA;
            $model = $this->config['ollama_model'] ?? 'qwen2.5:1.5b';
            $this->log("Classifier provider: ollama ({$model} at {$ollamaUrl})");

            return $this->resolvedProvider;
        }

        // Priority 4: LM Studio (local, free)
        $lmstudioUrl = $this->config['lmstudio_url'] ?? 'http://localhost:1234';
        if ($this->isLocalServerReachable($lmstudioUrl)) {
            $this->resolvedProvider = self::PROVIDER_LMSTUDIO;
            $this->log("Classifier provider: lmstudio ({$lmstudioUrl})");

            return $this->resolvedProvider;
        }

        $groqKey = $this->config['groq_api_key'] ?? '';
        if ($groqKey !== '') {
            $this->resolvedProvider = self::PROVIDER_GROQ;
            $model = $this->config['groq_model'] ?? 'llama-3.3-70b-versatile';
            $this->log("Classifier provider: groq ({$model})");

            return $this->resolvedProvider;
        }

        $geminiKey = $this->config['gemini_api_key'] ?? '';
        if ($geminiKey !== '') {
            $this->resolvedProvider = self::PROVIDER_GEMINI;
            $model = $this->config['gemini_model'] ?? 'gemini-3-flash-preview';
            $this->log("Classifier provider: gemini ({$model})");

            return $this->resolvedProvider;
        }

        $this->resolvedProvider = self::PROVIDER_ANTHROPIC;
        $this->log("Classifier provider: anthropic ({$this->fastModel})");

        return $this->resolvedProvider;
    }

    // -------------------------------------------------------------------------
    // Provider implementations
    // -------------------------------------------------------------------------

    /**
     * Ollama: POST /api/generate
     */
    private function callOllama(string $prompt, int $maxTokens): string
    {
        $url = rtrim($this->config['ollama_url'] ?? 'http://localhost:11434', '/') . '/api/generate';
        $model = $this->config['ollama_model'] ?? 'qwen2.5:1.5b';

        $body = json_encode([
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'format' => 'json',
            'options' => [
                'num_predict' => $maxTokens,
                'temperature' => 0.1,
            ],
        ]);

        $response = $this->httpPost($url, $body, [
            'Content-Type: application/json',
        ], 30);

        $data = json_decode($response, true);

        return $data['response'] ?? '';
    }

    /**
     * LM Studio: POST /v1/chat/completions (OpenAI-compatible)
     */
    private function callLmStudio(string $prompt, int $maxTokens): string
    {
        $url = rtrim($this->config['lmstudio_url'] ?? 'http://localhost:1234', '/') . '/v1/chat/completions';

        $body = json_encode([
            'messages' => [
                ['role' => 'system', 'content' => 'You are a task classifier. Respond with only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object'],
        ]);

        $response = $this->httpPost($url, $body, [
            'Content-Type: application/json',
        ], 30);

        $data = json_decode($response, true);

        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Groq: POST /openai/v1/chat/completions
     */
    private function callGroq(string $prompt, int $maxTokens): string
    {
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $apiKey = $this->config['groq_api_key'] ?? '';
        $model = $this->config['groq_model'] ?? 'llama-3.3-70b-versatile';

        $body = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a task classifier. Respond with only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object'],
        ]);

        $response = $this->httpPost($url, $body, [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
        ], 15);

        $data = json_decode($response, true);

        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Google Gemini: POST /v1beta/models/{model}:generateContent
     */
    private function callGemini(string $prompt, int $maxTokens): string
    {
        $apiKey = $this->config['gemini_api_key'] ?? '';
        $model = $this->config['gemini_model'] ?? 'gemini-3-flash-preview';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $body = json_encode([
            'contents' => [
                [
                    'parts' => [
                        ['text' => "You are a task classifier. Respond with only valid JSON.\n\n{$prompt}"],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ]);

        $response = $this->httpPost($url, $body, [
            'Content-Type: application/json',
        ], 15);

        $data = json_decode($response, true);

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    /**
     * Apple Foundation Models: delegate to the shared AppleFMClient.
     *
     * Uses the general-purpose AppleFMClient for on-device classification.
     */
    private function callAppleFM(string $prompt, int $maxTokens): string
    {
        if ($this->appleFM === null) {
            throw new \RuntimeException('Apple FM client not configured');
        }

        return $this->appleFM->classify($prompt, $maxTokens);
    }

    /**
     * MLX: POST /classify to the local MLX server.
     */
    private function callMlx(string $prompt, int $maxTokens): string
    {
        $url = rtrim($this->config['mlx_url'] ?? 'http://localhost:5127', '/') . '/classify';

        $body = json_encode([
            'prompt' => $prompt,
            'max_tokens' => $maxTokens,
        ]);

        $response = $this->httpPost($url, $body, [
            'Content-Type: application/json',
        ], 30);

        $data = json_decode($response, true);

        return $data['content'] ?? '';
    }

    /**
     * Anthropic: Use the existing Agent framework (fallback).
     */
    private function callAnthropic(string $prompt, int $maxTokens): string
    {
        $client = ($this->clientFactory)();

        $agent = Agent::create($client)
            ->withName('router_classifier')
            ->withSystemPrompt('You are a task classifier. Respond with only valid JSON.')
            ->withModel($this->fastModel)
            ->maxIterations(1)
            ->maxTokens($maxTokens);

        $result = $agent->run($prompt);

        // Record Anthropic classification usage in ledger
        $tokens = $result->getTokenUsage();
        $this->ledger?->record(
            'anthropic',
            'classification',
            $tokens['input'] ?? 0,
            $tokens['output'] ?? 0,
        );

        return $result->getAnswer();
    }

    // -------------------------------------------------------------------------
    // Apple FM helpers
    // -------------------------------------------------------------------------

    /**
     * Check if the small model client (Apple FM or Haiku fallback) is available.
     *
     * Delegates to the shared SmallModelClient when configured.
     */
    private function isAppleFMAvailable(): bool
    {
        if ($this->appleFM !== null) {
            return $this->appleFM->isAvailable();
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Check if a local server is reachable within a timeout.
     */
    private function isLocalServerReachable(string $url): bool
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 0.5,
                    'ignore_errors' => true,
                ],
            ]);

            $response = @file_get_contents($url, false, $context);

            // Any response (including error pages) means the server is running
            return $response !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Simple HTTP POST with error handling.
     *
     * @param string $url Target URL
     * @param string $body JSON body
     * @param string[] $headers HTTP headers
     * @param int $timeout Request timeout in seconds
     * @return string Response body
     * @throws \RuntimeException on HTTP errors
     */
    private function httpPost(string $url, string $body, array $headers, int $timeout): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \RuntimeException("HTTP POST to {$url} failed");
        }

        return $response;
    }

    /**
     * Log a message via the optional logger.
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
