<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use Dalehurley\Phpbot\Skill\SkillTextUtils;

/**
 * Transforms raw curl/API commands into parameterized, production-quality
 * shell scripts. Replaces hardcoded credentials with environment variables,
 * extracts dynamic parameters as script arguments, and generates robust
 * bash scripts with validation, error handling, retry logic, and
 * response parsing.
 */
class CurlScriptBuilder
{
    // =========================================================================
    // API Provider Registry
    // =========================================================================

    /**
     * API provider detection patterns.
     * Each entry: [url_pattern, provider_key, human_label]
     */
    private const API_PROVIDERS = [
        // Communication
        ['#api\.twilio\.com.*Messages#i', 'twilio_sms', 'Send SMS via Twilio API'],
        ['#api\.twilio\.com.*Calls#i', 'twilio_call', 'Make phone call via Twilio API'],
        ['#api\.twilio\.com.*Lookups#i', 'twilio_lookup', 'Phone number lookup via Twilio API'],
        ['#api\.twilio\.com#i', 'twilio', 'Call Twilio API'],
        ['#api\.sendgrid\.com/v3/mail/send#i', 'sendgrid_mail', 'Send email via SendGrid API'],
        ['#api\.sendgrid\.com#i', 'sendgrid', 'Call SendGrid API'],
        ['#api\.mailgun\.net#i', 'mailgun', 'Send email via Mailgun API'],
        ['#api\.postmarkapp\.com#i', 'postmark', 'Send email via Postmark API'],
        ['#ses\.amazonaws\.com#i', 'aws_ses', 'Send email via AWS SES'],
        ['#sns\.amazonaws\.com#i', 'aws_sns', 'Send notification via AWS SNS'],
        ['#api\.vonage\.com|rest\.nexmo\.com#i', 'vonage', 'Send message via Vonage API'],
        ['#api\.messagebird\.com#i', 'messagebird', 'Send message via MessageBird API'],
        ['#api\.plivo\.com#i', 'plivo', 'Send message via Plivo API'],

        // AI / ML
        ['#api\.openai\.com/v1/chat/completions#i', 'openai_chat', 'Chat completion via OpenAI API'],
        ['#api\.openai\.com/v1/images#i', 'openai_images', 'Generate images via OpenAI API'],
        ['#api\.openai\.com/v1/embeddings#i', 'openai_embeddings', 'Generate embeddings via OpenAI API'],
        ['#api\.openai\.com/v1/audio#i', 'openai_audio', 'Audio processing via OpenAI API'],
        ['#api\.openai\.com#i', 'openai', 'Call OpenAI API'],
        ['#api\.anthropic\.com#i', 'anthropic', 'Call Anthropic Claude API'],
        ['#api\.cohere\.ai|api\.cohere\.com#i', 'cohere', 'Call Cohere API'],
        ['#generativelanguage\.googleapis\.com#i', 'google_ai', 'Call Google AI API'],
        ['#api\.replicate\.com#i', 'replicate', 'Call Replicate API'],
        ['#api\.stability\.ai#i', 'stability', 'Generate images via Stability AI API'],
        ['#api\.huggingface\.co#i', 'huggingface', 'Call Hugging Face API'],
        ['#api\.mistral\.ai#i', 'mistral', 'Call Mistral AI API'],
        ['#api\.groq\.com#i', 'groq', 'Call Groq API'],
        ['#api\.perplexity\.ai#i', 'perplexity', 'Call Perplexity API'],

        // Collaboration / Messaging
        ['#api\.slack\.com/api/chat\.postMessage#i', 'slack_message', 'Post message to Slack channel'],
        ['#api\.slack\.com/api/files\.upload#i', 'slack_upload', 'Upload file to Slack'],
        ['#api\.slack\.com#i', 'slack', 'Call Slack API'],
        ['#discord\.com/api|discordapp\.com/api#i', 'discord', 'Call Discord API'],
        ['#api\.telegram\.org/bot#i', 'telegram', 'Send message via Telegram Bot API'],
        ['#graph\.microsoft\.com.*sendMail#i', 'microsoft_mail', 'Send email via Microsoft Graph API'],
        ['#graph\.microsoft\.com#i', 'microsoft_graph', 'Call Microsoft Graph API'],

        // Cloud / Infrastructure
        ['#s3\.amazonaws\.com|s3\.[a-z0-9-]+\.amazonaws\.com#i', 'aws_s3', 'Access AWS S3 storage'],
        ['#lambda\.amazonaws\.com#i', 'aws_lambda', 'Invoke AWS Lambda function'],
        ['#dynamodb\.amazonaws\.com#i', 'aws_dynamodb', 'Query AWS DynamoDB'],
        ['#sqs\.amazonaws\.com#i', 'aws_sqs', 'Send message to AWS SQS queue'],
        ['#\.amazonaws\.com#i', 'aws', 'Call AWS API'],
        ['#storage\.googleapis\.com#i', 'gcp_storage', 'Access Google Cloud Storage'],
        ['#\.googleapis\.com#i', 'google_cloud', 'Call Google Cloud API'],
        ['#management\.azure\.com#i', 'azure', 'Call Azure Management API'],
        ['#api\.digitalocean\.com#i', 'digitalocean', 'Call DigitalOcean API'],
        ['#api\.cloudflare\.com#i', 'cloudflare', 'Call Cloudflare API'],
        ['#api\.vercel\.com#i', 'vercel', 'Call Vercel API'],
        ['#api\.netlify\.com#i', 'netlify', 'Call Netlify API'],

        // Payment / Finance
        ['#api\.stripe\.com/v1/charges#i', 'stripe_charge', 'Create charge via Stripe API'],
        ['#api\.stripe\.com/v1/customers#i', 'stripe_customer', 'Manage customers via Stripe API'],
        ['#api\.stripe\.com/v1/payment_intents#i', 'stripe_payment', 'Create payment via Stripe API'],
        ['#api\.stripe\.com#i', 'stripe', 'Call Stripe API'],
        ['#api\.paypal\.com|api-m\.paypal\.com#i', 'paypal', 'Call PayPal API'],
        ['#api\.square\.com|connect\.squareup\.com#i', 'square', 'Call Square API'],
        ['#api\.braintreegateway\.com#i', 'braintree', 'Call Braintree API'],

        // DevOps / Monitoring
        ['#api\.github\.com#i', 'github', 'Call GitHub API'],
        ['#gitlab\.com/api#i', 'gitlab', 'Call GitLab API'],
        ['#api\.bitbucket\.org#i', 'bitbucket', 'Call Bitbucket API'],
        ['#api\.pagerduty\.com#i', 'pagerduty', 'Call PagerDuty API'],
        ['#api\.datadoghq\.com#i', 'datadog', 'Call Datadog API'],
        ['#sentry\.io/api#i', 'sentry', 'Call Sentry API'],
        ['#api\.newrelic\.com#i', 'newrelic', 'Call New Relic API'],

        // CRM / Marketing
        ['#api\.hubspot\.com#i', 'hubspot', 'Call HubSpot API'],
        ['#api\.salesforce\.com|\.my\.salesforce\.com#i', 'salesforce', 'Call Salesforce API'],
        ['#api\.airtable\.com#i', 'airtable', 'Call Airtable API'],
        ['#api\.notion\.com#i', 'notion', 'Call Notion API'],
        ['#api\.mailchimp\.com#i', 'mailchimp', 'Call Mailchimp API'],

        // Search / Data
        ['#api\.algolia\.com|\.algolia\.net#i', 'algolia', 'Call Algolia Search API'],
        ['#api\.elastic\.co|elasticsearch#i', 'elasticsearch', 'Call Elasticsearch API'],
        ['#api\.mapbox\.com#i', 'mapbox', 'Call Mapbox API'],
        ['#maps\.googleapis\.com|maps\.google\.com#i', 'google_maps', 'Call Google Maps API'],
        ['#api\.openweathermap\.org#i', 'openweather', 'Call OpenWeatherMap API'],

        // Media / Storage
        ['#api\.cloudinary\.com#i', 'cloudinary', 'Call Cloudinary API'],
        ['#api\.imgbb\.com#i', 'imgbb', 'Upload image via ImgBB API'],
        ['#upload\.imagekit\.io|api\.imagekit\.io#i', 'imagekit', 'Call ImageKit API'],
        ['#api\.imgur\.com#i', 'imgur', 'Upload to Imgur API'],

        // Auth / Identity
        ['#auth0\.com/api#i', 'auth0', 'Call Auth0 API'],
        ['#api\.clerk\.dev|api\.clerk\.com#i', 'clerk', 'Call Clerk API'],
        ['#oauth2\.googleapis\.com|accounts\.google\.com/o/oauth2#i', 'google_oauth', 'Google OAuth token exchange'],
    ];

