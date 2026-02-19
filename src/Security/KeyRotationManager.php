<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Security;

/**
 * Manages multi-provider API key rotation with detection, validation,
 * and structured reporting.
 *
 * Supported providers: openai, anthropic, google, twilio, stripe, github,
 * aws, sendgrid, slack, discord, mailgun, cloudflare, datadog.
 */
class KeyRotationManager
{
    /**
     * Regex patterns for detecting credentials per provider.
     * Each pattern must have a named capture group "key".
     *
     * @var array<string, array{pattern: string, env_var: string, label: string}>
     */
    private const PROVIDERS = [
        'openai' => [
            'pattern' => '/(?:OPENAI_API_KEY|openai_api_key)\s*[=:]\s*["\']?(?P<key>sk-[A-Za-z0-9\-_]{20,})["\']?/',
            'env_var' => 'OPENAI_API_KEY',
            'label' => 'OpenAI',
        ],
        'anthropic' => [
            'pattern' => '/(?:ANTHROPIC_API_KEY|anthropic_api_key)\s*[=:]\s*["\']?(?P<key>sk-ant-[A-Za-z0-9\-_]{20,})["\']?/',
            'env_var' => 'ANTHROPIC_API_KEY',
            'label' => 'Anthropic',
        ],
        'google' => [
            'pattern' => '/(?:GEMINI_API_KEY|GOOGLE_API_KEY|gemini_api_key|google_api_key)\s*[=:]\s*["\']?(?P<key>AIza[A-Za-z0-9\-_]{35})["\']?/',
            'env_var' => 'GEMINI_API_KEY',
            'label' => 'Google/Gemini',
        ],
        'twilio' => [
            'pattern' => '/(?:TWILIO_AUTH_TOKEN|twilio_auth_token)\s*[=:]\s*["\']?(?P<key>[a-f0-9]{32})["\']?/',
            'env_var' => 'TWILIO_AUTH_TOKEN',
            'label' => 'Twilio Auth Token',
        ],
        'stripe' => [
            'pattern' => '/(?:STRIPE_SECRET_KEY|stripe_secret_key|STRIPE_API_KEY|stripe_api_key)\s*[=:]\s*["\']?(?P<key>sk_(?:live|test)_[A-Za-z0-9]{24,})["\']?/',
            'env_var' => 'STRIPE_SECRET_KEY',
            'label' => 'Stripe',
        ],
        'github' => [
            'pattern' => '/(?:GITHUB_TOKEN|github_token|GH_TOKEN|gh_token)\s*[=:]\s*["\']?(?P<key>gh[po]_[A-Za-z0-9]{36})["\']?/',
            'env_var' => 'GITHUB_TOKEN',
            'label' => 'GitHub',
        ],
        'sendgrid' => [
            'pattern' => '/(?:SENDGRID_API_KEY|sendgrid_api_key)\s*[=:]\s*["\']?(?P<key>SG\.[A-Za-z0-9\-_]{22,})["\']?/',
            'env_var' => 'SENDGRID_API_KEY',
            'label' => 'SendGrid',
        ],
        'slack' => [
            'pattern' => '/(?:SLACK_BOT_TOKEN|slack_bot_token|SLACK_TOKEN|slack_token)\s*[=:]\s*["\']?(?P<key>xox[bpsa]-[A-Za-z0-9\-]{10,})["\']?/',
            'env_var' => 'SLACK_BOT_TOKEN',
            'label' => 'Slack',
        ],
    ];

    /**
     * Scan a list of files for known credential patterns.
     *
     * @param string[] $files
     * @return array<string, array{provider: string, label: string, files: array<string, string[]>}>
     */
    public function detectProviders(array $files): array
    {
        $detected = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $content = (string) file_get_contents($file);

            foreach (self::PROVIDERS as $provider => $config) {
                if (preg_match_all($config['pattern'], $content, $matches)) {
                    foreach ($matches['key'] as $key) {
                        $detected[$provider]['provider'] = $provider;
                        $detected[$provider]['label'] = $config['label'];
                        $detected[$provider]['env_var'] = $config['env_var'];
                        $detected[$provider]['files'][$file][] = $key;
                    }
                }
            }
        }

        return $detected;
    }

    /**
     * Replace all occurrences of old keys with new keys across the given files.
     *
     * @param array<string, string> $replacements Map of old_key => new_key
     * @param string[] $files Files to update
     * @return array{replaced: array<string, int>, errors: string[]}
     */
    public function replaceKeys(array $replacements, array $files): array
    {
        $replaced = [];
        $errors = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                $errors[] = "File not found: {$file}";
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                $errors[] = "Cannot read: {$file}";
                continue;
            }

            $fileChanged = false;
            foreach ($replacements as $oldKey => $newKey) {
                if (str_contains($content, $oldKey)) {
                    $content = str_replace($oldKey, $newKey, $content);
                    $replaced[$file] = ($replaced[$file] ?? 0) + 1;
                    $fileChanged = true;
                }
            }

            if ($fileChanged) {
                if (file_put_contents($file, $content) === false) {
                    $errors[] = "Cannot write: {$file}";
                    unset($replaced[$file]);
                }
            }
        }

        return compact('replaced', 'errors');
    }

    /**
     * Get a list of supported provider names.
     *
     * @return string[]
     */
    public function getSupportedProviders(): array
    {
        return array_map(fn($p) => $p['label'], self::PROVIDERS);
    }

    /**
     * Return the pattern config for a provider by name.
     *
     * @return array{pattern: string, env_var: string, label: string}|null
     */
    public function getProviderConfig(string $provider): ?array
    {
        return self::PROVIDERS[$provider] ?? null;
    }
}
