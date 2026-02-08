<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Apple;

use Dalehurley\Phpbot\Stats\TokenLedger;

/**
 * Simple on-device agent that handles bash-only tasks via Apple FM.
 *
 * For tasks that only need 1-2 bash commands and a formatted response,
 * this agent bypasses Claude entirely:
 *
 * 1. Asks Apple FM what bash command(s) to run for the user's request
 * 2. Executes the commands locally
 * 3. Sends the results back to Apple FM for formatting
 * 4. Returns the formatted answer
 *
 * Result: zero Claude tokens for simple tasks like "what's my RAM",
 * "what's my disk usage", "list running processes", etc.
 *
 * Limitations (by design, keeps tasks within Apple FM's 4096 token window):
 * - Max 2 bash commands
 * - Max 4000 chars of combined output
 * - Falls back to Claude if output is too large or commands fail
 */
class AppleFMSimpleAgent
{
    /** Max combined bash output chars to send to Apple FM for formatting. */
    private const MAX_OUTPUT_CHARS = 4000;

    /** Max raw output chars before summarization (skill-aware mode). */
    private const MAX_RAW_OUTPUT_CHARS = 20000;

    /** Max commands Apple FM can request. */
    private const MAX_COMMANDS = 2;

    /** Optional logging callback. */
    private ?\Closure $logger = null;

    public function __construct(
        private SmallModelClient $appleFM,
        private ?TokenLedger $ledger = null,
    ) {}

    /**
     * Set an optional logger.
     */
    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Check if this agent can handle a given request.
     *
     * Returns true for tasks that are simple enough for Apple FM:
     * - Only needs bash tool
     * - Simple complexity
     * - Apple FM is available
     */
    public function canHandle(array $tools, string $complexity): bool
    {
        if (!$this->appleFM->isAvailable()) {
            return false;
        }

        // Only handle tasks that just need bash
        $toolNames = array_map(fn($t) => is_string($t) ? $t : ($t->getName() ?? ''), $tools);
        $hasBash = in_array('bash', $toolNames, true);
        $isSimple = in_array($complexity, ['simple', 'trivial'], true);

        // Only bash, and max 2 other tools (like search_capabilities)
        $nonBashTools = array_filter($toolNames, fn($t) => !in_array($t, ['bash', 'search_capabilities'], true));

        return $hasBash && $isSimple && count($nonBashTools) === 0;
    }

    /**
     * Execute a simple task entirely on-device.
     *
     * Returns the formatted response, or null if the task couldn't be
     * handled locally (caller should fall back to Claude).
     */
    public function execute(string $userInput): ?string
    {
        try {
            $this->log("Attempting on-device execution: {$userInput}");

            // Step 1: Ask Apple FM what bash commands to run
            $commands = $this->planCommands($userInput);

            if (empty($commands)) {
                $this->log('No commands planned, falling back to Claude');

                return null;
            }

            if (count($commands) > self::MAX_COMMANDS) {
                $this->log('Too many commands (' . count($commands) . '), falling back to Claude');

                return null;
            }

            // Step 2: Execute the commands
            $results = [];
            $totalOutputChars = 0;

            foreach ($commands as $command) {
                $result = $this->executeBash($command);
                $outputLen = strlen($result['stdout'] ?? '') + strlen($result['stderr'] ?? '');
                $totalOutputChars += $outputLen;

                // If output is too large for Apple FM's context, fall back
                if ($totalOutputChars > self::MAX_OUTPUT_CHARS) {
                    $this->log("Output too large ({$totalOutputChars} chars), falling back to Claude");

                    return null;
                }

                // If command failed with non-trivial error, let Claude handle it
                if ($result['exit_code'] !== 0 && !empty($result['stderr'])) {
                    $this->log("Command failed: {$command}, falling back to Claude");

                    return null;
                }

                $results[] = $result;
            }

            // Step 3: Format the results via Apple FM
            $formattedResponse = $this->formatResults($userInput, $commands, $results);

            if ($formattedResponse === null || strlen($formattedResponse) < 10) {
                $this->log('Formatting failed or empty, falling back to Claude');

                return null;
            }

            $this->log('Successfully handled on-device');

            return $formattedResponse;
        } catch (\Throwable $e) {
            $this->log("On-device execution failed: {$e->getMessage()}, falling back to Claude");

            return null;
        }
    }

