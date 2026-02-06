<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Skill;

use ClaudeAgents\Agent;
use Dalehurley\Phpbot\CredentialPatterns;

/**
 * Uses the LLM to generalise a completed task into a reusable skill definition.
 *
 * Houses the system prompt, fallback builder, validation/normalisation,
 * and all credential-description helpers that were previously inlined
 * in SkillAutoCreator.
 */
class SkillGeneralizer
{
    private const MAX_ANSWER_LENGTH = 3000;
    private const MAX_TOKENS = 10000;
    private const TEMPERATURE = 0.2;
    private const MAX_NAME_WORDS = 6;

    /**
     * Map of credential-type substring → human-readable service name.
     * Add new entries here when supporting additional providers.
     */
    private const SERVICE_MAP = [
        'twilio'      => 'Twilio',
        'aws'         => 'AWS',
        'openai'      => 'OpenAI',
        'anthropic'   => 'Anthropic',
        'slack'       => 'Slack',
        'sendgrid'    => 'SendGrid',
        'stripe'      => 'Stripe',
        'github'      => 'GitHub',
        'gitlab'      => 'GitLab',
        'discord'     => 'Discord',
        'mailgun'     => 'Mailgun',
        'heroku'      => 'Heroku',
        'vercel'      => 'Vercel',
        'firebase'    => 'Firebase',
        'gcp'         => 'Google Cloud',
        'azure'       => 'Azure',
        'digitalocean' => 'DigitalOcean',
        'linode'      => 'Linode',
        'cloudflare'  => 'Cloudflare',
        'datadog'     => 'Datadog',
        'newrelic'    => 'New Relic',
        'sentry'      => 'Sentry',
        'algolia'     => 'Algolia',
        'pusher'      => 'Pusher',
        'twitch'      => 'Twitch',
        'spotify'     => 'Spotify',
        'dropbox'     => 'Dropbox',
        'box'         => 'Box',
        'airtable'    => 'Airtable',
        'notion'      => 'Notion',
        'asana'       => 'Asana',
        'trello'      => 'Trello',
        'jira'        => 'Jira',
        'confluence'  => 'Confluence',
        'bitbucket'   => 'Bitbucket',
        'circleci'    => 'CircleCI',
        'travis'      => 'Travis CI',
        'jenkins'     => 'Jenkins',
        'docker'      => 'Docker Hub',
        'npm'         => 'npm',
        'pypi'        => 'PyPI',
        'rubygems'    => 'RubyGems',
        'packagist'   => 'Packagist',
        'nuget'       => 'NuGet',
        'maven'       => 'Maven',
        'gradle'      => 'Gradle',
        'terraform'   => 'Terraform Cloud',
        'ansible'     => 'Ansible',
        'kubernetes'  => 'Kubernetes',
        'redis'       => 'Redis',
        'mongodb'     => 'MongoDB',
        'postgres'    => 'PostgreSQL',
        'mysql'       => 'MySQL',
        'elasticsearch' => 'Elasticsearch',
        'rabbitmq'    => 'RabbitMQ',
        'kafka'       => 'Apache Kafka',
        'salesforce'  => 'Salesforce',
        'hubspot'     => 'HubSpot',
        'zendesk'     => 'Zendesk',
        'intercom'    => 'Intercom',
        'segment'     => 'Segment',
        'amplitude'   => 'Amplitude',
        'mixpanel'    => 'Mixpanel',
        'google'      => 'Google',
        'facebook'    => 'Facebook',
        'twitter'     => 'Twitter',
        'linkedin'    => 'LinkedIn',
        'instagram'   => 'Instagram',
        'youtube'     => 'YouTube',
        'paypal'      => 'PayPal',
        'square'      => 'Square',
        'braintree'   => 'Braintree',
        'plaid'       => 'Plaid',
        'okta'        => 'Okta',
        'auth0'       => 'Auth0',
    ];

