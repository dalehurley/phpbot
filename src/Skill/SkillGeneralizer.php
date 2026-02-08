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
        $services = [];
        if (!empty($credentialReport)) {
            $services = self::detectServiceNames($credentialReport);
            if (!empty($services)) {
                $description .= ' using ' . implode(' or ', $services);
            }
        }
        $description .= '. Includes bundled scripts and credential management for repeatable execution.';

        // Build when_to_use with concrete trigger phrases as bullet list
        $whenToUse = "Use this skill when the user asks to:\n\n";
        $whenToUse .= "- " . ucfirst($humanName) . "\n";
        $whenToUse .= "- " . ucfirst(trim($sanitized)) . "\n";
        $whenToUse .= "- Similar requests involving " . $humanName;

        $procedure = "1. Retrieve required credentials using the `get_keys` tool.\n";
        $procedure .= "2. Gather any missing input parameters from the user via `ask_user`.\n";

        if (!empty($scripts)) {
            $scriptNames = array_map(fn($s) => '`' . $s['filename'] . '`', $scripts);
            $procedure .= "3. Execute the task using bundled scripts: " . implode(', ', $scriptNames) . "\n";
            $procedure .= "4. Verify the output and report results to the user.\n";
        } else {
            $procedure .= "3. Execute the task following the reference commands below.\n";
            $procedure .= "4. Verify the output and report results to the user.\n";
        }

        // Build setup notes from detected services
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
            'overview'             => '',
            'when_to_use'          => $whenToUse,
            'procedure'            => $procedure,
            'tags'                 => ['auto-generated'],
            'required_credentials' => self::buildRequiredCredentials($credentialReport),
            'setup_notes'          => $setupNotes,
            'input_parameters'     => [],
            'output_format'        => '',
            'example_request'      => ucfirst($humanName),
            'reference_commands'   => '',
            'keywords'             => [],
            'notes'                => [],
            'bundled_resources'    => [],
            'license'              => '',
            'version'              => '0.1.0',
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
        $overview    = !empty($data['overview']) ? (string) $data['overview'] : ($fallback['overview'] ?? '');
        $whenToUse   = !empty($data['when_to_use']) ? (string) $data['when_to_use'] : $fallback['when_to_use'];
        $procedure   = !empty($data['procedure']) ? (string) $data['procedure'] : $fallback['procedure'];
        $tags        = !empty($data['tags']) && is_array($data['tags']) ? $data['tags'] : ['auto-generated'];

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
        $exampleRequest    = !empty($data['example_request']) ? (string) $data['example_request'] : ($fallback['example_request'] ?? '');
        $referenceCommands = !empty($data['reference_commands']) ? (string) $data['reference_commands'] : ($fallback['reference_commands'] ?? '');
        $keywords          = !empty($data['keywords']) && is_array($data['keywords']) ? $data['keywords'] : ($fallback['keywords'] ?? []);
        $notes             = !empty($data['notes']) && is_array($data['notes']) ? $data['notes'] : ($fallback['notes'] ?? []);
        $bundledResources  = !empty($data['bundled_resources']) && is_array($data['bundled_resources']) ? $data['bundled_resources'] : ($fallback['bundled_resources'] ?? []);
        $license           = !empty($data['license']) ? (string) $data['license'] : ($fallback['license'] ?? '');
        $version           = !empty($data['version']) ? (string) $data['version'] : ($fallback['version'] ?? '0.1.0');

        // Safety: strip any leaked credentials from ALL text fields
        $name              = CredentialPatterns::strip($name);
        $description       = CredentialPatterns::strip($description);
        $overview          = CredentialPatterns::strip($overview);
        $whenToUse         = CredentialPatterns::strip($whenToUse);
        $procedure         = CredentialPatterns::strip($procedure);
        $outputFormat      = CredentialPatterns::strip($outputFormat);
        $exampleRequest    = CredentialPatterns::strip($exampleRequest);
        $referenceCommands = CredentialPatterns::strip($referenceCommands);

        // Strip credentials from setup notes instructions
        foreach ($setupNotes as &$note) {
            if (isset($note['instructions'])) {
                $note['instructions'] = CredentialPatterns::strip($note['instructions']);
            }
        }
        unset($note);

        // Strip credentials from notes
        $notes = array_map(fn($n) => CredentialPatterns::strip((string) $n), $notes);

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
            'overview'             => $overview,
            'when_to_use'          => $whenToUse,
            'procedure'            => $procedure,
            'tags'                 => $tags,
            'required_credentials' => $requiredCredentials,
            'setup_notes'          => $setupNotes,
            'input_parameters'     => $inputParameters,
            'output_format'        => $outputFormat,
            'example_request'      => $exampleRequest,
            'reference_commands'   => $referenceCommands,
            'keywords'             => $keywords,
            'notes'                => $notes,
            'bundled_resources'    => $bundledResources,
            'license'              => $license,
            'version'              => $version,
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
    "overview": "string (optional — 1-2 sentence intro paragraph providing context, e.g. 'This guide covers PDF processing operations using Python libraries and CLI tools.' Empty string if not needed)",
    "when_to_use": "string (trigger contexts with example phrases, formatted as bullet list)",
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
    "procedure": "string (numbered steps with concrete commands using placeholders)",
    "input_parameters": [
        {
            "name": "string (parameter name)",
            "description": "string (what it is)",
            "example": "string (example value)",
            "required": true
        }
    ],
    "output_format": "string (optional — what the skill produces/delivers, e.g. 'A markdown script file and an MP3 audio file.' Empty string if obvious)",
    "example_request": "string (a short, generic example of what a user would say to trigger this skill)",
    "reference_commands": "string (key shell commands generalized with {{PLACEHOLDER}} variables, or empty string if bundled scripts cover it)",
    "keywords": ["string array (optional — explicit trigger keywords for discoverability beyond what tags cover)"],
    "notes": ["string array (optional — important tips, gotchas, or caveats)"]
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
- Use inline code formatting for tool names, commands, and file paths

