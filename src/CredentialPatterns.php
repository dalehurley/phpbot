<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

/**
 * Centralised credential detection and sanitisation.
 *
 * Every pattern lives here so that SkillAutoCreator (and anything else that
 * needs to scrub secrets) shares a single source of truth.
 *
 * Each detection entry returns:
 *   type           – short identifier (e.g. "twilio_account_sid")
 *   value          – the matched literal
 *   placeholder    – the {{PLACEHOLDER}} to substitute
 *   key_store_key  – the key-store name for retrieval (nullable)
 *
 * Each stripping entry is a [regex, replacement] pair applied in order.
 */
class CredentialPatterns
{
    // =========================================================================
    // Detection Patterns
    // =========================================================================
    //
    // Grouped by provider / category.  Each entry is a callable that receives
    // the full text and a reference to the $found array + $knownValues set.
    //
    // We use closures so complex multi-step matches (e.g. Twilio SID:token)
    // work naturally without shoe-horning into a flat [regex, …] table.
    // =========================================================================

    /**
     * Scan arbitrary text and return all detected credentials.
     *
     * @return list<array{type: string, value: string, placeholder: string, key_store_key: ?string}>
     */
    public static function detect(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $found = [];
        $knownValues = [];

        foreach (self::detectors() as $detector) {
            $detector($text, $found, $knownValues);
        }

        return $found;
    }