    /**
     * @param \Closure(): \ClaudePhp\ClaudePhp $clientFactory
     * @param (?callable(\Throwable): void)|null $onError  Optional error callback for observability.
     */
    public function __construct(
        private \Closure $clientFactory,
        private array $config,
        private mixed $onError = null,
    ) {}

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Generalise the completed task into a high-quality, reusable skill
     * definition via the LLM, falling back to a heuristic-based result.
     */
    public function generalize(
        string $input,
        array $analysis,
        string $answer,
        array $scripts = [],
        string $sanitizedRecipe = '',
        array $credentialReport = []
    ): array {
        $sanitized = SkillTextUtils::sanitizeInput($input);
        $fallback = self::buildFallback($sanitized, $scripts, $credentialReport);

        try {
            $payload = $this->buildPayload($input, $sanitized, $analysis, $answer, $scripts, $sanitizedRecipe, $credentialReport);

            $client = ($this->clientFactory)();

            $agent = Agent::create($client)
                ->withName('skill_generalizer')
                ->withSystemPrompt(self::systemPrompt())
                ->withModel($this->getFastModel())
                ->maxIterations(1)
                ->maxTokens(self::MAX_TOKENS)
                ->temperature(self::TEMPERATURE);

            $result = $agent->run(
                "Generalize this completed task into a reusable skill. Respond with a JSON object matching the schema exactly:\n\n{$payload}"
            );

            $data = SkillTextUtils::extractJsonFromResponse($result->getAnswer());

            if ($data === null) {
                return $fallback;
            }

            return self::validateAndNormalize($data, $fallback, $scripts, $credentialReport);
        } catch (\Throwable $e) {
            if (is_callable($this->onError)) {
                ($this->onError)($e);
            }

            return $fallback;
        }
    }

    // =========================================================================
    // Fallback builder (public static for testability)
    // =========================================================================

    /**
     * Build a decent fallback when the LLM call fails.
     * Even fallback descriptions should be useful for skill matching.
     */
    public static function buildFallback(string $sanitized, array $scripts, array $credentialReport): array
    {
        $name = SkillTextUtils::generateShortName($sanitized);
        $humanName = str_replace('-', ' ', $name);

        // Build a useful description even without the LLM
        $description = ucfirst($humanName) . '.';
        $description .= " Use this skill when the user asks to {$humanName}";
        if (!empty($credentialReport)) {
            $services = self::detectServiceNames($credentialReport);
            if (!empty($services)) {
                $description .= ' using ' . implode(' or ', $services);
            }
        }
        $description .= '. Includes bundled scripts and credential management for repeatable execution.';

        // Build when_to_use with concrete trigger phrases
        $whenToUse = "Use this skill when the user asks to:\n";
        $whenToUse .= "- " . ucfirst($humanName) . "\n";
        $whenToUse .= "- " . ucfirst(trim($sanitized)) . "\n";
        $whenToUse .= "- Similar requests involving " . $humanName;

        $procedure = "1. Retrieve required credentials using the get_keys tool.\n";
        $procedure .= "2. Gather any missing input parameters from the user via ask_user.\n";

        if (!empty($scripts)) {
            $scriptNames = array_map(fn($s) => $s['filename'], $scripts);
            $procedure .= "3. Execute the task using bundled scripts: " . implode(', ', $scriptNames) . "\n";
            $procedure .= "4. Verify the output and report results to the user.\n";
        } else {
            $procedure .= "3. Execute the task following the reference commands below.\n";
            $procedure .= "4. Verify the output and report results to the user.\n";
        }

        return [
            'name'                 => $name,
            'description'          => $description,
            'when_to_use'          => $whenToUse,
            'procedure'            => $procedure,
            'tags'                 => ['auto-generated'],
            'required_credentials' => self::buildRequiredCredentials($credentialReport),
            'input_parameters'     => [],
        ];
    }

    // =========================================================================
    // Validation & normalisation (public static for testability)
    // =========================================================================