    /**
     * Execute a skill-based task entirely on-device.
     *
     * Like execute(), but uses the skill's instructions to plan commands
     * more accurately. Also handles large output by summarizing it before
     * formatting, which lets it handle tasks like weather (14K char output)
     * that would normally exceed Apple FM's context window.
     *
     * @param string $userInput The user's request
     * @param string $skillInstructions Optimized/condensed skill instructions
     * @param string $skillName Name of the matched skill
     * @return string|null Formatted response, or null to fall back to Claude
     */
    public function executeWithSkill(string $userInput, string $skillInstructions, string $skillName): ?string
    {
        try {
            $this->log("Attempting on-device skill execution: {$skillName}");

            // Step 1: Plan commands using skill instructions
            $commands = $this->planSkillCommands($userInput, $skillInstructions);

            if (empty($commands)) {
                $this->log('No commands planned from skill, falling back to Claude');

                return null;
            }

            if (count($commands) > self::MAX_COMMANDS) {
                $this->log('Too many commands (' . count($commands) . '), falling back to Claude');

                return null;
            }

            // Step 2: Execute the commands
            $results = [];
            $totalOutputChars = 0;

            foreach ($commands as $command) {
                $result = $this->executeBash($command);
                $outputLen = strlen($result['stdout'] ?? '') + strlen($result['stderr'] ?? '');
                $totalOutputChars += $outputLen;

                // Even with summarization, cap at a reasonable raw limit
                if ($totalOutputChars > self::MAX_RAW_OUTPUT_CHARS) {
                    $this->log("Output too large even for summarization ({$totalOutputChars} chars), falling back to Claude");

                    return null;
                }

                // If command failed, let Claude handle it
                if ($result['exit_code'] !== 0 && !empty($result['stderr'])) {
                    $this->log("Skill command failed: {$command}, falling back to Claude");

                    return null;
                }

                $results[] = $result;
            }

            // Step 3: Summarize large output before formatting
            // This is the key difference from execute() — we can handle 14K+ output
            // by summarizing it to fit within Apple FM's context window
            foreach ($results as &$result) {
                $stdoutLen = strlen($result['stdout'] ?? '');
                if ($stdoutLen > self::MAX_OUTPUT_CHARS) {
                    $this->log("Summarizing large output: {$stdoutLen} chars");
                    $context = "Output from bash command: {$result['command']}";
                    $result['stdout'] = $this->appleFM->summarize(
                        $result['stdout'],
                        $context,
                        384,
                    );
                    $this->log('Summarized to ' . strlen($result['stdout']) . ' chars');
                }
            }
            unset($result);

            // Step 4: Format the results via Apple FM
            $formattedResponse = $this->formatResults($userInput, $commands, $results);

            if ($formattedResponse === null || strlen($formattedResponse) < 10) {
                $this->log('Skill formatting failed or empty, falling back to Claude');

                return null;
            }

            $this->log("Successfully handled skill '{$skillName}' on-device");

            return $formattedResponse;
        } catch (\Throwable $e) {
            $this->log("On-device skill execution failed: {$e->getMessage()}, falling back to Claude");

            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Step 1: Plan commands
    // -------------------------------------------------------------------------

    /**
     * Ask Apple FM what bash commands to run for the user's request.
     *
     * @return string[] Array of bash commands to execute
     */
    private function planCommands(string $userInput): array
    {
        $instructions = 'You are a command planner. Given a user request, output ONLY the bash '
            . 'commands needed to answer it, one per line. Output ONLY commands, no explanation, '
            . 'no markdown, no numbering. Maximum 2 commands.';

        $response = $this->appleFM->call(
            "User request: {$userInput}\n\nBash commands:",
            128,
            'agent_planning',
            $instructions,
        );

        // Parse the response: each non-empty line is a command
        $lines = array_filter(
            array_map('trim', explode("\n", $response)),
            fn($line) => $line !== '' && !str_starts_with($line, '#') && !str_starts_with($line, '//')
        );

        // Basic safety: reject dangerous commands
        $safe = [];
        foreach ($lines as $line) {
            // Remove any numbering prefixes like "1. " or "- "
            $line = preg_replace('/^[\d]+\.\s*/', '', $line);
            $line = preg_replace('/^[-*]\s*/', '', $line);
            $line = trim($line, '`');
            $line = trim($line);

            if ($line === '' || $this->isDangerous($line)) {
                continue;
            }

            $safe[] = $line;
        }

        return array_slice($safe, 0, self::MAX_COMMANDS);
    }

    /**
     * Plan commands using skill instructions for better accuracy.
     *
     * The skill instructions tell Apple FM exactly what command to run
     * (e.g. "curl wttr.in/{{LOCATION}}"), making this much more reliable
     * than generic command planning.
     *
     * @return string[] Array of bash commands to execute
     */
    private function planSkillCommands(string $userInput, string $skillInstructions): array
    {
        // Step 1: Extract parameters from the user input using Apple FM.
        // This is a simple extraction task: "weather in London" -> "London".
        // We do this separately because the small model is much more reliable
        // at extraction than multi-step "extract AND substitute AND output command".
        $processed = $this->substituteSkillPlaceholders($userInput, $skillInstructions);

        // Step 2: Ask Apple FM to output the final bash commands.
        // At this point, placeholders should already be replaced (e.g. "curl wttr.in/London"),
        // so Apple FM just needs to output the command as-is.
        $instructions = 'You are a command planner. The skill procedure below contains ready-to-run '
            . 'bash commands with all values already filled in. Output ONLY the bash commands, '
            . 'one per line. No explanation, no markdown, no numbering. Maximum 2 commands.';

        $prompt = "User request: {$userInput}\n\nSkill procedure:\n{$processed}\n\nBash commands:";

        $response = $this->appleFM->call(
            $prompt,
            128,
            'agent_planning',
            $instructions,
        );

        // Parse the response: each non-empty line is a command
        $lines = array_filter(
            array_map('trim', explode("\n", $response)),
            fn($line) => $line !== '' && !str_starts_with($line, '#') && !str_starts_with($line, '//')
        );

        // Basic safety: reject dangerous commands and unsubstituted placeholders
        $safe = [];
        foreach ($lines as $line) {
            $line = preg_replace('/^[\d]+\.\s*/', '', $line);
            $line = preg_replace('/^[-*]\s*/', '', $line);
            $line = trim($line, '`');
            $line = trim($line);

            if ($line === '' || $this->isDangerous($line)) {
                continue;
            }

            // Reject commands with unsubstituted placeholders
            if (preg_match('/\{\{[A-Z_]+\}\}|\$\{[A-Z_]+\}/', $line)) {
                $this->log("Rejecting command with unsubstituted placeholder: {$line}");

                continue;
            }

            $safe[] = $line;
        }

        return array_slice($safe, 0, self::MAX_COMMANDS);
    }

    /**
     * Extract parameter values from user input and substitute placeholders.
     *
     * Uses Apple FM to extract the right value (e.g. "London" from "weather in London"),
     * then does the substitution in PHP. This is much more reliable than asking Apple FM
     * to both extract and substitute in one step.
     *
     * @return string Skill instructions with placeholders replaced by extracted values
     */
    private function substituteSkillPlaceholders(string $userInput, string $skillInstructions): string
    {
        // Find all placeholders: {{LOCATION}}, ${LOCATION}, {LOCATION}
        $placeholders = [];
        if (preg_match_all('/\{\{([A-Z_]+)\}\}|\$\{([A-Z_]+)\}|\{([A-Z_]+)\}/', $skillInstructions, $matches)) {
            foreach ($matches[0] as $i => $fullMatch) {
                // Get the parameter name from whichever capture group matched
                $name = $matches[1][$i] !== '' ? $matches[1][$i]
                    : ($matches[2][$i] !== '' ? $matches[2][$i] : $matches[3][$i]);
                $placeholders[$fullMatch] = $name;
            }
        }

        if (empty($placeholders)) {
            return $skillInstructions;
        }

        // Ask Apple FM to extract each unique parameter name
        $uniqueNames = array_unique(array_values($placeholders));
        $paramList = implode(', ', $uniqueNames);

        $extractPrompt = "From this user request, extract the value for: {$paramList}\n\n"
            . "User request: \"{$userInput}\"\n\n"
            . "Respond with ONLY the extracted value, nothing else. "
            . "For example, if the request is \"weather in London\" and the parameter is LOCATION, respond: London";

        $extracted = trim($this->appleFM->call(
            $extractPrompt,
            64,
            'param_extraction',
            'You extract parameter values from user requests. Respond with ONLY the value, no quotes, no explanation.',
        ));

        if ($extracted === '') {
            $this->log('Parameter extraction returned empty, using original instructions');

            return $skillInstructions;
        }

        $this->log("Extracted parameter value: \"{$extracted}\" from \"{$userInput}\"");

        // URL-encode spaces for URL-like contexts (e.g. "New York" -> "New+York")
        $urlSafe = str_replace(' ', '+', $extracted);

        // Replace all placeholders with the extracted value
        $result = $skillInstructions;
        foreach ($placeholders as $placeholder => $name) {
            // For placeholders that appear in URL contexts, use URL-safe version
            if (preg_match('#(https?://|wttr\.in/|curl\s+\S*)' . preg_quote($placeholder, '#') . '#', $result)) {
                $result = str_replace($placeholder, $urlSafe, $result);
            } else {
                $result = str_replace($placeholder, $extracted, $result);
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Step 2: Execute bash
    // -------------------------------------------------------------------------

    /**
     * Execute a bash command and return the result.
     *
     * @return array{command: string, exit_code: int, stdout: string, stderr: string}
     */
    private function executeBash(string $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            getcwd(),
            null,
        );

        if (!is_resource($process)) {
            return [
                'command' => $command,
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'Failed to execute command',
            ];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'command' => $command,
            'exit_code' => $exitCode,
            'stdout' => trim($stdout ?: ''),
            'stderr' => trim($stderr ?: ''),
        ];
    }

    // -------------------------------------------------------------------------
    // Step 3: Format results
    // -------------------------------------------------------------------------

    /**
     * Send command results to Apple FM for formatting into a user-friendly response.
     */
    private function formatResults(string $userInput, array $commands, array $results): ?string
    {
        $instructions = 'You are a helpful assistant. Format the command output into a clear, '
            . 'well-structured response to the user\'s question. Use markdown formatting. '
            . 'Be concise but complete. Include key data points and numbers.';

        $resultSections = [];
        foreach ($results as $result) {
            $section = "Command: {$result['command']}\n";
            if ($result['exit_code'] !== 0) {
                $section .= "Exit code: {$result['exit_code']}\n";
            }
            if ($result['stdout'] !== '') {
                $section .= "Output:\n{$result['stdout']}\n";
            }
            if ($result['stderr'] !== '') {
                $section .= "Stderr:\n{$result['stderr']}\n";
            }
            $resultSections[] = $section;
        }

        $commandOutput = implode("\n---\n", $resultSections);

        $prompt = "User question: {$userInput}\n\nCommand results:\n{$commandOutput}\n\nFormatted response:";

        return $this->appleFM->call($prompt, 512, 'agent_formatting', $instructions);
    }

    // -------------------------------------------------------------------------
    // Safety
    // -------------------------------------------------------------------------

    /**
     * Check if a command is dangerous.
     */
    private function isDangerous(string $command): bool
    {
        $dangerous = [
            'rm -rf',
            'rm -r /',
            'mkfs',
            'dd if=',
            ':(){:|:&};:',
            '> /dev/sda',
            'chmod -R 777 /',
            'sudo rm',
            'format',
        ];

        $lower = strtolower($command);

        foreach ($dangerous as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)("AppleFM Agent: {$message}");
        }
    }
}