    /**
     * Strip every recognised credential / PII pattern from text, replacing
     * with safe placeholders.  Also normalises user-specific filesystem paths.
     */
    public static function strip(string $text): string
    {
        if ($text === '') {
            return '';
        }

        foreach (self::strippingRules() as [$pattern, $replacement]) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * Convenience: detect credentials inside a collection of tool calls and
     * combine with recipe text.
     *
     * @param  array  $toolCalls  Raw tool-call array from the agent result.
     * @return list<array{type: string, value: string, placeholder: string, key_store_key: ?string}>
     */
    public static function detectFromToolCalls(string $recipe, array $toolCalls): array
    {
        $allText = $recipe;

        foreach ($toolCalls as $call) {
            $input = $call['input'] ?? [];
            $allText .= "\n" . (is_string($input) ? $input : json_encode($input));

            $output = $call['output'] ?? '';
            if (is_string($output)) {
                $allText .= "\n" . $output;
            }
        }

        return self::detect($allText);
    }

    /**
     * Human-readable description for a credential type.
     */
    public static function describeType(string $type): string
    {
        return self::DESCRIPTIONS[$type]
            ?? ucfirst(str_replace('_', ' ', $type));
    }

    // =========================================================================
    // Credential type → human description map
    // =========================================================================

    private const DESCRIPTIONS = [
        // Twilio
        'twilio_account_sid'     => 'Twilio Account SID (starts with AC)',
        'twilio_auth_token'      => 'Twilio Auth Token',
        'from_phone'             => 'Twilio phone number (sender)',
        'to_phone'               => 'Recipient phone number',
        // AWS
        'aws_access_key'         => 'AWS Access Key ID (starts with AKIA)',
        'aws_secret_access_key'  => 'AWS Secret Access Key',
        'aws_session_token'      => 'AWS temporary session token',
        // GCP
        'gcp_api_key'            => 'Google Cloud API key',
        'gcp_service_account'    => 'GCP service-account private key ID',
        'firebase_api_key'       => 'Firebase API key',
        // Azure
        'azure_subscription_key' => 'Azure subscription key',
        'azure_storage_key'      => 'Azure Storage account key',
        'azure_connection_string' => 'Azure connection string',
        // OpenAI / AI providers
        'openai_api_key'         => 'OpenAI API key (starts with sk-)',
        'anthropic_api_key'      => 'Anthropic API key (starts with sk-ant-)',
        'huggingface_token'      => 'Hugging Face access token (starts with hf_)',
        'cohere_api_key'         => 'Cohere API key',
        'replicate_api_token'    => 'Replicate API token (starts with r8_)',
        // Payment
        'stripe_secret_key'      => 'Stripe secret key (starts with sk_live_ / sk_test_)',
        'stripe_publishable_key' => 'Stripe publishable key (starts with pk_live_ / pk_test_)',
        'stripe_webhook_secret'  => 'Stripe webhook signing secret (starts with whsec_)',
        'paypal_client_id'       => 'PayPal client ID',
        'paypal_client_secret'   => 'PayPal client secret',
        'square_access_token'    => 'Square access token',
        'braintree_access_token' => 'Braintree access token',
        // Communication / Email
        'sendgrid_api_key'       => 'SendGrid API key (starts with SG.)',
        'mailgun_api_key'        => 'Mailgun API key',
        'mailchimp_api_key'      => 'Mailchimp API key',
        'postmark_server_token'  => 'Postmark server API token',
        'sparkpost_api_key'      => 'SparkPost API key',
        // Messaging / Social
        'slack_token'            => 'Slack bot / OAuth token (starts with xoxb- / xoxp-)',
        'slack_webhook'          => 'Slack incoming webhook URL',
        'discord_token'          => 'Discord bot token',
        'discord_webhook'        => 'Discord webhook URL',
        'telegram_bot_token'     => 'Telegram Bot API token',
        // Version control / DevOps
        'github_token'           => 'GitHub personal access token',
        'github_app_key'         => 'GitHub App private key',
        'gitlab_token'           => 'GitLab personal / project access token',
        'bitbucket_app_password' => 'Bitbucket app password',
        'heroku_api_key'         => 'Heroku API key',
        'vercel_token'           => 'Vercel access token',
        'netlify_token'          => 'Netlify access token',
        'docker_hub_token'       => 'Docker Hub access token',
        'circleci_token'         => 'CircleCI API token',
        'travis_token'           => 'Travis CI token',
        'npm_token'              => 'npm authentication token',
        'pypi_token'             => 'PyPI API token (starts with pypi-)',
        // Databases
        'database_url'           => 'Database connection URL (may contain password)',
        'redis_url'              => 'Redis connection URL (may contain password)',
        'mongodb_uri'            => 'MongoDB connection URI',
        // Crypto / secrets
        'jwt_token'              => 'JSON Web Token',
        'private_key'            => 'PEM-encoded private key',
        'ssh_private_key'        => 'SSH private key',
        // Generic
        'bearer_token'           => 'API Bearer token',
        'api_token'              => 'API authentication token',
        'basic_auth'             => 'HTTP Basic Auth credentials in URL',
        // PII
        'phone_number'           => 'Phone number',
        'email_address'          => 'E-mail address (potential PII)',
        'ip_address'             => 'IP address',
        'ssn'                    => 'Social Security Number',
        'credit_card'            => 'Credit card number',
    ];

    // =========================================================================
    // Detector closures
    // =========================================================================

    /**
     * @return list<\Closure>
     */
    private static function detectors(): array
    {
        return [
            // -----------------------------------------------------------------
            // Twilio
            // -----------------------------------------------------------------
            self::simpleDetector(
                '#(AC[0-9a-f]{32})#i',
                'twilio_account_sid',
                '{{TWILIO_ACCOUNT_SID}}',
                'twilio_account_sid'
            ),
            function (string $text, array &$found, array &$known): void {
                if (preg_match('#(?:AC[0-9a-f]{32}):([0-9a-f]{32})#i', $text, $m)) {
                    self::addIfNew($found, $known, 'twilio_auth_token', $m[1], '{{TWILIO_AUTH_TOKEN}}', 'twilio_auth_token');
                }
            },
            // Twilio phone numbers (From / To parameters)
            function (string $text, array &$found, array &$known): void {
                if (preg_match_all('#(?:From|To)[="\s]+(\+\d{10,15})#i', $text, $matches)) {
                    foreach ($matches[1] as $phone) {
                        $isFrom = (bool) preg_match('#From[="\s]+' . preg_quote($phone, '#') . '#i', $text);
                        $type = $isFrom ? 'from_phone' : 'to_phone';
                        $placeholder = $isFrom ? '{{FROM_PHONE_NUMBER}}' : '{{TO_PHONE_NUMBER}}';
                        $keyStoreKey = $isFrom ? 'twilio_phone_number' : null;
                        self::addIfNew($found, $known, $type, $phone, $placeholder, $keyStoreKey);
                    }
                }
            },

            // -----------------------------------------------------------------
            // AWS
            // -----------------------------------------------------------------
            self::simpleDetector(
                '#(AKIA[0-9A-Z]{16})#',
                'aws_access_key',
                '{{AWS_ACCESS_KEY_ID}}',
                'aws_access_key_id'
            ),
            self::kvDetector(
                '#(?:aws_secret_access_key|aws_secret|secret_key)["\s:=]+["\']?([A-Za-z0-9/+=]{40})["\']?#i',
                'aws_secret_access_key',
                '{{AWS_SECRET_ACCESS_KEY}}',
                'aws_secret_access_key'
            ),
            self::kvDetector(
                '#(?:aws_session_token|session_token)["\s:=]+["\']?([A-Za-z0-9/+=]{100,})["\']?#i',
                'aws_session_token',
                '{{AWS_SESSION_TOKEN}}',
                'aws_session_token'
            ),

            // -----------------------------------------------------------------
            // GCP / Firebase  (Firebase first — more specific context guard)
            // -----------------------------------------------------------------
            self::simpleDetector(
                '#(AIza[0-9A-Za-z\-_]{35})#',
                'firebase_api_key',
                '{{FIREBASE_API_KEY}}',
                'firebase_api_key',
                '#firebase#i'  // context guard
            ),
            self::simpleDetector(
                '#(AIza[0-9A-Za-z\-_]{35})#',
                'gcp_api_key',
                '{{GCP_API_KEY}}',
                'gcp_api_key'
            ),

            // -----------------------------------------------------------------
            // Azure
            // -----------------------------------------------------------------
            self::kvDetector(
                '#(?:Ocp-Apim-Subscription-Key|subscription[_-]?key)["\s:=]+["\']?([0-9a-f]{32})["\']?#i',
                'azure_subscription_key',
                '{{AZURE_SUBSCRIPTION_KEY}}',
                'azure_subscription_key'
            ),
            function (string $text, array &$found, array &$known): void {
                if (preg_match('#(DefaultEndpointsProtocol=https?;AccountName=[^;]+;AccountKey=[A-Za-z0-9+/=]{44,};[^\s"\']+)#', $text, $m)) {
                    self::addIfNew($found, $known, 'azure_connection_string', $m[1], '{{AZURE_CONNECTION_STRING}}', 'azure_connection_string');
                }
            },

            // -----------------------------------------------------------------
            // OpenAI / Anthropic / AI providers
            // -----------------------------------------------------------------
            self::simpleDetector(
                '#(sk-[A-Za-z0-9]{20,})#',
                'openai_api_key',
                '{{OPENAI_API_KEY}}',
                'openai_api_key',
                '#(?:openai|OPENAI|open_ai|gpt|chatgpt)#i'
            ),
            self::simpleDetector(
                '#(sk-ant-[A-Za-z0-9\-]{20,})#',
                'anthropic_api_key',
                '{{ANTHROPIC_API_KEY}}',
                'anthropic_api_key'
            ),
            self::simpleDetector(
                '#(hf_[A-Za-z0-9]{20,})#',
                'huggingface_token',
                '{{HUGGINGFACE_TOKEN}}',
                'huggingface_token'
            ),
            self::simpleDetector(
                '#(r8_[A-Za-z0-9]{20,})#',
                'replicate_api_token',
                '{{REPLICATE_API_TOKEN}}',
                'replicate_api_token'
            ),
            self::kvDetector(
                '#(?:cohere[_-]?api[_-]?key)["\s:=]+["\']?([A-Za-z0-9]{20,})["\']?#i',
                'cohere_api_key',
                '{{COHERE_API_KEY}}',
                'cohere_api_key'
            ),

            // -----------------------------------------------------------------
            // Stripe
            // -----------------------------------------------------------------
            self::simpleDetector(
                '#(sk_(?:live|test)_[A-Za-z0-9]{24,})#',
                'stripe_secret_key',
                '{{STRIPE_SECRET_KEY}}',
                'stripe_secret_key'
            ),
            self::simpleDetector(
                '#(pk_(?:live|test)_[A-Za-z0-9]{24,})#',
                'stripe_publishable_key',
                '{{STRIPE_PUBLISHABLE_KEY}}',
                'stripe_publishable_key'
            ),
            self::simpleDetector(
                '#(whsec_[A-Za-z0-9]{24,})#',
                'stripe_webhook_secret',
                '{{STRIPE_WEBHOOK_SECRET}}',
                'stripe_webhook_secret'
            ),

            // -----------------------------------------------------------------
            // PayPal / Square / Braintree
            // -----------------------------------------------------------------
            self::kvDetector(
                '#(?:paypal[_-]?client[_-]?secret)["\s:=]+["\']?([A-Za-z0-9\-]{20,})["\']?#i',
                'paypal_client_secret',
                '{{PAYPAL_CLIENT_SECRET}}',
                'paypal_client_secret'
            ),
            self::simpleDetector(
                '#(sq0[a-z]{3}-[A-Za-z0-9\-_]{22,})#',
                'square_access_token',
                '{{SQUARE_ACCESS_TOKEN}}',
                'square_access_token'
            ),
            self::simpleDetector(
                '#(access_token\$[a-z]+\$[a-z0-9]{16,}\$[a-f0-9]{32,})#',
                'braintree_access_token',
                '{{BRAINTREE_ACCESS_TOKEN}}',
                'braintree_access_token'
            ),

            // -----------------------------------------------------------------
            // SendGrid / Mailgun / Mailchimp / Postmark / SparkPost
            // -----------------------------------------------------------------
            self::simpleDetector(
                '#(SG\.[A-Za-z0-9\-_]{22,}\.[A-Za-z0-9\-_]{22,})#',
                'sendgrid_api_key',
                '{{SENDGRID_API_KEY}}',
                'sendgrid_api_key'
            ),
            self::kvDetector(
                '#(?:mailgun[_-]?api[_-]?key|MAILGUN_API_KEY)["\s:=]+["\']?(key-[A-Za-z0-9]{32,})["\']?#i',
                'mailgun_api_key',
                '{{MAILGUN_API_KEY}}',
                'mailgun_api_key'
            ),
            self::simpleDetector(
                '#([0-9a-f]{32}-us\d{1,2})#',
                'mailchimp_api_key',
                '{{MAILCHIMP_API_KEY}}',
                'mailchimp_api_key'
            ),
            self::kvDetector(
                '#(?:postmark|POSTMARK)[_-]?(?:server)?[_-]?(?:token|api[_-]?key)["\s:=]+["\']?([0-9a-f\-]{36})["\']?#i',
                'postmark_server_token',
                '{{POSTMARK_SERVER_TOKEN}}',
                'postmark_server_token'
            ),
            self::kvDetector(
                '#(?:sparkpost|SPARKPOST)[_-]?(?:api)?[_-]?key["\s:=]+["\']?([A-Za-z0-9]{40,})["\']?#i',
                'sparkpost_api_key',
                '{{SPARKPOST_API_KEY}}',
                'sparkpost_api_key'
            ),

            // -----------------------------------------------------------------
            // Slack / Discord / Telegram
            // -----------------------------------------------------------------
            self::simpleDetector(
                '#(xox[bpras]-[0-9A-Za-z\-]{10,})#',
                'slack_token',
                '{{SLACK_TOKEN}}',
                'slack_token'
            ),
            function (string $text, array &$found, array &$known): void {
                if (preg_match('#(https://hooks\.slack\.com/services/T[A-Z0-9]+/B[A-Z0-9]+/[A-Za-z0-9]+)#', $text, $m)) {
                    self::addIfNew($found, $known, 'slack_webhook', $m[1], '{{SLACK_WEBHOOK_URL}}', 'slack_webhook_url');
                }
            },
            function (string $text, array &$found, array &$known): void {
                // Discord bot tokens are base64-ish, ~59 chars with dots
                if (preg_match('#(?:discord|DISCORD)[_-]?(?:bot)?[_-]?token["\s:=]+["\']?([A-Za-z0-9._\-]{50,})#i', $text, $m)) {
                    self::addIfNew($found, $known, 'discord_token', $m[1], '{{DISCORD_BOT_TOKEN}}', 'discord_bot_token');
                }
            },
            function (string $text, array &$found, array &$known): void {
                if (preg_match('#(https://discord(?:app)?\.com/api/webhooks/\d+/[A-Za-z0-9_\-]+)#', $text, $m)) {
                    self::addIfNew($found, $known, 'discord_webhook', $m[1], '{{DISCORD_WEBHOOK_URL}}', 'discord_webhook_url');
                }
            },
            function (string $text, array &$found, array &$known): void {
                if (preg_match('#(\d{8,10}:[A-Za-z0-9_\-]{35})#', $text, $m)) {
                    // Telegram bot tokens are numeric_id:alphanumeric
                    self::addIfNew($found, $known, 'telegram_bot_token', $m[1], '{{TELEGRAM_BOT_TOKEN}}', 'telegram_bot_token');
                }
            },

            // -----------------------------------------------------------------
            // GitHub / GitLab / Bitbucket
            // -----------------------------------------------------------------
            self::simpleDetector(
                '#(ghp_[A-Za-z0-9]{36})#',
                'github_token',
                '{{GITHUB_TOKEN}}',
                'github_token'
            ),
            self::simpleDetector(
                '#(gho_[A-Za-z0-9]{36})#',
                'github_token',
                '{{GITHUB_TOKEN}}',
                'github_token'
            ),
            self::simpleDetector(
                '#(ghu_[A-Za-z0-9]{36})#',
                'github_token',
                '{{GITHUB_TOKEN}}',
                'github_token'
            ),
            self::simpleDetector(
                '#(ghs_[A-Za-z0-9]{36})#',
                'github_token',
                '{{GITHUB_TOKEN}}',
                'github_token'
            ),
            self::simpleDetector(
                '#(github_pat_[A-Za-z0-9_]{22,})#',
                'github_token',
                '{{GITHUB_TOKEN}}',
                'github_token'
            ),
            self::simpleDetector(
                '#(glpat-[A-Za-z0-9\-_]{20,})#',
                'gitlab_token',
                '{{GITLAB_TOKEN}}',
                'gitlab_token'
            ),

            // -----------------------------------------------------------------
            // Heroku / Vercel / Netlify
            // -----------------------------------------------------------------
            self::kvDetector(
                '#(?:HEROKU_API_KEY|heroku[_-]?api[_-]?key)["\s:=]+["\']?([0-9a-f\-]{36})["\']?#i',
                'heroku_api_key',
                '{{HEROKU_API_KEY}}',
                'heroku_api_key'
            ),
            self::kvDetector(
                '#(?:VERCEL_TOKEN|vercel[_-]?token)["\s:=]+["\']?([A-Za-z0-9]{24,})["\']?#i',
                'vercel_token',
                '{{VERCEL_TOKEN}}',
                'vercel_token'
            ),
            self::kvDetector(
                '#(?:NETLIFY_AUTH_TOKEN|netlify[_-]?token)["\s:=]+["\']?([A-Za-z0-9\-_]{40,})["\']?#i',
                'netlify_token',
                '{{NETLIFY_TOKEN}}',
                'netlify_token'
            ),

            // -----------------------------------------------------------------
            // npm / PyPI / Docker Hub / CircleCI / Travis
            // -----------------------------------------------------------------
            self::simpleDetector(
                '#(npm_[A-Za-z0-9]{36})#',
                'npm_token',
                '{{NPM_TOKEN}}',
                'npm_token'
            ),
            self::simpleDetector(
                '#(pypi-[A-Za-z0-9\-_]{16,})#',
                'pypi_token',
                '{{PYPI_TOKEN}}',
                'pypi_token'
            ),
            self::kvDetector(
                '#(?:DOCKER_HUB_TOKEN|docker[_-]?hub[_-]?(?:access)?[_-]?token)["\s:=]+["\']?([A-Za-z0-9\-]{36,})["\']?#i',
                'docker_hub_token',
                '{{DOCKER_HUB_TOKEN}}',
                'docker_hub_token'
            ),
            self::kvDetector(
                '#(?:CIRCLE_TOKEN|circleci[_-]?token)["\s:=]+["\']?([A-Za-z0-9]{40})["\']?#i',
                'circleci_token',
                '{{CIRCLECI_TOKEN}}',
                'circleci_token'
            ),

            // -----------------------------------------------------------------
            // Database connection strings
            // -----------------------------------------------------------------
            function (string $text, array &$found, array &$known): void {
                // PostgreSQL / MySQL / MariaDB connection URLs with passwords
                if (preg_match('#((?:postgres(?:ql)?|mysql|mariadb)://[^@\s]+:[^@\s]+@[^\s"\']+)#i', $text, $m)) {
                    self::addIfNew($found, $known, 'database_url', $m[1], '{{DATABASE_URL}}', 'database_url');
                }
            },
            function (string $text, array &$found, array &$known): void {
                if (preg_match('#(redis://[^\s"\']+)#i', $text, $m)) {
                    self::addIfNew($found, $known, 'redis_url', $m[1], '{{REDIS_URL}}', 'redis_url');
                }
            },
            function (string $text, array &$found, array &$known): void {
                if (preg_match('#(mongodb(?:\+srv)?://[^@\s]+:[^@\s]+@[^\s"\']+)#i', $text, $m)) {
                    self::addIfNew($found, $known, 'mongodb_uri', $m[1], '{{MONGODB_URI}}', 'mongodb_uri');
                }
            },

            // -----------------------------------------------------------------
            // JWT tokens
            // -----------------------------------------------------------------
            function (string $text, array &$found, array &$known): void {
                // JWTs have 3 base64url segments separated by dots
                if (preg_match('#(eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_\-]{10,})#', $text, $m)) {
                    self::addIfNew($found, $known, 'jwt_token', $m[1], '{{JWT_TOKEN}}', null);
                }
            },

            // -----------------------------------------------------------------
            // Private keys (PEM / SSH)
            // -----------------------------------------------------------------
            function (string $text, array &$found, array &$known): void {
                if (preg_match('#(-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----[\s\S]*?-----END (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----)#', $text, $m)) {
                    self::addIfNew($found, $known, 'private_key', $m[1], '{{PRIVATE_KEY}}', null);
                }
            },

            // -----------------------------------------------------------------
            // Bearer tokens (generic, after provider-specific patterns)
            // -----------------------------------------------------------------
            function (string $text, array &$found, array &$known): void {
                if (preg_match('#Bearer\s+([A-Za-z0-9\-._~+/]{20,}=*)#', $text, $m)) {
                    self::addIfNew($found, $known, 'bearer_token', $m[1], '{{BEARER_TOKEN}}', 'bearer_token');
                }
            },

            // -----------------------------------------------------------------
            // Basic auth in URLs  (://user:pass@host)
            // -----------------------------------------------------------------
            function (string $text, array &$found, array &$known): void {
                if (preg_match_all('#://([^@/\s"\']+):([^@/\s"\']+)@#', $text, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $full = "://{$m[1]}:{$m[2]}@";
                        self::addIfNew($found, $known, 'basic_auth', $full, '://{{AUTH_USER}}:{{AUTH_PASS}}@', null);
                    }
                }
            },

            // -----------------------------------------------------------------
            // Generic long API keys / tokens (catch-all, runs last)
            // -----------------------------------------------------------------
            function (string $text, array &$found, array &$known): void {
                $pattern = '#(?:api[_-]?key|apikey|token|secret|password|auth_token|access_token|client_secret|private_key|signing_key|encryption_key)["\s:=]+["\']?([A-Za-z0-9\-._~+/]{20,})["\']?#i';
                if (preg_match_all($pattern, $text, $matches)) {
                    foreach ($matches[1] as $token) {
                        self::addIfNew($found, $known, 'api_token', $token, '{{API_TOKEN}}', 'api_token');
                    }
                }
            },

            // -----------------------------------------------------------------
            // PII: credit card numbers
            // -----------------------------------------------------------------
            function (string $text, array &$found, array &$known): void {
                // Visa, Mastercard, Amex, Discover (with optional dashes/spaces)
                if (preg_match_all('#\b((?:4\d{3}|5[1-5]\d{2}|3[47]\d{2}|6(?:011|5\d{2}))[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4})\b#', $text, $matches)) {
                    foreach ($matches[1] as $cc) {
                        $digits = preg_replace('/[\s\-]/', '', $cc);
                        if (self::luhnCheck($digits)) {
                            self::addIfNew($found, $known, 'credit_card', $cc, '{{CREDIT_CARD}}', null);
                        }
                    }
                }
            },

            // -----------------------------------------------------------------
            // PII: US Social Security Numbers
            // -----------------------------------------------------------------
            function (string $text, array &$found, array &$known): void {
                if (preg_match_all('#\b(\d{3}[\s\-]\d{2}[\s\-]\d{4})\b#', $text, $matches)) {
                    foreach ($matches[1] as $ssn) {
                        self::addIfNew($found, $known, 'ssn', $ssn, '{{SSN}}', null);
                    }
                }
            },

            // -----------------------------------------------------------------
            // Phone numbers (international E.164)
            // -----------------------------------------------------------------
            function (string $text, array &$found, array &$known): void {
                if (preg_match_all('#(?<!\w)(\+\d{10,15})(?!\w)#', $text, $matches)) {
                    foreach ($matches[1] as $phone) {
                        self::addIfNew($found, $known, 'phone_number', $phone, '{{PHONE_NUMBER}}', null);
                    }
                }
            },
        ];
    }

    // =========================================================================
    // Stripping rules  (applied in order)
    // =========================================================================

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function strippingRules(): array
    {
        return [
            // ----- User-specific filesystem paths -----
            ['#/Users/[^/\s"\']+(/[^\s"\']*)?#', '$HOME$1'],
            ['#/home/[^/\s"\']+(/[^\s"\']*)?#', '$HOME$1'],
            ['#C:\\\\Users\\\\[^\\\\\\s"\']+#i', '%USERPROFILE%'],

            // ----- Provider-specific (most specific first) -----

            // Anthropic (before generic sk- match)
            ['#sk-ant-[A-Za-z0-9\-]{20,}#', '{{ANTHROPIC_API_KEY}}'],

            // OpenAI (sk- prefix, but NOT sk-ant-)
            ['#sk-(?!ant-)[A-Za-z0-9]{20,}#', '{{OPENAI_API_KEY}}'],

            // Hugging Face
            ['#hf_[A-Za-z0-9]{20,}#', '{{HUGGINGFACE_TOKEN}}'],

            // Replicate
            ['#r8_[A-Za-z0-9]{20,}#', '{{REPLICATE_API_TOKEN}}'],

            // Twilio Account SID
            ['#AC[0-9a-f]{32}#i', '{{TWILIO_ACCOUNT_SID}}'],

            // Twilio Auth Token (after SID placeholder)
            ['#(\{\{TWILIO_ACCOUNT_SID\}\}):([0-9a-f]{32})#i', '$1:{{TWILIO_AUTH_TOKEN}}'],

            // Standalone 32-char hex after -u flag (auth tokens)
            ['#(-u\s+[^:]+:)[0-9a-f]{32}#i', '$1{{AUTH_TOKEN}}'],

            // AWS access key
            ['#AKIA[0-9A-Z]{16}#', '{{AWS_ACCESS_KEY_ID}}'],
            ['#(?:aws_secret_access_key|aws_secret|secret_key)["\s:=]+["\']?([A-Za-z0-9/+=]{40})["\']?#i', 'aws_secret_access_key={{AWS_SECRET_ACCESS_KEY}}'],

            // GCP / Firebase API key
            ['#AIza[0-9A-Za-z\-_]{35}#', '{{GCP_API_KEY}}'],

            // Azure connection strings
            ['#DefaultEndpointsProtocol=https?;AccountName=[^;]+;AccountKey=[A-Za-z0-9+/=]{44,};[^\s"\']+#', '{{AZURE_CONNECTION_STRING}}'],

            // Stripe keys
            ['#sk_(?:live|test)_[A-Za-z0-9]{24,}#', '{{STRIPE_SECRET_KEY}}'],
            ['#pk_(?:live|test)_[A-Za-z0-9]{24,}#', '{{STRIPE_PUBLISHABLE_KEY}}'],
            ['#whsec_[A-Za-z0-9]{24,}#', '{{STRIPE_WEBHOOK_SECRET}}'],

            // SendGrid
            ['#SG\.[A-Za-z0-9\-_]{22,}\.[A-Za-z0-9\-_]{22,}#', '{{SENDGRID_API_KEY}}'],

            // Mailchimp  (hex-us##)
            ['#[0-9a-f]{32}-us\d{1,2}#', '{{MAILCHIMP_API_KEY}}'],

            // Slack tokens
            ['#xox[bpras]-[0-9A-Za-z\-]{10,}#', '{{SLACK_TOKEN}}'],
            ['#https://hooks\.slack\.com/services/T[A-Z0-9]+/B[A-Z0-9]+/[A-Za-z0-9]+#', '{{SLACK_WEBHOOK_URL}}'],

            // Discord webhook URLs
            ['#https://discord(?:app)?\.com/api/webhooks/\d+/[A-Za-z0-9_\-]+#', '{{DISCORD_WEBHOOK_URL}}'],

            // GitHub tokens
            ['#ghp_[A-Za-z0-9]{36}#', '{{GITHUB_TOKEN}}'],
            ['#gho_[A-Za-z0-9]{36}#', '{{GITHUB_TOKEN}}'],
            ['#ghu_[A-Za-z0-9]{36}#', '{{GITHUB_TOKEN}}'],
            ['#ghs_[A-Za-z0-9]{36}#', '{{GITHUB_TOKEN}}'],
            ['#github_pat_[A-Za-z0-9_]{22,}#', '{{GITHUB_TOKEN}}'],

            // GitLab tokens
            ['#glpat-[A-Za-z0-9\-_]{20,}#', '{{GITLAB_TOKEN}}'],

            // Square
            ['#sq0[a-z]{3}-[A-Za-z0-9\-_]{22,}#', '{{SQUARE_ACCESS_TOKEN}}'],

            // npm tokens
            ['#npm_[A-Za-z0-9]{36}#', '{{NPM_TOKEN}}'],

            // PyPI tokens
            ['#pypi-[A-Za-z0-9\-_]{16,}#', '{{PYPI_TOKEN}}'],

            // JWT tokens (three base64url segments)
            ['#eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_\-]{10,}#', '{{JWT_TOKEN}}'],

            // PEM private keys
            ['#-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----[\s\S]*?-----END (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----#', '{{PRIVATE_KEY}}'],

            // Database connection URLs with embedded passwords
            ['#((?:postgres(?:ql)?|mysql|mariadb)://[^:@\s"\']+):([^@\s"\']+)@#i', '$1:{{DB_PASSWORD}}@'],
            ['#(mongodb(?:\+srv)?://[^:@\s"\']+):([^@\s"\']+)@#i', '$1:{{DB_PASSWORD}}@'],
            ['#(redis://(?:[^:@\s"\']+)?):([^@\s"\']+)@#i', '$1:{{REDIS_PASSWORD}}@'],

            // Phone numbers in API params
            ['#(From[="\s]+)\+\d{10,15}#i', '$1{{FROM_PHONE_NUMBER}}'],
            ['#(To[="\s]+)\+\d{10,15}#i', '$1{{TO_PHONE_NUMBER}}'],

            // Bearer tokens
            ['#(Bearer\s+)[A-Za-z0-9\-._~+/]{20,}=*#', '$1{{BEARER_TOKEN}}'],

            // Credit card numbers (Visa, MC, Amex, Discover with optional separators)
            ['#\b(?:4\d{3}|5[1-5]\d{2}|3[47]\d{2}|6(?:011|5\d{2}))[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?\d{4}\b#', '{{CREDIT_CARD}}'],

            // US SSN
            ['#\b\d{3}[\s\-]\d{2}[\s\-]\d{4}\b#', '{{SSN}}'],

            // Generic API key / token / secret / password values (catch-all, last)
            ['#((?:api[_-]?key|apikey|token|secret|password|auth_token|access_token|client_secret|private_key|signing_key|encryption_key)["\s:=]+["\']?)([A-Za-z0-9\-._~+/]{20,})(["\']?)#i', '$1{{API_SECRET}}$3'],

            // Basic auth in URLs
            ['#(://)([^@/\s"\']+):([^@/\s"\']+)@#', '$1{{AUTH_USER}}:{{AUTH_PASS}}@'],

            // Standalone E.164 phone numbers (after From/To-specific rules)
            ['#(?<!\w)\+\d{10,15}(?!\w)#', '{{PHONE_NUMBER}}'],

            // IPv4 addresses (very last — many are benign but worth scrubbing)
            ['#\b(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\b#', '{{IP_ADDRESS}}'],
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Factory for a simple "find literal match, emit credential" detector.
     *
     * @param  string|null  $contextGuard  Optional regex the text must also match.
     */
    private static function simpleDetector(
        string $pattern,
        string $type,
        string $placeholder,
        string $keyStoreKey,
        ?string $contextGuard = null
    ): \Closure {
        return function (string $text, array &$found, array &$known) use ($pattern, $type, $placeholder, $keyStoreKey, $contextGuard): void {
            if ($contextGuard !== null && !preg_match($contextGuard, $text)) {
                return;
            }
            if (preg_match($pattern, $text, $m)) {
                self::addIfNew($found, $known, $type, $m[1], $placeholder, $keyStoreKey);
            }
        };
    }

    /**
     * Factory for "key=value" style detectors where we only want the value
     * part as the credential.
     */
    private static function kvDetector(
        string $pattern,
        string $type,
        string $placeholder,
        string $keyStoreKey
    ): \Closure {
        return function (string $text, array &$found, array &$known) use ($pattern, $type, $placeholder, $keyStoreKey): void {
            if (preg_match($pattern, $text, $m)) {
                self::addIfNew($found, $known, $type, $m[1], $placeholder, $keyStoreKey);
            }
        };
    }

    /**
     * Add a credential to $found only if its value hasn't been seen.
     */
    private static function addIfNew(
        array &$found,
        array &$known,
        string $type,
        string $value,
        string $placeholder,
        ?string $keyStoreKey
    ): void {
        if (in_array($value, $known, true)) {
            return;
        }
        $known[] = $value;
        $found[] = [
            'type'          => $type,
            'value'         => $value,
            'placeholder'   => $placeholder,
            'key_store_key' => $keyStoreKey,
        ];
    }

    /**
     * Luhn checksum for credit-card number validation.
     */
    private static function luhnCheck(string $digits): bool
    {
        $sum = 0;
        $len = strlen($digits);
        $parity = $len % 2;

        for ($i = 0; $i < $len; $i++) {
            $d = (int) $digits[$i];
            if ($i % 2 === $parity) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }
}