    // =========================================================================
    // Dynamic Parameter Patterns
    // =========================================================================

    /**
     * Patterns for extracting dynamic parameters from commands.
     * Each entry: [regex, param_name, description, position_priority]
     */
    private const PARAMETER_PATTERNS = [
        // Phone numbers
        ['#(To[="\s]+)\+(\d{10,15})#i', 'TO_PHONE', 'Recipient phone number (E.164 format, e.g. +1234567890)', 10],
        ['#(From[="\s]+)\+(\d{10,15})#i', 'FROM_PHONE', 'Sender phone number (E.164 format)', 90],

        // Email addresses in form data or JSON
        ['#((?:to|recipient|to_email|email)[="\s:]+)([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})#i', 'TO_EMAIL', 'Recipient email address', 10],
        ['#((?:from|from_email|sender)[="\s:]+)([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})#i', 'FROM_EMAIL', 'Sender email address', 90],

        // Message/body content
        ['#(Body[="\s]+)([^"&\n]{3,})#i', 'MESSAGE_BODY', 'Message content to send', 20],
        ['#("text":\s*")([^"]{3,})#i', 'MESSAGE_TEXT', 'Message text content', 20],
        ['#("content":\s*")([^"]{3,})#i', 'CONTENT', 'Content payload', 20],
        ['#(message[="\s:]+)([^"&\n]{3,})#i', 'MESSAGE', 'Message content', 20],

        // Subject lines
        ['#((?:subject|Subject)[="\s:]+)([^"&\n]{3,})#i', 'SUBJECT', 'Email or message subject line', 15],

        // Channel/room targets
        ['#("channel":\s*")([A-Z0-9]{9,12})#', 'CHANNEL_ID', 'Slack channel ID', 25],
        ['#(channel[="\s]+)([\#@]?[\w\-]+)#i', 'CHANNEL', 'Channel or room name', 25],
        ['#("chat_id":\s*"?)(-?\d+)#i', 'CHAT_ID', 'Telegram chat ID', 25],

        // URLs in request data (not the API endpoint itself)
        ['#("(?:url|webhook_url|callback_url|redirect_uri)":\s*")([^"]+)#i', 'CALLBACK_URL', 'Callback/webhook URL', 30],
        ['#((?:StatusCallback|Url)[="\s]+)(https?://[^\s"&]+)#i', 'CALLBACK_URL', 'Callback URL for status updates', 30],

        // File paths in upload commands
        ['#(-F\s+["\']?file=@|--data-binary\s+@)([^\s"\']+)#', 'FILE_PATH', 'Path to file for upload', 35],
        ['#(-T\s+)([^\s"\']+)#', 'FILE_PATH', 'Path to file for upload', 35],

        // Model selection (AI APIs)
        ['#("model":\s*")([^"]+)#', 'MODEL', 'AI model to use', 40],

        // Prompt / query for AI APIs
        ['#("prompt":\s*")([^"]{3,})#', 'PROMPT', 'Prompt or query text', 20],
        ['#("query":\s*")([^"]{3,})#', 'QUERY', 'Search or query text', 20],
        ['#("input":\s*")([^"]{3,})#', 'INPUT_TEXT', 'Input text', 20],

        // Webhook / event names
        ['#("event":\s*")([^"]+)#', 'EVENT_NAME', 'Event name or type', 50],
        ['#("event_type":\s*")([^"]+)#', 'EVENT_TYPE', 'Event type', 50],

        // Quantity / amount (payment APIs)
        ['#("amount":\s*)(\d+)#', 'AMOUNT', 'Payment amount (in smallest currency unit, e.g. cents)', 20],
        ['#("currency":\s*")([A-Z]{3})#', 'CURRENCY', 'Three-letter currency code (e.g. USD)', 45],
    ];