    /**
     * Validate and normalise the LLM output, ensuring all credentials
     * are stripped and the structure is correct.
     */
    public static function validateAndNormalize(
        array $data,
        array $fallback,
        array $scripts,
        array $credentialReport
    ): array {
        $name        = !empty($data['name']) ? (string) $data['name'] : $fallback['name'];
        $description = !empty($data['description']) ? (string) $data['description'] : $fallback['description'];
        $whenToUse   = !empty($data['when_to_use']) ? (string) $data['when_to_use'] : $fallback['when_to_use'];
        $procedure   = !empty($data['procedure']) ? (string) $data['procedure'] : $fallback['procedure'];
        $tags        = !empty($data['tags']) && is_array($data['tags']) ? $data['tags'] : ['auto-generated'];

        $requiredCredentials = !empty($data['required_credentials']) && is_array($data['required_credentials'])
            ? $data['required_credentials']
            : self::buildRequiredCredentials($credentialReport);

        $inputParameters = !empty($data['input_parameters']) && is_array($data['input_parameters'])
            ? $data['input_parameters']
            : [];

        // Safety: strip any leaked credentials from ALL text fields
        $name        = CredentialPatterns::strip($name);
        $description = CredentialPatterns::strip($description);
        $whenToUse   = CredentialPatterns::strip($whenToUse);
        $procedure   = CredentialPatterns::strip($procedure);

        // Enforce name length (max MAX_NAME_WORDS words)
        $nameWords = explode('-', $name);
        if (count($nameWords) > self::MAX_NAME_WORDS) {
            $name = implode('-', array_slice($nameWords, 0, self::MAX_NAME_WORDS));
        }

        // Ensure auto-generated tag is present
        if (!in_array('auto-generated', $tags, true)) {
            $tags[] = 'auto-generated';
        }

        return [
            'name'                 => $name,
            'description'          => $description,
            'when_to_use'          => $whenToUse,
            'procedure'            => $procedure,
            'tags'                 => $tags,
            'required_credentials' => $requiredCredentials,
            'input_parameters'     => $inputParameters,
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Build the JSON payload sent to the LLM agent.
     */
    private function buildPayload(
        string $input,
        string $sanitized,
        array $analysis,
        string $answer,
        array $scripts,
        string $sanitizedRecipe,
        array $credentialReport
    ): string {
        $safeAnswer = mb_strlen($answer) > self::MAX_ANSWER_LENGTH
            ? mb_substr($answer, 0, self::MAX_ANSWER_LENGTH) . '...'
            : $answer;

        $scriptInfo = array_map(fn($s) => [
            'filename'    => $s['filename'],
            'source'      => $s['source'] ?? 'unknown',
            'description' => $s['description'] ?? '',
            'parameters'  => $s['parameters'] ?? [],
        ], $scripts);

        $credentialInfo = array_map(fn($c) => [
            'type'          => $c['type'],
            'placeholder'   => $c['placeholder'],
            'key_store_key' => $c['key_store_key'],
        ], $credentialReport);

        return json_encode([
            'user_request'         => $input,
            'sanitized_request'    => $sanitized,
            'task_type'            => $analysis['task_type'] ?? 'general',
            'result_summary'       => $safeAnswer,
            'bundled_scripts'      => $scriptInfo,
            'sanitized_recipe'     => $sanitizedRecipe,
            'detected_credentials' => $credentialInfo,
            'complexity'           => $analysis['complexity'] ?? 'medium',
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Build required_credentials array from the credential detection report.
     */
    private static function buildRequiredCredentials(array $credentialReport): array
    {
        $credentials = [];
        $seen = [];

        foreach ($credentialReport as $cred) {
            $keyStoreKey = $cred['key_store_key'] ?? null;
            if ($keyStoreKey === null || in_array($keyStoreKey, $seen, true)) {
                continue;
            }
            $seen[] = $keyStoreKey;

            $envVar = strtoupper(str_replace(['{{', '}}'], '', $cred['placeholder']));
            $credentials[] = [
                'key_store_key' => $keyStoreKey,
                'description'   => CredentialPatterns::describeType($cred['type']),
                'env_var'       => $envVar,
            ];
        }

        return $credentials;
    }

    /**
     * Detect service names from credential types for description context.
     */
    private static function detectServiceNames(array $credentialReport): array
    {
        $services = [];

        foreach ($credentialReport as $cred) {
            $type = $cred['type'] ?? '';
            foreach (self::SERVICE_MAP as $needle => $label) {
                if (str_contains($type, $needle) && !in_array($label, $services, true)) {
                    $services[] = $label;
                }
            }
        }

        return $services;
    }

    private function getFastModel(): string
    {
        $fast = $this->config['fast_model'] ?? '';
        if (is_string($fast) && $fast !== '') {
            return $fast;
        }

        return $this->config['model'];
    }

    // =========================================================================
    // System prompt
    // =========================================================================

    /**
     * The system prompt for the skill-generalizer LLM agent.
     */
    private static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a skill architect. You transform completed tasks into high-quality, reusable skills that help an AI agent learn and improve over time. Each skill you create becomes part of the agent's permanent knowledge base.

## YOUR OUTPUT: JSON SCHEMA

You MUST respond with ONLY a JSON object matching this exact schema:

{
    "name": "string (2-4 words, kebab-case, generic and action-oriented)",
    "description": "string (1-3 sentences: what it does, when to use it, trigger keywords)",
    "tags": ["string array of category tags for discovery"],
    "when_to_use": "string (trigger contexts with example phrases)",
    "required_credentials": [
        {
            "key_store_key": "string (key name in the key store)",
            "description": "string (what this credential is for)",
            "env_var": "string (environment variable name)"
        }
    ],
    "procedure": "string (numbered steps with concrete commands using placeholders)",
    "input_parameters": [
        {
            "name": "string (parameter name)",
            "description": "string (what it is)",
            "example": "string (example value)",
            "required": true
        }
    ]
}

## CRITICAL RULES

### Description (MOST IMPORTANT FIELD)

The description is the PRIMARY trigger mechanism — it determines whether the skill gets matched to future requests. It must be rich, natural, and full of relevant keywords.

Write it as 1-3 natural sentences that cover:
1. What the skill DOES (the capability)
2. WHEN to use it (trigger contexts)
3. Relevant KEYWORDS and SYNONYMS that would appear in similar future requests

BAD descriptions:
- "Repeatable workflow to send-sms. Use when asked to perform similar tasks."
- "A skill for sending SMS messages."
- "Workflow for text messaging."

GOOD descriptions:
- "Send SMS text messages to any phone number using the Twilio API. Use this skill when the user asks to send a text, SMS, text message, or notify someone via phone. Supports custom message content and any recipient number."
- "Create beautiful visual art in .png and .pdf documents using design philosophy. Use this skill when the user asks to create a poster, piece of art, design, or other static piece. Create original visual designs, never copying existing artists' work."
- "Write internal communications using company-standard formats. Use this skill when asked to write status reports, leadership updates, 3P updates, company newsletters, FAQs, incident reports, or project updates."
- "Convert PDF documents to other formats including Word (.docx), text, and HTML. Use this skill when the user asks to convert, transform, or export a PDF to another format."

### Naming
- 2-6 words maximum, kebab-case, action-oriented, GENERIC
- Describes the CATEGORY of action, not the specific instance
- REMOVE all specific content, names, message text, file names
- Examples:
  - "send an sms saying boy time to go" → "send-sms"
  - "email john the quarterly report" → "send-email"
  - "convert users-2024.pdf to docx" → "convert-pdf-to-docx"

### Security — ZERO TOLERANCE for credential leaks
- NEVER include actual API keys, tokens, passwords, phone numbers, or account IDs
- Use {{PLACEHOLDER}} syntax for credentials, reference the key store (get_keys tool)
- All credentials must be listed in required_credentials

### Procedure Quality
- Steps must be CONCRETE and EXECUTABLE — not vague filler
- Include actual commands with {{PLACEHOLDER}} variables
- Reference bundled scripts by filename when available
- First step: retrieve credentials via get_keys tool (if any are required)
- Target 4-8 steps total

### When to Use
- List specific trigger phrases and contexts
- Include synonyms and alternative phrasings users might say
- Format as a list of example triggers

BAD example (everything wrong):
{"name": "send-an-sms-saying-boy-time-to-go", "description": "Repeatable workflow to send-an-sms-saying-boy-time-to-go. Use when asked to perform similar tasks.", "procedure": "1. Identify input\n2. Follow workflow\n3. Generate output\n4. Validate"}

GOOD example (what to produce):
{"name": "send-sms", "description": "Send SMS text messages to any phone number using the Twilio API. Use this skill when the user asks to send a text, SMS, text message, or notify someone via phone. Supports custom message content and any recipient number in E.164 format.", "when_to_use": "Use this skill when the user asks to:\n- Send an SMS or text message\n- Text someone a message\n- Notify someone via SMS\n- Send a Twilio message", "procedure": "1. Retrieve Twilio credentials: use get_keys with keys [twilio_account_sid, twilio_auth_token, twilio_phone_number]\n2. Get recipient phone number and message content from user if not provided (use ask_user)\n3. Send SMS using bundled script: bash scripts/run.sh <to_phone> <message_body>\n4. Verify response contains 'sid' field indicating successful queue\n5. Report delivery status to user"}

Respond with ONLY the JSON object. No markdown fences, no explanation, no commentary.
PROMPT;
    }
}
