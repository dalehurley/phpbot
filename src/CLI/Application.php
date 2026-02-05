<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\CLI;

use Dalehurley\Phpbot\Bot;
use Dalehurley\Phpbot\Storage\KeyStore;

class Application
{
    private array $config;
    private ?Bot $bot = null;
    private ?KeyStore $keyStore = null;
    private bool $verbose = false;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function run(array $argv): int
    {
        $args = $this->parseArguments($argv);
        $this->verbose = $args['verbose'];

        // Handle special commands
        if ($args['help']) {
            $this->showHelp();
            return 0;
        }

        if ($args['version']) {
            $this->showVersion();
            return 0;
        }

        if ($args['list-tools']) {
            return $this->listTools($args['verbose']);
        }

        // Ensure API key is available
        if (!$this->ensureApiKey()) {
            return 1;
        }

        // Initialize bot
        $this->bot = new Bot($this->config, $args['verbose']);

        // Handle different modes
        if ($args['interactive']) {
            return $this->runInteractiveMode();
        }

        if (!empty($args['input'])) {
            return $this->runSingleCommand($args['input'], $args['verbose']);
        }

        // Default to interactive mode if no input
        return $this->runInteractiveMode();
    }

    private function parseArguments(array $argv): array
    {
        $args = [
            'help' => false,
            'version' => false,
            'verbose' => false,
            'interactive' => false,
            'list-tools' => false,
            'input' => '',
        ];

        array_shift($argv); // Remove script name
        $input = [];

        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];