    // =========================================================================
    // HTTP Method Detection
    // =========================================================================

    private const HTTP_METHODS = [
        '#\bcurl\b.*\s-X\s*GET\b#is' => 'GET',
        '#\bcurl\b.*\s-X\s*POST\b#is' => 'POST',
        '#\bcurl\b.*\s-X\s*PUT\b#is' => 'PUT',
        '#\bcurl\b.*\s-X\s*PATCH\b#is' => 'PATCH',
        '#\bcurl\b.*\s-X\s*DELETE\b#is' => 'DELETE',
        '#\bcurl\b.*\s-X\s*HEAD\b#is' => 'HEAD',
        '#\bcurl\b.*\s-X\s*OPTIONS\b#is' => 'OPTIONS',
        // Infer POST when data flags are present and no explicit method
        '#\bcurl\b.*(?:-d\s|--data[\s=]|-F\s|--form[\s=])#is' => 'POST',
    ];

    // =========================================================================
    // Content-Type Detection
    // =========================================================================

    private const CONTENT_TYPE_PATTERNS = [
        '#-H\s+["\']Content-Type:\s*application/json["\']#i' => 'application/json',
        '#-H\s+["\']Content-Type:\s*application/x-www-form-urlencoded["\']#i' => 'application/x-www-form-urlencoded',
        '#-H\s+["\']Content-Type:\s*multipart/form-data["\']#i' => 'multipart/form-data',
        '#-H\s+["\']Content-Type:\s*text/xml["\']#i' => 'text/xml',
        '#-H\s+["\']Content-Type:\s*text/plain["\']#i' => 'text/plain',
        '#--json\b#i' => 'application/json',
        '#-F\s|--form\s#' => 'multipart/form-data',
    ];

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Detect curl/API commands in tool calls and generate parameterized shell scripts.
     *
     * @param array $toolCalls      Array of tool call records from agent execution
     * @param array $credentialReport  Detected credentials from the session
     * @return array  Array of script descriptors ready for skill bundling
     */
    public function generateApiScripts(array $toolCalls, array $credentialReport): array
    {
        $curlCommands = $this->extractCurlCommands($toolCalls);

        if (empty($curlCommands)) {
            return [];
        }

        $scripts = [];
        foreach ($curlCommands as $i => $curlCmd) {
            $script = $this->buildParameterizedScript($curlCmd, $credentialReport);
            if ($script !== null) {
                $filename = count($curlCommands) === 1
                    ? 'run.sh'
                    : 'run_' . ($i + 1) . '.sh';
                $scripts[] = [
                    'original_path' => '',
                    'filename' => $filename,
                    'content' => $script['content'],
                    'extension' => 'sh',
                    'source' => 'auto_generated',
                    'description' => $script['description'],
                    'parameters' => $script['parameters'],
                    'metadata' => $script['metadata'],
                ];
            }
        }

        return $scripts;
    }