### When to Use
- List specific trigger phrases and contexts as a BULLET LIST
- Include synonyms and alternative phrasings users might say
- Format with "- " markdown bullets
- Example format: "Use this skill when the user asks to:\n- Send an SMS or text message\n- Text someone a message"

### Overview (OPTIONAL — include when helpful)
- 1-2 sentences providing context about what the skill covers
- Useful for complex skills that benefit from a brief introduction
- Skip for simple single-purpose utility skills

### Output Format (OPTIONAL — include when the deliverables are non-obvious)
- Describe what files or artifacts the skill produces
- Include file types, naming conventions, delivery location
- Examples: "A markdown script file and an MP3 audio file in the output directory."
- Skip when the output is obvious (e.g., a simple clipboard write)

### Setup Notes (OPTIONAL — include for service-specific guidance)
- Per-service setup instructions (e.g., "Gmail requires an App Password")
- Only include for credentials that need special configuration
- Each note has a "service" name and "instructions" text

### Keywords (OPTIONAL — include for discoverability)
- Explicit trigger words and synonyms that aid skill matching
- Complements tags with more natural language terms
- Example: ["branding", "corporate identity", "visual formatting", "company colors"]

### Notes (OPTIONAL — include for gotchas or important tips)
- Important tips users should know
- Gotchas or common mistakes
- Performance considerations
- Example: ["Use App Password for Gmail, not your regular password", "Large PDFs may take several minutes to process"]

### Example Request
- A short, natural sentence (max ~100 chars) a user would type to trigger this skill
- Must be GENERIC — no specific names, file paths, phone numbers, or content
- Should read like a real user request, not a description
- Examples:
  - "send a text message to confirm the appointment"
  - "create a podcast episode about PHP development"
  - "convert the attached PDF to a Word document"

### Reference Commands
- Generalized versions of the KEY shell commands needed, with {{PLACEHOLDER}} variables
- ONLY the essential commands — NOT the full execution transcript
- Replace all specific values (file paths, content, names) with descriptive placeholders
- Keep it SHORT: 3-8 lines maximum, one command per logical step
- If bundled scripts cover the workflow, return an empty string ""
- NEVER include large heredocs, full file contents, or verbose output
- Example: "say -v Samantha -f {{SCRIPT_FILE}} -o {{OUTPUT_NAME}}.aiff\nffmpeg -i {{OUTPUT_NAME}}.aiff -c:a libmp3lame -q:a 2 {{OUTPUT_NAME}}.mp3"

BAD example (everything wrong):
{"name": "send-an-sms-saying-boy-time-to-go", "description": "Repeatable workflow to send-an-sms-saying-boy-time-to-go. Use when asked to perform similar tasks.", "procedure": "1. Identify input\n2. Follow workflow\n3. Generate output\n4. Validate"}

GOOD example (what to produce):
{"name": "send-sms", "description": "Send SMS text messages to any phone number using the Twilio API. Use this skill when the user asks to send a text, SMS, text message, or notify someone via phone. Supports custom message content and any recipient number in E.164 format.", "overview": "", "when_to_use": "Use this skill when the user asks to:\n- Send an SMS or text message\n- Text someone a message\n- Notify someone via SMS\n- Send a Twilio message", "procedure": "1. Retrieve Twilio credentials: use `get_keys` with keys `[twilio_account_sid, twilio_auth_token, twilio_phone_number]`\n2. Get recipient phone number and message content from user if not provided (use `ask_user`)\n3. Send SMS using bundled script: `bash scripts/run.sh <to_phone> <message_body>`\n4. Verify response contains 'sid' field indicating successful queue\n5. Report delivery status to user", "output_format": "", "example_request": "send a text message to confirm the appointment", "reference_commands": "bash scripts/run.sh {{TO_PHONE}} {{MESSAGE_BODY}}", "setup_notes": [{"service": "Twilio", "instructions": "1. Create a Twilio account at twilio.com\n2. Get your Account SID and Auth Token from the dashboard\n3. Purchase or verify a phone number for sending"}], "keywords": ["text", "sms", "message", "twilio", "phone", "notify"], "notes": ["Phone numbers must be in E.164 format (e.g., +1234567890)", "Twilio trial accounts can only send to verified numbers"]}

Respond with ONLY the JSON object. No markdown fences, no explanation, no commentary.
PROMPT;
    }
}
