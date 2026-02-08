<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Apple;

use Dalehurley\Phpbot\Stats\TokenLedger;

/**
 * General-purpose Apple Foundation Models client.
 *
 * Wraps the compiled Swift CLI bridge that talks to the on-device
 * Apple Intelligence model via the FoundationModels framework (macOS 26+).
 *
 * Capabilities:
 * - General text completion (any prompt)
 * - Summarization (with context)
 * - Classification
 *
 * All calls are free (on-device), private, and work offline.
 * Token usage is estimated (chars/4) since Apple FM doesn't report tokens.
 */
class AppleFMClient implements SmallModelClient
{
    /** Resolved binary path (cached after first check). */
    private ?string $binaryPath = null;

    /** Whether we've already attempted resolution. */
    private bool $resolved = false;

    /** Optional logging callback. */
    private ?\Closure $logger = null;

    public function __construct(
        private string $binDir,
        private ?TokenLedger $ledger = null,
    ) {}

    /**
     * Set an optional logger.
     */
    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * General-purpose text completion.
     *
     * @param string $prompt The prompt to send
     * @param int $maxTokens Maximum tokens for the response
     * @param string $purpose Purpose label for the token ledger
     * @param string|null $instructions Optional system instructions for the session
     * @return string The model's text response
     * @throws \RuntimeException if Apple FM is not available
     */
    public function call(string $prompt, int $maxTokens = 512, string $purpose = 'general', ?string $instructions = null): string
    {
        $binary = $this->resolveBinary();

        if ($binary === null) {
            throw new \RuntimeException('Apple FM binary not available');
        }

        // Truncate very large prompts to fit Apple FM's 4096 token context window.
        // Keep ~3200 tokens (12800 chars) for input, leaving room for output.
        $maxInputChars = 12800;
        if (strlen($prompt) > $maxInputChars) {
            $prompt = substr($prompt, 0, $maxInputChars) . "\n[... truncated ...]";
        }

        $payload = [
            'prompt' => $prompt,
            'max_tokens' => $maxTokens,
        ];

        if ($instructions !== null) {
            $payload['instructions'] = $instructions;
        }

        $inputJson = json_encode($payload);
        $response = $this->executeProcess($binary, $inputJson);

        // Record in ledger (estimate tokens as chars/4)
        $inputTokens = (int) ceil(strlen($prompt) / 4);
        $outputTokens = (int) ceil(strlen($response) / 4);
        $this->ledger?->record('apple_fm', $purpose, $inputTokens, $outputTokens);

        return $response;
    }

    /**
     * Summarize content with context about what it is and why it matters.
     *
     * Uses session instructions to tell the model its role as a summarizer,
     * then sends the content as the prompt. This gives better results than
     * putting everything in the prompt because Apple FM's session instructions
     * guide the model's behavior more effectively.
     *
     * @param string $content The content to summarize
     * @param string $context Context about the content (tool name, purpose, etc.)
     * @param int $maxTokens Maximum tokens for the summary
     * @return string The summarized content
     */
    public function summarize(string $content, string $context, int $maxTokens = 256): string
    {
        $instructions = 'You are a concise summarizer for an AI assistant. '
            . 'Preserve: error codes, exit codes, file paths, key data values, structural information, counts, sizes. '
            . 'Remove: repetitive content, verbose formatting, redundant whitespace, decorative text. '
            . 'Output only the summary, no preamble.';

        $prompt = "Context: {$context}\n\nContent (" . strlen($content) . " chars):\n{$content}";

        return $this->call($prompt, $maxTokens, 'summarization', $instructions);
    }

    /**
     * Classify a request using session instructions for better quality.
     */
    public function classify(string $prompt, int $maxTokens = 256): string
    {
        $instructions = 'You are a task classifier. Respond with only valid JSON. '
            . 'Do not include any text before or after the JSON.';

        return $this->call($prompt, $maxTokens, 'classification', $instructions);
    }

    // -------------------------------------------------------------------------
    // Availability
    // -------------------------------------------------------------------------

    /**
     * Check if Apple FM is available on this system.
     *
     * Requires macOS 26+ (Darwin 25+) and either a compiled binary
     * or the Swift source + swiftc to compile on first use.
     */
    public function isAvailable(): bool
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        $darwinVersion = php_uname('r');
        $majorVersion = (int) explode('.', $darwinVersion)[0];

        if ($majorVersion < 25) {
            return false;
        }

        return $this->resolveBinary() !== null;
    }

    // -------------------------------------------------------------------------
    // Binary resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the Apple FM binary path, compiling from source if needed.
     * Result is cached after first call.
     */
    public function resolveBinary(): ?string
    {
        if ($this->resolved) {
            return $this->binaryPath;
        }

        $this->resolved = true;
        $binary = $this->binDir . '/apple-fm-classify';
        $source = $binary . '.swift';

        // Use pre-compiled binary
        if (is_file($binary) && is_executable($binary)) {
            $this->binaryPath = $binary;

            return $this->binaryPath;
        }

        // Try to compile from source
        if (!is_file($source)) {
            return null;
        }

        $swiftc = trim((string) shell_exec('which swiftc 2>/dev/null'));

        if ($swiftc === '') {
            return null;
        }

        $cmd = escapeshellarg($swiftc)
            . ' -parse-as-library -framework FoundationModels -O'
            . ' -o ' . escapeshellarg($binary)
            . ' ' . escapeshellarg($source)
            . ' 2>&1';

        $output = shell_exec($cmd);
        $this->log('Apple FM compile: ' . ($output ?: 'OK'));

        if (is_file($binary) && is_executable($binary)) {
            $this->binaryPath = $binary;

            return $this->binaryPath;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Process execution
    // -------------------------------------------------------------------------

    /**
     * Execute the Apple FM binary with JSON input on stdin, return text content.
     *
     * @throws \RuntimeException on process errors
     */
    private function executeProcess(string $binary, string $inputJson): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($binary, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start Apple FM process');
        }

        fwrite($pipes[0], $inputJson);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Apple FM returned error: ' . ($stderr ?: 'unknown'));
        }

        $data = json_decode($stdout, true);

        return $data['content'] ?? '';
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