    /**
     * Build a single parameterized shell script from a raw curl command.
     *
     * @param string $curlCommand      The raw curl command string
     * @param array  $credentialReport  Detected credentials
     * @return array|null  Script descriptor or null on failure
     */
    public function buildParameterizedScript(string $curlCommand, array $credentialReport): ?array
    {
        $parameterizedCmd = $curlCommand;

        // Gather metadata about the command
        $httpMethod = $this->detectHttpMethod($curlCommand);
        $contentType = $this->detectContentType($curlCommand);
        $provider = $this->detectApiProvider($curlCommand);
        $description = $provider['label'] ?? 'Execute API call';
        $endpointUrl = $this->extractEndpointUrl($curlCommand);
        $hasFileUpload = $this->detectsFileUpload($curlCommand);
        $followsRedirects = $this->detectsFollowRedirects($curlCommand);
        $isSilent = $this->detectsSilentMode($curlCommand);

        // Phase 1: Replace detected credentials with env var reads
        $envVars = [];
        $parameterizedCmd = $this->replaceCredentials($parameterizedCmd, $credentialReport, $envVars);

        // Phase 2: Extract and replace dynamic parameters
        $parameters = [];
        $parameterizedCmd = $this->extractParameters($parameterizedCmd, $parameters);

        // Phase 3: Sanitize user-specific paths
        $parameterizedCmd = $this->sanitizeUserPaths($parameterizedCmd);

        // Phase 4: Normalize and clean the curl command
        $parameterizedCmd = $this->normalizeCurlCommand($parameterizedCmd, $isSilent, $followsRedirects);

        // Phase 5: Assemble the shell script
        $script = $this->assembleScript(
            $parameterizedCmd,
            $parameters,
            $envVars,
            $httpMethod,
            $contentType,
            $description,
            $endpointUrl,
            $hasFileUpload,
        );

        return [
            'content' => $script,
            'description' => $description,
            'parameters' => $parameters,
            'env_vars' => $envVars,
            'metadata' => [
                'http_method' => $httpMethod,
                'content_type' => $contentType,
                'provider' => $provider['key'] ?? 'unknown',
                'endpoint' => $endpointUrl,
                'has_file_upload' => $hasFileUpload,
            ],
        ];
    }

    /**
     * Check if a command string is a curl/API call worth turning into a script.
     */
    public function isCurlOrApiCommand(string $command): bool
    {
        $lower = strtolower($command);

        // Direct curl/wget/httpie commands
        if (
            str_contains($lower, 'curl ') ||
            str_contains($lower, 'curl\n') ||
            str_contains($lower, 'wget ') ||
            str_contains($lower, 'http ') ||     // httpie
            str_contains($lower, 'https ')
        ) {
            return true;
        }

        // API URL with HTTP reference
        if (str_contains($lower, 'api.') && str_contains($lower, 'http')) {
            return true;
        }

        // Python requests library one-liners
        if (preg_match('#requests\.(get|post|put|patch|delete)\s*\(#i', $lower)) {
            return true;
        }

        // Node fetch calls
        if (preg_match('#fetch\s*\(\s*["\']https?://#i', $lower)) {
            return true;
        }

        return false;
    }

