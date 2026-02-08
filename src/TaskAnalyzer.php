<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot;

use ClaudeAgents\Agent;
use Dalehurley\Phpbot\Platform;

class TaskAnalyzer
{
    /**
     * @param \Closure(): \ClaudePhp\ClaudePhp $clientFactory
     */
    public function __construct(
        private \Closure $clientFactory,
        private string $model
    ) {}

    public function analyze(string $input): array
    {
        $client = ($this->clientFactory)();

        $analysisAgent = Agent::create($client)
            ->withName('task_analyzer')
            ->withSystemPrompt($this->getSystemPrompt())
            ->withModel($this->model)
            ->maxIterations(1)
            ->maxTokens(2048);

        $result = $analysisAgent->run("Analyze this task and respond with JSON only:\n\n{$input}");

        $analysis = json_decode($result->getAnswer(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->fallbackAnalysis($input);
        }

        return $analysis;
    }

    private function fallbackAnalysis(string $input): array
    {
        $lower = strtolower($input);

        $requiresRealWorldEffect = str_contains($lower, 'say ') ||
            str_contains($lower, 'speak') ||
            str_contains($lower, 'out loud') ||
            str_contains($lower, 'talk') ||
            str_contains($lower, 'read aloud') ||
            str_contains($lower, 'announce') ||
            str_contains($lower, 'play sound') ||
            str_contains($lower, 'play audio') ||
            str_contains($lower, 'text to speech') ||
            str_contains($lower, 'text-to-speech') ||
            str_contains($lower, 'notification') ||
            str_contains($lower, 'clipboard') ||
            str_contains($lower, 'open browser') ||
            str_contains($lower, 'open url') ||
            str_contains($lower, 'send ') ||
            str_contains($lower, 'call ') ||
            str_contains($lower, 'email ') ||
            str_contains($lower, 'show me') ||
            str_contains($lower, 'display');

        return [
            'task_type' => $requiresRealWorldEffect ? 'automation' : 'general',
            'complexity' => 'medium',
            'requires_bash' => $requiresRealWorldEffect ||
                str_contains($lower, 'run') ||
                str_contains($lower, 'execute') ||
                str_contains($lower, 'command') ||
                str_contains($lower, 'install'),
            'requires_file_ops' => str_contains($lower, 'file') ||
                str_contains($lower, 'read') ||
                str_contains($lower, 'write') ||
                str_contains($lower, 'create') ||
                str_contains($lower, 'save'),
            'requires_tool_creation' => str_contains($lower, 'create tool') ||
                str_contains($lower, 'new tool') ||
                str_contains($lower, 'build tool'),
            'requires_real_world_effect' => $requiresRealWorldEffect,
            'real_world_effect' => $requiresRealWorldEffect ? 'Action with observable outcome beyond text' : null,
            'creative_approaches' => [],
            'definition_of_done' => $requiresRealWorldEffect
                ? ['The requested action was actually performed (not just described)', 'Outcome verified via tool output']
                : ['Task completed successfully'],
            'suggested_approach' => 'direct',
            'estimated_steps' => $requiresRealWorldEffect ? 3 : 1,
            'potential_tools_needed' => $requiresRealWorldEffect ? ['bash'] : [],
        ];
    }

    private function getSystemPrompt(): string
    {
        $osName = Platform::osName();
        $tts = Platform::ttsCommand();
        $audio = Platform::audioPlayCommand();
        $notify = Platform::isMacOS() ? 'osascript' : 'notify-send';
        $clip = Platform::isMacOS() ? 'pbcopy' : 'xclip';
        $open = Platform::openCommand();
        $screenshot = Platform::screenshotCommand();

        return <<<PROMPT
You are a task analyzer for an AI agent running on {$osName} that has access to a full computer via bash, can call any API with curl, can write and run code in any language, and can interact with the OS (audio, display, clipboard, network, etc.).

Analyze the user's request and output a JSON object with the following structure:

{
    "task_type": "general|coding|research|automation|data_processing|problem_solving",
    "complexity": "simple|medium|complex",
    "requires_bash": true/false,
    "requires_file_ops": true/false,
    "requires_tool_creation": true/false,
    "requires_planning": true/false,
    "requires_reflection": true/false,
    "requires_real_world_effect": true/false,
    "real_world_effect": "string describing the physical/external outcome, or null",
    "creative_approaches": ["approach 1", "approach 2"],
    "definition_of_done": ["list", "of", "completion", "criteria"],
    "suggested_approach": "direct|plan_execute|reflection|chain_of_thought",
    "estimated_steps": number,
    "potential_tools_needed": ["bash", "file_system", "custom_tool_name"]
}

IMPORTANT CONTEXT — THE AGENT CAN DO ALMOST ANYTHING:
The agent runs on a real {$osName} computer with bash access. It can:
- Run ANY shell command
- Install packages via brew, pip, npm, apt-get
- Call ANY API on the internet via curl (OpenAI, Twilio, Slack, weather, etc.)
- Write and execute scripts in Python, Node.js, PHP, bash, etc.
- Interact with the OS: play audio (`{$tts}`, `{$audio}`), show notifications (`{$notify}`), copy to clipboard (`{$clip}`), open files/URLs (`{$open}`), take screenshots (`{$screenshot}`), and more
- Read/write/create any file on the filesystem
- Search for existing credentials on the machine

When analyzing, THINK CREATIVELY about how the agent could accomplish the task using these capabilities.

REQUIRES_REAL_WORLD_EFFECT:
Set to true when the task requires an outcome BEYOND just producing text. Examples:
- Speaking/audio output (say out loud, play sound, text-to-speech) → requires bash `{$tts}`, `{$audio}`, or API-generated audio
- Sending a message (SMS, email, Slack) → requires API calls
- Opening something (URL, file, app) → requires `{$open}` command
- Modifying system state (clipboard, notifications, settings) → requires OS commands
- Creating visible artifacts (images, PDFs, files the user can see) → requires file creation + `{$open}`

CRITICAL: "say hello out loud", "speak", "talk", "announce" = PRODUCE REAL AUDIO from speakers, not just print text.
CRITICAL: "send an SMS", "call someone", "email" = ACTUALLY SEND IT via API, not just describe it.
CRITICAL: "show me", "open", "display" = ACTUALLY OPEN/DISPLAY IT, not just describe it.

CREATIVE_APPROACHES:
List 1-3 practical approaches the agent could use, from simplest to most sophisticated. Think outside the box.
Examples:
- "say hello out loud" → ["built-in `{$tts}` command", "OpenAI TTS API to generate and play MP3"]
- "what's the weather" → ["curl wttr.in for quick text forecast", "call OpenWeatherMap API for detailed data"]
- "translate this to French" → ["use python googletrans library", "call DeepL or Google Translate API via curl"]
- "send a message to Slack" → ["curl Slack webhook URL", "use Slack API with bot token"]
- "make this PDF" → ["write HTML then convert with wkhtmltopdf", "use Python reportlab library"]

COMPLEXITY GUIDELINES:
- "simple": Single-action tasks (e.g., "say hello", "list files", "open google.com")
- "medium": Multi-step tasks, external tool integration, APIs (e.g., "send an SMS", "fetch weather data", "create a design")
- "complex": Multi-stage workflows, complex data processing, multiple integrations (e.g., "build a PDF report from CSV", "deploy an app")

ESTIMATED_STEPS should reflect the actual number of discrete tool calls needed, including:
- Checking for prerequisites/credentials (get_keys, search_computer)
- Gathering user input (ask_user)
- Executing main task (bash, write_file, etc.)
- Verification/finalization steps

Respond with ONLY the JSON object, no additional text.
PROMPT;
    }
}