            switch ($arg) {
                case '-h':
                case '--help':
                    $args['help'] = true;
                    break;
                case '-V':
                case '--version':
                    $args['version'] = true;
                    break;
                case '-v':
                case '--verbose':
                    $args['verbose'] = true;
                    break;
                case '-i':
                case '--interactive':
                    $args['interactive'] = true;
                    break;
                case '-l':
                case '--list-tools':
                    $args['list-tools'] = true;
                    break;
                case '-c':
                case '--command':
                    if (isset($argv[$i + 1])) {
                        $args['input'] = $argv[++$i];
                    }
                    break;
                default:
                    if (!str_starts_with($arg, '-')) {
                        $input[] = $arg;
                    }
                    break;
            }
        }

        if (empty($args['input']) && !empty($input)) {
            $args['input'] = implode(' ', $input);
        }

        return $args;
    }

    private function showHelp(): void
    {
        $help = <<<HELP
PhpBot - An evolving AI assistant powered by Claude

Usage:
  phpbot [options] [command]
  phpbot -c "your command here"
  phpbot -i  (interactive mode)

Options:
  -h, --help         Show this help message
  -V, --version      Show version information
  -v, --verbose      Enable verbose output
  -i, --interactive  Run in interactive mode
  -l, --list-tools   List all available tools
  -c, --command      Run a single command

Examples:
  phpbot "What files are in the current directory?"
  phpbot -v "Create a PHP class that validates email addresses"
  phpbot -c "Run the tests and fix any failures"
  phpbot -i

Environment Variables:
  ANTHROPIC_API_KEY  Your Anthropic API key (required)

HELP;
        echo $help;
    }

    private function showVersion(): void
    {
        echo "PhpBot v1.0.0\n";
        echo "Powered by claude-php/agent\n";
    }

    private function listTools(bool $verbose): int
    {
        $this->bot = new Bot($this->config, false);

        $this->output("\n🔧 Available Tools:\n");
        $this->output(str_repeat('-', 50) . "\n");

        $tools = $this->bot->listTools();

        foreach ($tools as $name) {
            $this->output("  • {$name}\n");
        }

        $customTools = $this->bot->getToolRegistry()->listCustomTools();

        if (!empty($customTools)) {
            $this->output("\n📦 Custom Tools:\n");
            $this->output(str_repeat('-', 50) . "\n");

            foreach ($customTools as $tool) {
                $this->output("  • {$tool['name']}\n");
                if ($verbose) {
                    $this->output("    {$tool['description']}\n");
                    $this->output("    Category: {$tool['category']}\n");
                    if (!empty($tool['parameters'])) {
                        $this->output("    Parameters:\n");
                        foreach ($tool['parameters'] as $param) {
                            $required = $param['required'] ? ' (required)' : '';
                            $this->output("      - {$param['name']}: {$param['type']}{$required}\n");
                        }
                    }
                    $this->output("\n");
                }
            }
        }

        $this->output("\n");
        return 0;
    }

    private function runInteractiveMode(): int
    {
        $this->output("\n");
        $this->output("╔══════════════════════════════════════════════════════════╗\n");
        $this->output("║                    PhpBot Interactive                     ║\n");
        $this->output("║           An evolving AI assistant for PHP               ║\n");
        $this->output("╠══════════════════════════════════════════════════════════╣\n");
        $this->output("║  Commands:                                               ║\n");
        $this->output("║    /help     - Show help                                 ║\n");
        $this->output("║    /tools    - List available tools                      ║\n");
        $this->output("║    /skills   - List available skills                     ║\n");
        $this->output("║    /scripts  - List skill script tools                   ║\n");
        $this->output("║    /abilities - List learned abilities                   ║\n");
        $this->output("║    /clear    - Clear screen                              ║\n");
        $this->output("║    /exit     - Exit the application                      ║\n");
        $this->output("╚══════════════════════════════════════════════════════════╝\n");
        $this->output("\n");

        while (true) {
            $input = $this->prompt('phpbot> ');

            if ($input === false || $input === null) {
                break;
            }

            $input = trim($input);

            if (empty($input)) {
                continue;
            }

            // Handle special commands
            if (str_starts_with($input, '/')) {
                $handled = $this->handleSpecialCommand($input);
                if ($handled === -1) {
                    break; // Exit
                }
                continue;
            }

            // Run the command
            $this->executeCommand($input);
        }

        $this->output("\nGoodbye! 👋\n");
        return 0;
    }

    private function handleSpecialCommand(string $command): int
    {
        $parts = explode(' ', $command, 2);
        $cmd = strtolower($parts[0]);

        return match ($cmd) {
            '/help' => $this->showInteractiveHelp(),
            '/tools' => $this->showTools(),
            '/skills' => $this->showSkills(),
            '/scripts' => $this->showScripts(),
            '/abilities' => $this->showAbilities(),
            '/clear' => $this->clearScreen(),
            '/exit', '/quit', '/q' => -1,
            default => $this->unknownCommand($cmd),
        };
    }

    private function showInteractiveHelp(): int
    {
        $this->output("\n📖 Interactive Help:\n");
        $this->output(str_repeat('-', 50) . "\n");
        $this->output("  Just type your request and press Enter.\n");
        $this->output("  PhpBot will analyze your request, select the\n");
        $this->output("  appropriate tools, and execute the task.\n\n");
        $this->output("  Special Commands:\n");
        $this->output("    /help     - Show this help\n");
        $this->output("    /tools    - List available tools\n");
        $this->output("    /skills   - List available skills\n");
        $this->output("    /scripts  - List skill script tools\n");
        $this->output("    /abilities - List learned abilities\n");
        $this->output("    /clear    - Clear screen\n");
        $this->output("    /exit     - Exit the application\n\n");
        $this->output("  Examples:\n");
        $this->output("    • List all PHP files in current directory\n");
        $this->output("    • Create a tool that fetches weather data\n");
        $this->output("    • Run the unit tests and summarize results\n");
        $this->output("\n");
        return 0;
    }

    private function showSkills(): int
    {
        if ($this->bot === null) {
            $this->bot = new Bot($this->config, false);
        }

        $skills = $this->bot->listSkills();

        $this->output("\n🧰 Available Skills:\n");
        $this->output(str_repeat('-', 50) . "\n");

        if (empty($skills)) {
            $this->output("  (no skills found)\n\n");
            return 0;
        }

        foreach ($skills as $skill) {
            $this->output("  • {$skill['name']}\n");
            if (!empty($skill['description'])) {
                $this->output("    {$skill['description']}\n");
            }
        }

        $this->output("\n");
        return 0;
    }

    private function showScripts(): int
    {
        if ($this->bot === null) {
            $this->bot = new Bot($this->config, false);
        }

        $scripts = $this->bot->listSkillScripts();

        $this->output("\n🧩 Skill Script Tools:\n");
        $this->output(str_repeat('-', 50) . "\n");

        if (empty($scripts)) {
            $this->output("  (no script tools found)\n\n");
            return 0;
        }

        foreach ($scripts as $tool) {
            $this->output("  • {$tool['name']}\n");
            if (!empty($tool['description'])) {
                $this->output("    {$tool['description']}\n");
            }
        }

        $this->output("\n");
        return 0;
    }

    private function showAbilities(): int
    {
        if ($this->bot === null) {
            $this->bot = new Bot($this->config, false);
        }

        $abilities = $this->bot->listAbilities();

        $this->output("\n🧠 Learned Abilities:\n");
        $this->output(str_repeat('-', 50) . "\n");

        if (empty($abilities)) {
            $this->output("  (no abilities learned yet)\n");
            $this->output("  Abilities are discovered when the bot\n");
            $this->output("  overcomes obstacles during task execution.\n\n");
            return 0;
        }

        foreach ($abilities as $ability) {
            $this->output("  • {$ability['title']}\n");
            if (!empty($ability['description'])) {
                $this->output("    {$ability['description']}\n");
            }
            if (!empty($ability['obstacle'])) {
                $this->output("    Obstacle: {$ability['obstacle']}\n");
            }
            if (!empty($ability['strategy'])) {
                $this->output("    Strategy: {$ability['strategy']}\n");
            }
            if (!empty($ability['tags'])) {
                $this->output("    Tags: " . implode(', ', $ability['tags']) . "\n");
            }
            $this->output("\n");
        }

        $this->output("  Total: " . count($abilities) . " abilities\n\n");
        return 0;
    }

    private function showTools(): int
    {
        $this->listTools(false);
        return 0;
    }

    private function clearScreen(): int
    {
        system('clear');
        return 0;
    }

    private function unknownCommand(string $cmd): int
    {
        $this->error("Unknown command: {$cmd}. Type /help for available commands.\n");
        return 0;
    }

    private function runSingleCommand(string $input, bool $verbose): int
    {
        return $this->executeCommand($input) ? 0 : 1;
    }

    private function executeCommand(string $input, bool $retryUnauthorized = true): bool
    {
        $this->output("\n");
        $this->output("⏳ Processing your request...\n");

        try {
            $startTime = microtime(true);
            $lastStage = '';

            // Progress callback to show real-time updates
            $onProgress = function (string $stage, string $message) use (&$lastStage) {
                $icon = match ($stage) {
                    'start' => '📝',
                    'analyzing' => '🔍',
                    'analyzed' => '✓',
                    'summary_before' => '🧭',
                    'skills' => '🧰',
                    'selected' => '🎯',
                    'executing' => '⚡',
                    'agent_start' => '🤖',
                    'iteration' => '💭',
                    'iteration_summary' => '🧠',
                    'tool' => '🔧',
                    'bash_call' => '🖥️',
                    'agent_complete' => '✅',
                    'complete' => '🏁',
                    'summary_after' => '📌',
                    'abilities' => '🧠',
                    'abilities_learned' => '🧠',
                    default => '→',
                };

                // Don't repeat the same stage
                if ($stage !== $lastStage) {
                    $this->output("{$icon} {$message}\n");
                    $lastStage = $stage;
                }
            };

            $result = $this->bot->run($input, $onProgress);
            $duration = round(microtime(true) - $startTime, 2);

            if ($result->isSuccess()) {
                $this->output("\n📤 Response:\n");
                $this->output(str_repeat('-', 50) . "\n");
                $this->output($result->getAnswer() . "\n");
                $this->output(str_repeat('-', 50) . "\n");

                // Show stats
                $usage = $result->getTokenUsage();
                $this->output("\n📊 Stats: {$result->getIterations()} iterations, ");
                $this->output("{$usage['total']} tokens, {$duration}s\n");

                // Show tool calls if any
                $toolCalls = $result->getToolCalls();
                if (!empty($toolCalls)) {
                    $this->output("🔧 Tools used: " . implode(', ', array_unique(array_column($toolCalls, 'tool'))) . "\n");
                }

                // Show learned abilities if any
                $learnedAbilities = $result->getLearnedAbilities();
                if (!empty($learnedAbilities)) {
                    $this->output("🧠 Abilities learned: " . implode(', ', array_map(fn($a) => $a['title'], $learnedAbilities)) . "\n");
                }

                $this->output("\n");
                return true;
            } else {
                $error = $result->getError() ?? '';
                if ($retryUnauthorized && $this->isUnauthorizedError($error)) {
                    if ($this->promptAndStoreApiKey()) {
                        $this->bot = new Bot($this->config, $this->verbose);
                        return $this->executeCommand($input, false);
                    }
                }
                $this->error("❌ Error: " . $error . "\n\n");
                return false;
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ($retryUnauthorized && $this->isUnauthorizedError($message)) {
                if ($this->promptAndStoreApiKey()) {
                    $this->bot = new Bot($this->config, $this->verbose);
                    return $this->executeCommand($input, false);
                }
            }
            $this->error("❌ Exception: " . $message . "\n\n");
            return false;
        }
    }

    private function ensureApiKey(): bool
    {
        $apiKey = $this->resolveApiKey();
        if ($apiKey !== '') {
            $this->config['api_key'] = $apiKey;
            return true;
        }

        return $this->promptAndStoreApiKey();
    }

    private function resolveApiKey(): string
    {
        $apiKey = $this->config['api_key'] ?? '';
        if (is_string($apiKey) && $apiKey !== '') {
            return $apiKey;
        }

        $envKey = getenv('ANTHROPIC_API_KEY');
        if (is_string($envKey) && $envKey !== '') {
            return $envKey;
        }

        $storedKey = $this->getKeyStore()->get('anthropic_api_key');
        return $storedKey ?? '';
    }

    private function promptAndStoreApiKey(): bool
    {
        $this->output("\n🔑 Anthropic API key required.\n");
        $key = $this->prompt('Enter ANTHROPIC_API_KEY: ');
        if ($key === false || $key === null) {
            return false;
        }

        $key = trim($key);
        if ($key === '') {
            return false;
        }

        $this->getKeyStore()->set('anthropic_api_key', $key);
        $this->config['api_key'] = $key;
        return true;
    }

    private function getKeyStore(): KeyStore
    {
        if ($this->keyStore !== null) {
            return $this->keyStore;
        }

        $path = $this->config['keys_storage_path'] ?? dirname(__DIR__, 2) . '/storage/keys.json';
        $this->keyStore = new KeyStore($path);
        return $this->keyStore;
    }

    private function isUnauthorizedError(string $message): bool
    {
        $needle = strtolower($message);
        return str_contains($needle, 'unauthorized')
            || str_contains($needle, 'expired')
            || str_contains($needle, 'invalid api key')
            || str_contains($needle, '401');
    }

    private function prompt(string $prompt): string|false
    {
        if (function_exists('readline')) {
            $line = readline($prompt);
            if ($line !== false) {
                readline_add_history($line);
            }
            return $line;
        }

        echo $prompt;
        return fgets(STDIN);
    }

    private function output(string $message): void
    {
        echo $message;
        // Flush output immediately for real-time feedback
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function error(string $message): void
    {
        fwrite(STDERR, $message);
    }
}