    // =========================================================================
    // Command Extraction
    // =========================================================================

    /**
     * Extract curl/API commands from tool call records.
     */
    private function extractCurlCommands(array $toolCalls): array
    {
        $curlCommands = [];

        foreach ($toolCalls as $call) {
            if (!empty($call['is_error'])) {
                continue;
            }

            if (($call['tool'] ?? '') !== 'bash') {
                continue;
            }

            $input = SkillTextUtils::parseToolInput($call);

            $command = trim((string) ($input['command'] ?? ''));
            if ($command !== '' && $this->isCurlOrApiCommand($command)) {
                $curlCommands[] = $command;
            }
        }

        return $curlCommands;
    }

    // =========================================================================
    // Detection Methods
    // =========================================================================

    /**
     * Detect the HTTP method used in the curl command.
     */
    private function detectHttpMethod(string $command): string
    {
        foreach (self::HTTP_METHODS as $pattern => $method) {
            if (preg_match($pattern, $command)) {
                return $method;
            }
        }

        return 'GET';
    }

    /**
     * Detect the Content-Type of the request.
     */
    private function detectContentType(string $command): ?string
    {
        foreach (self::CONTENT_TYPE_PATTERNS as $pattern => $type) {
            if (preg_match($pattern, $command)) {
                return $type;
            }
        }

        // Infer from data format
        if (preg_match('#-d\s+["\']?\s*\{#', $command)) {
            return 'application/json';
        }

        if (preg_match('#--data-urlencode\b#', $command)) {
            return 'application/x-www-form-urlencoded';
        }

        return null;
    }

    /**
     * Detect which API provider the command targets.
     *
     * @return array{key: string, label: string}|array{}
     */
    private function detectApiProvider(string $command): array
    {
        foreach (self::API_PROVIDERS as [$pattern, $key, $label]) {
            if (preg_match($pattern, $command)) {
                return ['key' => $key, 'label' => $label];
            }
        }

        return [];
    }

