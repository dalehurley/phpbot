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
        $name      = SkillTextUtils::generateShortName($sanitized);
        $humanName = str_replace('-', ' ', $name);

        // Build a rich description that includes WHAT, WHEN, and keyword synonyms —
        // the description is the sole trigger mechanism and must be comprehensive.
        $services    = !empty($credentialReport) ? self::detectServiceNames($credentialReport) : [];
        $serviceText = !empty($services) ? ' using ' . implode(' or ', $services) : '';

        $description  = ucfirst($humanName) . $serviceText . '. ';
        $description .= "Use this skill when the user asks to {$humanName}{$serviceText}. ";
        $description .= 'Includes bundled scripts and credential management for repeatable execution.';

        $procedure  = "1. Retrieve required credentials using the `get_keys` tool.\n";
        $procedure .= "2. Gather any missing input parameters from the user via `ask_user`.\n";

        if (!empty($scripts)) {
            $scriptNames = array_map(fn($s) => '`' . $s['filename'] . '`', $scripts);
            $procedure  .= '3. Execute the task using bundled scripts: ' . implode(', ', $scriptNames) . "\n";
        } else {
            $procedure .= "3. Execute the task following the reference commands below.\n";
        }

        $procedure .= "4. Verify the output and report results to the user.\n";

        $setupNotes = [];
        foreach ($services as $service) {
            $setupNotes[] = [
                'service'      => $service,
                'instructions' => "Configure {$service} credentials and store them via the `store_keys` tool.",
            ];
        }

        return [
            'name'                 => $name,
            'description'          => $description,
            'procedure'            => $procedure,
            'required_credentials' => self::buildRequiredCredentials($credentialReport),
            'setup_notes'          => $setupNotes,
            'input_parameters'     => [],
            'output_format'        => '',
            'examples'             => [ucfirst($humanName)],
            'reference_commands'   => '',
            'notes'                => [],
            'bundled_resources'    => [],
        ];
    }

    // =========================================================================
    // Validation & normalisation (public static for testability)
    // =========================================================================

    /**
     * Validate and normalise the LLM output, ensuring all credentials
     * are stripped and the structure matches the current schema.
     */
    public static function validateAndNormalize(
        array $data,
        array $fallback,
        array $scripts,
        array $credentialReport
    ): array {
        $name        = !empty($data['name']) ? (string) $data['name'] : $fallback['name'];
        $description = !empty($data['description']) ? (string) $data['description'] : $fallback['description'];
        $procedure   = !empty($data['procedure']) ? (string) $data['procedure'] : $fallback['procedure'];

        $requiredCredentials = !empty($data['required_credentials']) && is_array($data['required_credentials'])
            ? $data['required_credentials']
            : self::buildRequiredCredentials($credentialReport);

        $setupNotes = !empty($data['setup_notes']) && is_array($data['setup_notes'])
            ? $data['setup_notes']
            : ($fallback['setup_notes'] ?? []);

        $inputParameters = !empty($data['input_parameters']) && is_array($data['input_parameters'])
            ? $data['input_parameters']
            : [];

        $outputFormat      = !empty($data['output_format']) ? (string) $data['output_format'] : ($fallback['output_format'] ?? '');
        $referenceCommands = !empty($data['reference_commands']) ? (string) $data['reference_commands'] : ($fallback['reference_commands'] ?? '');
        $notes             = !empty($data['notes']) && is_array($data['notes']) ? $data['notes'] : ($fallback['notes'] ?? []);
        $bundledResources  = !empty($data['bundled_resources']) && is_array($data['bundled_resources']) ? $data['bundled_resources'] : ($fallback['bundled_resources'] ?? []);

        // Normalise examples: prefer `examples` array, fall back to legacy `example_request` string.
        if (!empty($data['examples']) && is_array($data['examples'])) {
            $examples = $data['examples'];
        } elseif (!empty($data['example_request'])) {
            $examples = [(string) $data['example_request']];
        } else {
            $examples = $fallback['examples'] ?? [];
        }

        // Safety: strip any leaked credentials from ALL text fields
        $name              = CredentialPatterns::strip($name);
        $description       = CredentialPatterns::strip($description);
        $procedure         = CredentialPatterns::strip($procedure);
        $outputFormat      = CredentialPatterns::strip($outputFormat);
        $referenceCommands = CredentialPatterns::strip($referenceCommands);
        $examples          = array_map(fn($e) => CredentialPatterns::strip((string) $e), $examples);

        foreach ($setupNotes as &$note) {
            if (isset($note['instructions'])) {
                $note['instructions'] = CredentialPatterns::strip($note['instructions']);
            }
        }
        unset($note);

        $notes = array_map(fn($n) => CredentialPatterns::strip((string) $n), $notes);

        // Enforce name length
        $nameWords = explode('-', $name);
        if (count($nameWords) > self::MAX_NAME_WORDS) {
            $name = implode('-', array_slice($nameWords, 0, self::MAX_NAME_WORDS));
        }

        return [
            'name'                 => $name,
            'description'          => $description,
            'procedure'            => $procedure,
            'required_credentials' => $requiredCredentials,
            'setup_notes'          => $setupNotes,
            'input_parameters'     => $inputParameters,
            'output_format'        => $outputFormat,
            'examples'             => $examples,
            'reference_commands'   => $referenceCommands,
            'notes'                => $notes,
            'bundled_resources'    => $bundledResources,
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

Respond with ONLY a JSON object matching this exact schema — no markdown fences, no explanation:

{
    "name": "string (2-6 words, kebab-case, generic and action-oriented)",
    "description": "string (2-4 sentences: WHAT it does + WHEN to use it + trigger keywords and synonyms)",
    "required_credentials": [
        {
            "key_store_key": "string (key name in the key store)",
            "description": "string (what this credential is for)",
            "env_var": "string (environment variable name)"
        }
    ],
    "setup_notes": [
        {
            "service": "string (service name, e.g. 'Gmail', 'Twilio')",
            "instructions": "string (setup steps for this specific service)"
        }
    ],
    "procedure": "string (numbered steps with concrete commands using {{PLACEHOLDER}} variables)",
    "input_parameters": [
        {
            "name": "string (parameter name)",
            "description": "string (what it is)",
            "example": "string (example value)",
            "required": true
        }
    ],
    "output_format": "string (optional — what the skill produces. Empty string if obvious)",
    "examples": ["string array of 3-5 diverse, natural user queries that would trigger this skill"],
    "reference_commands": "string (key shell commands with {{PLACEHOLDER}} vars, or empty string if bundled scripts cover it)",
    "notes": ["string array (optional — important tips, gotchas, or caveats)"]
}

## CRITICAL RULES

### Description (MOST IMPORTANT FIELD)

The description is the PRIMARY and ONLY trigger mechanism — it determines whether this skill gets matched to future requests. It is the only field read before the skill body loads, so ALL "when to use" information must live here.

Write 2-4 natural sentences that cover:
1. What the skill DOES (the capability)
2. WHEN to use it (trigger contexts, example phrasings)
3. Relevant KEYWORDS and SYNONYMS that would appear in similar requests

BAD descriptions:
- "Repeatable workflow to send-sms. Use when asked to perform similar tasks."
- "A skill for sending SMS messages."

GOOD descriptions:
- "Send SMS text messages to any phone number using the Twilio API. Use this skill when the user asks to send a text, SMS, text message, or notify someone via phone. Supports custom message content and any recipient number in E.164 format."
- "Create beautiful visual art in .png and .pdf documents using a design philosophy. Use this skill when the user asks to create a poster, piece of art, design, or other static visual piece."
- "Write internal communications using company-standard formats. Use this skill when asked to write status reports, leadership updates, company newsletters, FAQs, incident reports, or project updates."
- "Convert PDF documents to other formats including Word (.docx), text, and HTML. Use this skill when the user asks to convert, transform, or export a PDF to another format."

### Naming
- 2-6 words, kebab-case, action-oriented, GENERIC — describes the category, not the specific instance
- Remove all specific content, names, message text, file names
- "send an sms saying boy time to go" → "send-sms"
- "email john the quarterly report" → "send-email"
- "convert users-2024.pdf to docx" → "convert-pdf-to-docx"

### Security — ZERO TOLERANCE for credential leaks
- NEVER include actual API keys, tokens, passwords, phone numbers, or account IDs
- Use {{PLACEHOLDER}} syntax for all credentials
- List every required credential in required_credentials

### Procedure Quality
- Steps must be CONCRETE and EXECUTABLE — no vague filler
- Include actual commands with {{PLACEHOLDER}} variables
- Reference bundled scripts by filename when available
- First step: retrieve credentials via `get_keys` tool (if required)
- Target 4-8 steps total

### Examples (3-5 diverse user queries)
- Natural sentences a user would actually type to trigger this skill
- GENERIC — no specific names, file paths, phone numbers, or personal content
- Cover different phrasings of the same intent
- "send a text message to confirm the appointment"
- "create a podcast episode about PHP development"
- "convert the attached PDF to a Word document"

### Output Format (OPTIONAL)
- Describe what files or artifacts the skill produces
- Include file types and delivery location
- Empty string when the output is obvious

### Setup Notes (OPTIONAL)
- Per-service setup instructions only when credentials need special configuration
- Example: Gmail requires an App Password, not a regular password

### Reference Commands
- Generalized KEY shell commands with {{PLACEHOLDER}} variables
- 3-8 lines maximum; ONLY the essential commands
- Empty string if bundled scripts already cover the workflow

BAD example:
{"name": "send-an-sms-saying-boy-time-to-go", "description": "Repeatable workflow to send-an-sms-saying-boy-time-to-go.", "procedure": "1. Identify input\n2. Follow workflow\n3. Generate output\n4. Validate", "examples": ["send sms"]}

GOOD example:
{"name": "send-sms", "description": "Send SMS text messages to any phone number using the Twilio API. Use this skill when the user asks to send a text, SMS, text message, or notify someone via phone. Supports custom message content and any recipient number in E.164 format.", "required_credentials": [{"key_store_key": "twilio_account_sid", "description": "Twilio Account SID", "env_var": "TWILIO_ACCOUNT_SID"}, {"key_store_key": "twilio_auth_token", "description": "Twilio Auth Token", "env_var": "TWILIO_AUTH_TOKEN"}, {"key_store_key": "twilio_phone_number", "description": "Twilio sender phone number", "env_var": "TWILIO_PHONE_NUMBER"}], "setup_notes": [{"service": "Twilio", "instructions": "1. Create a Twilio account at twilio.com\n2. Get your Account SID and Auth Token from the dashboard\n3. Purchase or verify a phone number for sending"}], "procedure": "1. Retrieve Twilio credentials: use `get_keys` with keys `[twilio_account_sid, twilio_auth_token, twilio_phone_number]`\n2. Get recipient phone number and message content from user if not provided (use `ask_user`)\n3. Send SMS using bundled script: `bash scripts/run.sh <to_phone> <message_body>`\n4. Verify response contains 'sid' field indicating successful queue\n5. Report delivery status to user", "input_parameters": [{"name": "to_phone", "description": "Recipient phone number", "example": "+14155551234", "required": true}, {"name": "message", "description": "SMS message body", "example": "Your appointment is confirmed.", "required": true}], "output_format": "", "examples": ["send a text message to confirm the appointment", "text someone that their order is ready", "send an SMS notification to the team", "notify a customer via text message", "send a Twilio SMS to this phone number"], "reference_commands": "bash scripts/run.sh {{TO_PHONE}} {{MESSAGE_BODY}}", "notes": ["Phone numbers must be in E.164 format (e.g., +1234567890)", "Twilio trial accounts can only send to verified numbers"]}
PROMPT;
    }
}