    /**
     * Extract the target URL from the curl command.
     */
    private function extractEndpointUrl(string $command): ?string
    {
        // Match quoted URLs
        if (preg_match('#(?:curl|wget|http|https)\s+.*?["\']?(https?://[^\s"\'\\\\]+)["\']?#is', $command, $m)) {
            return $m[1];
        }

        // Match URL as a bare argument (last arg or after flags)
        if (preg_match('#\bhttps?://[^\s"\'\\\\]+#i', $command, $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * Detect if the command includes file upload flags.
     */
    private function detectsFileUpload(string $command): bool
    {
        return (bool) preg_match('#-F\s|--form\s|--data-binary\s+@|-T\s#', $command);
    }

    /**
     * Detect if the command follows redirects.
     */
    private function detectsFollowRedirects(string $command): bool
    {
        return (bool) preg_match('#\s-L\b|\s--location\b#', $command);
    }

    /**
     * Detect if the command uses silent mode.
     */
    private function detectsSilentMode(string $command): bool
    {
        return (bool) preg_match('#\s-s\b|\s--silent\b#', $command);
    }

    // =========================================================================
    // Parameterization Phases
    // =========================================================================

    /**
     * Phase 1: Replace detected credential values with environment variable references.
     */
    private function replaceCredentials(string $command, array $credentialReport, array &$envVars): string
    {
        foreach ($credentialReport as $cred) {
            $value = preg_quote($cred['value'], '#');
            $envName = strtoupper(str_replace(['{{', '}}'], '', $cred['placeholder']));
            $keyStoreKey = $cred['key_store_key'] ?? strtolower($envName);

            if (preg_match('#' . $value . '#', $command)) {
                $command = str_replace($cred['value'], '${' . $envName . '}', $command);
                $envVars[$envName] = $keyStoreKey;
            }
        }

        return $command;
    }

    /**
     * Phase 2: Extract dynamic parameter values and replace with shell variables.
     * Deduplicates by parameter name, taking the first match.
     */
    private function extractParameters(string $command, array &$parameters): string
    {
        $seenNames = [];
        $position = 0;

        // Sort patterns by priority (lower = assigned first)
        $patterns = self::PARAMETER_PATTERNS;
        usort($patterns, fn($a, $b) => $a[3] <=> $b[3]);

        foreach ($patterns as [$pattern, $paramName, $description, $priority]) {
            if (isset($seenNames[$paramName])) {
                continue;
            }

            if (preg_match($pattern, $command, $m)) {
                $position++;
                $command = preg_replace(
                    $pattern,
                    '$1${' . $paramName . '}',
                    $command,
                    1
                );
                $seenNames[$paramName] = true;
                $parameters[] = [
                    'name' => $paramName,
                    'description' => $description,
                    'position' => $position,
                ];
            }
        }

        return $command;
    }

    /**
     * Phase 3: Replace user-specific filesystem paths with $HOME.
     */
    private function sanitizeUserPaths(string $command): string
    {
        $command = preg_replace('#/Users/[^/\s"\']+#', '$HOME', $command);
        $command = preg_replace('#/home/[^/\s"\']+#', '$HOME', $command);

        return $command;
    }

    /**
     * Phase 4: Normalize the curl command for consistency.
     * Ensures useful flags are present and formatting is clean.
     */
    private function normalizeCurlCommand(string $command, bool $isSilent, bool $followsRedirects): string
    {
        // Ensure -s flag is present for cleaner output (suppress progress bar)
        if (!$isSilent && str_contains(strtolower($command), 'curl')) {
            $command = preg_replace('#\bcurl\b#', 'curl -s', $command, 1);
        }

        // Ensure -L flag for redirect-following where appropriate
        if (!$followsRedirects && preg_match('#\b(s3|storage|cdn|download)\b#i', $command)) {
            $command = preg_replace('#\bcurl\s+(-s\s+)?#', 'curl $1-L ', $command, 1);
        }

        return $command;
    }

    // =========================================================================
    // Script Assembly
    // =========================================================================

    /**
     * Assemble the final shell script from all collected components.
     */
    private function assembleScript(
        string $command,
        array $parameters,
        array $envVars,
        string $httpMethod,
        ?string $contentType,
        string $description,
        ?string $endpointUrl,
        bool $hasFileUpload,
    ): string {
        $script = "#!/usr/bin/env bash\n";
        $script .= "set -euo pipefail\n\n";

        // Header comment block
        $script .= $this->buildHeaderComment($description, $httpMethod, $contentType, $endpointUrl);

        // Color/formatting helpers
        $script .= $this->buildColorHelpers();

        // Argument parsing
        if (!empty($parameters)) {
            $script .= $this->buildArgumentSection($parameters);
        }

        // Environment variable checks (credentials)
        if (!empty($envVars)) {
            $script .= $this->buildCredentialSection($envVars);
        }

        // File validation for upload commands
        if ($hasFileUpload) {
            $script .= $this->buildFileValidationSection($parameters);
        }

        // Retry wrapper function
        $script .= $this->buildRetryFunction();

        // The actual API call with response handling
        $script .= $this->buildExecutionSection($command, $contentType);

        return $script;
    }

    /**
     * Build the header comment block.
     */
    private function buildHeaderComment(
        string $description,
        string $httpMethod,
        ?string $contentType,
        ?string $endpointUrl,
    ): string {
        $header = "# =============================================================================\n";
        $header .= "# {$description}\n";
        $header .= "# =============================================================================\n";
        $header .= "# HTTP Method:  {$httpMethod}\n";

        if ($contentType !== null) {
            $header .= "# Content-Type: {$contentType}\n";
        }

        if ($endpointUrl !== null) {
            // Redact any remaining secrets from the URL display
            $safeUrl = preg_replace('#://[^@/]+:[^@/]+@#', '://***:***@', $endpointUrl);
            $header .= "# Endpoint:     {$safeUrl}\n";
        }

        $header .= "# Generated:    " . date('Y-m-d') . "\n";
        $header .= "# =============================================================================\n\n";

        return $header;
    }

    /**
     * Build color/formatting helper variables for nicer output.
     */
    private function buildColorHelpers(): string
    {
        $s = "# --- Output formatting ---\n";
        $s .= "RED='\\033[0;31m'\n";
        $s .= "GREEN='\\033[0;32m'\n";
        $s .= "YELLOW='\\033[0;33m'\n";
        $s .= "NC='\\033[0m' # No Color\n\n";

        return $s;
    }

    /**
     * Build the argument parsing and validation section.
     */
    private function buildArgumentSection(array $parameters): string
    {
        $s = "# --- Arguments ---\n";

        // Usage comment
        foreach ($parameters as $param) {
            $s .= "# \${$param['position']}: {$param['description']}\n";
        }
        $s .= "\n";

        // Assign from positional args
        foreach ($parameters as $param) {
            $varName = $param['name'];
            $s .= "{$varName}=\"\${" . $param['position'] . ":-}\"\n";
        }
        $s .= "\n";

        // Build usage string for error messages
        $usageParts = [];
        foreach ($parameters as $p) {
            $usageParts[] = '<' . strtolower($p['name']) . '>';
        }
        $usageStr = implode(' ', $usageParts);

        // Validation with helpful error messages
        $s .= "# Validate required arguments\n";
        foreach ($parameters as $param) {
            $varName = $param['name'];
            $s .= "if [[ -z \"\${$varName}\" ]]; then\n";
            $s .= "  echo -e \"\${RED}Error:\${NC} {$param['description']} is required\" >&2\n";
            $s .= "  echo \"Usage: \$0 {$usageStr}\" >&2\n";
            $s .= "  exit 1\n";
            $s .= "fi\n";
        }
        $s .= "\n";

        // Add format validation for known parameter types
        $s .= $this->buildParameterFormatValidation($parameters);

        return $s;
    }

    /**
     * Build format validation for parameters with known constraints.
     */
    private function buildParameterFormatValidation(array $parameters): string
    {
        $s = '';

        foreach ($parameters as $param) {
            $name = $param['name'];

            // Phone number validation
            if (preg_match('#PHONE#i', $name)) {
                $s .= "# Validate phone number format (E.164)\n";
                $s .= "if [[ ! \"\${$name}\" =~ ^\\+[0-9]{10,15}$ ]]; then\n";
                $s .= "  echo -e \"\${YELLOW}Warning:\${NC} {$name} should be in E.164 format (e.g. +1234567890)\" >&2\n";
                $s .= "fi\n\n";
            }

            // Email validation
            if (preg_match('#EMAIL#i', $name)) {
                $s .= "# Validate email format\n";
                $s .= "if [[ ! \"\${$name}\" =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$ ]]; then\n";
                $s .= "  echo -e \"\${RED}Error:\${NC} {$name} is not a valid email address\" >&2\n";
                $s .= "  exit 1\n";
                $s .= "fi\n\n";
            }

            // URL validation
            if (preg_match('#URL#i', $name)) {
                $s .= "# Validate URL format\n";
                $s .= "if [[ ! \"\${$name}\" =~ ^https?:// ]]; then\n";
                $s .= "  echo -e \"\${YELLOW}Warning:\${NC} {$name} should start with http:// or https://\" >&2\n";
                $s .= "fi\n\n";
            }

            // Currency code validation
            if ($name === 'CURRENCY') {
                $s .= "# Validate currency code (ISO 4217)\n";
                $s .= "if [[ ! \"\${$name}\" =~ ^[A-Z]{3}$ ]]; then\n";
                $s .= "  echo -e \"\${RED}Error:\${NC} {$name} must be a 3-letter ISO currency code (e.g. USD, EUR)\" >&2\n";
                $s .= "  exit 1\n";
                $s .= "fi\n\n";
            }

            // Amount validation (must be positive integer)
            if ($name === 'AMOUNT') {
                $s .= "# Validate amount is a positive integer\n";
                $s .= "if [[ ! \"\${$name}\" =~ ^[0-9]+$ ]] || [[ \"\${$name}\" -le 0 ]]; then\n";
                $s .= "  echo -e \"\${RED}Error:\${NC} {$name} must be a positive integer (in smallest currency unit)\" >&2\n";
                $s .= "  exit 1\n";
                $s .= "fi\n\n";
            }

            // File path validation
            if ($name === 'FILE_PATH') {
                $s .= "# Validate file exists\n";
                $s .= "if [[ ! -f \"\${$name}\" ]]; then\n";
                $s .= "  echo -e \"\${RED}Error:\${NC} File not found: \${$name}\" >&2\n";
                $s .= "  exit 1\n";
                $s .= "fi\n\n";
            }
        }

        return $s;
    }

    /**
     * Build the credential/environment variable check section.
     */
    private function buildCredentialSection(array $envVars): string
    {
        $s = "# --- Credentials (from environment or key store) ---\n";
        $s .= "# Retrieve via: get_keys tool, environment variables, or .env file\n";

        // Source .env file if it exists
        $s .= "if [[ -f \"\${HOME}/.env\" ]]; then\n";
        $s .= "  # shellcheck disable=SC1091\n";
        $s .= "  source \"\${HOME}/.env\"\n";
        $s .= "fi\n";
        $s .= "if [[ -f \".env\" ]]; then\n";
        $s .= "  # shellcheck disable=SC1091\n";
        $s .= "  source \".env\"\n";
        $s .= "fi\n\n";

        foreach ($envVars as $envName => $keyStoreKey) {
            $s .= ": \"\${{{$envName}:?'{$envName} is required. Use get_keys to retrieve {$keyStoreKey} from the key store.'}\"\n";
        }
        $s .= "\n";

        return $s;
    }

    /**
     * Build file validation for upload commands.
     */
    private function buildFileValidationSection(array $parameters): string
    {
        $hasFileParam = false;
        foreach ($parameters as $param) {
            if ($param['name'] === 'FILE_PATH') {
                $hasFileParam = true;
                break;
            }
        }

        // File validation is already handled in format validation if FILE_PATH param exists
        if ($hasFileParam) {
            return '';
        }

        // If no FILE_PATH parameter was extracted but we know there's a file upload,
        // add a generic note
        $s = "# --- File upload detected ---\n";
        $s .= "# Ensure the file path referenced in the command below exists before running.\n\n";

        return $s;
    }

    /**
     * Build a retry wrapper function for resilient API calls.
     */
    private function buildRetryFunction(): string
    {
        $s = "# --- Retry logic for transient failures ---\n";
        $s .= "MAX_RETRIES=\${MAX_RETRIES:-3}\n";
        $s .= "RETRY_DELAY=\${RETRY_DELAY:-2}\n\n";

        $s .= "retry() {\n";
        $s .= "  local attempt=1\n";
        $s .= "  local max_attempts=\"\${MAX_RETRIES}\"\n";
        $s .= "  local delay=\"\${RETRY_DELAY}\"\n";
        $s .= "  local exit_code=0\n\n";
        $s .= "  while [[ \${attempt} -le \${max_attempts} ]]; do\n";
        $s .= "    if \"\$@\"; then\n";
        $s .= "      return 0\n";
        $s .= "    fi\n";
        $s .= "    exit_code=\$?\n\n";
        $s .= "    if [[ \${attempt} -lt \${max_attempts} ]]; then\n";
        $s .= "      echo -e \"\${YELLOW}Attempt \${attempt}/\${max_attempts} failed (exit code: \${exit_code}). Retrying in \${delay}s...\${NC}\" >&2\n";
        $s .= "      sleep \"\${delay}\"\n";
        $s .= "      delay=\$((delay * 2))\n";
        $s .= "    fi\n\n";
        $s .= "    attempt=\$((attempt + 1))\n";
        $s .= "  done\n\n";
        $s .= "  echo -e \"\${RED}All \${max_attempts} attempts failed.\${NC}\" >&2\n";
        $s .= "  return \${exit_code}\n";
        $s .= "}\n\n";

        return $s;
    }

    /**
     * Build the execution section that runs the actual API call with
     * response capture, HTTP status checking, and output formatting.
     */
    private function buildExecutionSection(string $command, ?string $contentType): string
    {
        $s = "# --- Execute API call ---\n";

        // Use a temp file for response + HTTP status when dealing with curl
        if (str_contains(strtolower($command), 'curl')) {
            $s .= "RESPONSE_FILE=\"\$(mktemp)\"\n";
            $s .= "trap 'rm -f \"\${RESPONSE_FILE}\"' EXIT\n\n";

            // Add --write-out to capture HTTP status code
            $httpCodeFlag = ' -w "\\n%{http_code}"';
            $s .= "echo -e \"\${YELLOW}Calling API...\${NC}\" >&2\n\n";

            // Wrap with retry
            $s .= "FULL_RESPONSE=\"\$(retry {$command}{$httpCodeFlag})\"\n\n";

            // Parse HTTP status from the last line
            $s .= "HTTP_CODE=\"\$(echo \"\${FULL_RESPONSE}\" | tail -1)\"\n";
            $s .= "RESPONSE_BODY=\"\$(echo \"\${FULL_RESPONSE}\" | sed '\$d')\"\n\n";

            // Status code check
            $s .= "# --- Validate response ---\n";
            $s .= "if [[ \"\${HTTP_CODE}\" =~ ^2[0-9]{2}$ ]]; then\n";
            $s .= "  echo -e \"\${GREEN}Success\${NC} (HTTP \${HTTP_CODE})\" >&2\n";
            $s .= "else\n";
            $s .= "  echo -e \"\${RED}Failed\${NC} (HTTP \${HTTP_CODE})\" >&2\n";
            $s .= "  echo \"\${RESPONSE_BODY}\" >&2\n";
            $s .= "  exit 1\n";
            $s .= "fi\n\n";

            // Pretty-print JSON responses if jq is available
            if ($contentType === 'application/json' || $contentType === null) {
                $s .= "# --- Format output ---\n";
                $s .= "if command -v jq &>/dev/null; then\n";
                $s .= "  echo \"\${RESPONSE_BODY}\" | jq .\n";
                $s .= "else\n";
                $s .= "  echo \"\${RESPONSE_BODY}\"\n";
                $s .= "fi\n";
            } else {
                $s .= "echo \"\${RESPONSE_BODY}\"\n";
            }
        } else {
            // Non-curl commands: execute directly with retry
            $s .= "retry {$command}\n";
        }

        return $s;
    }
}
