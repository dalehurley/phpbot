<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\CLI;

use Dalehurley\Phpbot\Bot;
use Dalehurley\Phpbot\Conversation\ConversationHistory;
use Dalehurley\Phpbot\Conversation\ConversationLayer;
use Dalehurley\Phpbot\Platform;
use Dalehurley\Phpbot\Storage\KeyStore;

class Application
{
    private array $config;
    private ?Bot $bot = null;
    private ?KeyStore $keyStore = null;
    private bool $verbose = false;
    private FileResolver $fileResolver;
    private ?ConversationHistory $conversationHistory = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->fileResolver = new FileResolver();
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

        // First-run setup: no .env and no API key configured anywhere
        if ($this->isFirstRun()) {
            $wizard = $this->createSetupWizard();
            if (!$wizard->run()) {
                return 1;
            }
            // Reload config after setup wrote .env
            $this->reloadEnvAndConfig();
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
        // Initialize conversation history for multi-turn context
        $conversationConfig = $this->config['conversation'] ?? [];
        $defaultLayer = ConversationLayer::tryFrom($conversationConfig['default_layer'] ?? 'summarized')
            ?? ConversationLayer::Summarized;
        $this->conversationHistory = new ConversationHistory($defaultLayer, $conversationConfig['max_turns'] ?? []);

        // Attach conversation history to the bot
        $this->bot->setConversationHistory($this->conversationHistory);

        $this->output("\n");
        $this->output("╔══════════════════════════════════════════════════════════╗\n");
        $this->output("║                    PhpBot Interactive                    ║\n");
        $this->output("║           An evolving AI assistant for PHP               ║\n");
        $this->output("╠══════════════════════════════════════════════════════════╣\n");
        $this->output("║  Commands:                                               ║\n");
        $this->output("║    /help     - Show help                                 ║\n");
        $this->output("║    /setup    - Run setup wizard (configure keys & env)   ║\n");
        $this->output("║    /file     - Search & select files to attach           ║\n");
        $this->output("║    /pick     - Open native file picker dialog            ║\n");
        $this->output("║    /files    - List attached files                       ║\n");
        $this->output("║    /detach   - Remove an attached file                   ║\n");
        $this->output("║    /context  - Show conversation context info            ║\n");
        $this->output("║    /layer    - Switch context layer (basic/summarized)   ║\n");
        $this->output("║    /tools    - List available tools                      ║\n");
        $this->output("║    /skills   - List available skills                     ║\n");
        $this->output("║    /scripts  - List skill script tools                   ║\n");
        $this->output("║    /clear    - Clear screen                              ║\n");
        $this->output("║    /exit     - Exit the application                      ║\n");
        $this->output("╠══════════════════════════════════════════════════════════╣\n");
        $this->output("║  File shortcuts:                                         ║\n");
        $this->output("║    @file.txt - Attach file inline with your message      ║\n");
        $this->output("║    Drag & drop files into the terminal to attach them    ║\n");
        $this->output("╚══════════════════════════════════════════════════════════╝\n");
        $this->output("\n");

        while (true) {
            $fileCount = $this->fileResolver->count();
            $turnCount = $this->conversationHistory->getTurnCount();
            $promptParts = [];
            if ($fileCount > 0) {
                $promptParts[] = "{$fileCount} files";
            }
            if ($turnCount > 0) {
                $promptParts[] = "{$turnCount} turns";
            }
            $promptSuffix = !empty($promptParts) ? ' [' . implode(', ', $promptParts) . ']' : '';
            $promptStr = "phpbot{$promptSuffix}> ";
            $input = $this->prompt($promptStr);

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

            // Preprocess input: resolve @file references and detect drag-and-drop paths
            $input = $this->preprocessInput($input);

            if (empty($input)) {
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
        $arg = $parts[1] ?? '';

        return match ($cmd) {
            '/help' => $this->showInteractiveHelp(),
            '/setup' => $this->runSetup(),
            '/file' => $this->handleFileSearch($arg),
            '/pick' => $this->handleFilePicker(),
            '/files' => $this->showAttachedFiles(),
            '/detach' => $this->handleDetach($arg),
            '/attach' => $this->handleAttach($arg),
            '/context' => $this->showConversationContext(),
            '/layer' => $this->handleLayerSwitch($arg),
            '/tools' => $this->showTools(),
            '/skills' => $this->showSkills(),
            '/scripts' => $this->showScripts(),
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
        $this->output("    /help           - Show this help\n");
        $this->output("    /setup          - Run setup wizard (API keys, .env config)\n");
        $this->output("    /file [query]   - Search & select a file to attach\n");
        $this->output("    /pick           - Open native file picker dialog\n");
        $this->output("    /attach <path>  - Attach a file by path\n");
        $this->output("    /files          - List currently attached files\n");
        $this->output("    /detach [path]  - Remove an attached file (all if no path)\n");
        $this->output("    /context        - Show conversation history and layer\n");
        $this->output("    /layer <name>   - Switch context layer (basic/summarized/full)\n");
        $this->output("    /tools          - List available tools\n");
        $this->output("    /skills         - List available skills\n");
        $this->output("    /scripts        - List skill script tools\n");
        $this->output("    /clear          - Clear screen\n");
        $this->output("    /exit           - Exit the application\n\n");
        $this->output("  File Shortcuts:\n");
        $this->output("    @file.txt       - Attach a file inline with your message\n");
        $this->output("    @src/*.php      - Attach files matching a glob pattern\n");
        $this->output("    @\"path/with spaces/file.txt\" - Quoted paths with spaces\n");
        $this->output("    Drag & drop     - Drag files into the terminal to attach\n\n");
        $this->output("  Examples:\n");
        $this->output("    • Refactor @src/Bot.php to use dependency injection\n");
        $this->output("    • Review @src/CLI/*.php for potential improvements\n");
        $this->output("    • /file composer   (search for files matching 'composer')\n");
        $this->output("    • /pick            (open file picker, then ask a question)\n");
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
        // Preprocess file references in single-command mode too
        $input = $this->preprocessInput($input);
        return $this->executeCommand($input) ? 0 : 1;
    }

    private function executeCommand(string $input, bool $retryUnauthorized = true): bool
    {
        $this->output("\n");

        // Build the effective input with file context
        $effectiveInput = $this->buildInputWithFileContext($input);

        $this->output("⏳ Processing your request...\n");

        // Set up file logging for this run
        $logWriter = $this->createLogWriter();
        $logWriter('Prompt: ' . $input);

        // Attach file logger to the bot so internal Bot::log() messages are captured
        $this->bot->setLogger($logWriter);

        try {
            $startTime = microtime(true);
            $lastStage = '';

            // Progress callback to show real-time updates
            $onProgress = function (string $stage, string $message) use (&$lastStage, $logWriter) {
                $logWriter("Progress: {$stage} - {$message}");

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
                    default => '→',
                };

                // Don't repeat the same stage
                if ($stage !== $lastStage) {
                    $this->output("{$icon} {$message}\n");
                    $lastStage = $stage;
                }
            };

            $result = $this->bot->run($effectiveInput, $onProgress);
            $duration = round(microtime(true) - $startTime, 2);

            if ($result->isSuccess()) {
                $this->output("\n📤 Response:\n");
                $this->output(str_repeat('-', 50) . "\n");
                $this->output($result->getAnswer() . "\n");
                $this->output(str_repeat('-', 50) . "\n");

                // Show created files so the user knows where to find them
                $createdFiles = $result->getCreatedFiles();
                if (!empty($createdFiles)) {
                    $this->output("\n📁 Created Files:\n");
                    foreach ($createdFiles as $filePath) {
                        $size = file_exists($filePath) ? $this->formatFileSize(filesize($filePath)) : 'unknown';
                        $this->output("  → {$filePath} ({$size})\n");
                    }
                }

                // Show stats — use token ledger when available for rich multi-provider display
                $ledger = $result->getTokenLedger();
                $usage = $result->getTokenUsage();
                $cost = $this->estimateCostFromResult($result);

                $this->output("\n📊 Stats: {$result->getIterations()} iterations, {$duration}s, ~\${$cost}\n");

                if ($ledger !== null && $ledger->hasEntries()) {
                    $this->output($ledger->formatReport() . "\n");

                    $savings = $ledger->getSavings();
                    if ($savings['estimated_tokens_saved'] > 0 && !$this->verbose) {
                        // Savings already shown in formatReport when verbose
                    }
                } else {
                    // Fallback: single-line Anthropic-only display
                    $inputTokens = number_format($usage['input'] ?? 0);
                    $outputTokens = number_format($usage['output'] ?? 0);
                    $totalTokens = number_format($usage['total'] ?? 0);
                    $this->output("  Tokens: {$totalTokens} ({$inputTokens} in / {$outputTokens} out)\n");
                }

                // Show tool calls if any
                $toolCalls = $result->getToolCalls();
                if (!empty($toolCalls)) {
                    $toolNames = array_count_values(array_column($toolCalls, 'tool'));
                    $toolParts = [];
                    foreach ($toolNames as $name => $count) {
                        $toolParts[] = $count > 1 ? "{$name} ({$count}x)" : $name;
                    }
                    $this->output("  Tools: " . implode(', ', $toolParts) . "\n");
                }

                // Log final result summary
                $logWriter("Run completed. Success=true, iterations={$result->getIterations()}, duration={$duration}s, cost=\${$cost}");
                if (!empty($toolCalls)) {
                    $logWriter('Tools used: ' . implode(', ', array_unique(array_column($toolCalls, 'tool'))));
                }
                $logWriter('Answer: ' . ($result->getAnswer() ?? ''));

                $this->output("\n");
                return true;
            } else {
                $error = $result->getError() ?? '';
                $logWriter("Run completed. Success=false, error={$error}");

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
            $logWriter("Exception: {$message}");
            $logWriter("Trace: {$e->getTraceAsString()}");

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

    // -------------------------------------------------------------------------
    // File management commands
    // -------------------------------------------------------------------------

    /**
     * Search for files and attach the selected one.
     */
    private function handleFileSearch(string $query): int
    {
        $query = trim($query);
        $searchQuery = $query !== '' ? $query : null;

        $this->output("\n🔍 Searching for files" . ($searchQuery ? " matching '{$searchQuery}'" : '') . "...\n");

        $path = $this->fileResolver->searchFiles($searchQuery);

        if ($path === null) {
            $this->output("  (no file selected)\n\n");
            return 0;
        }

        $result = $this->fileResolver->attach($path);
        if ($result['success']) {
            $this->output("📎 Attached: {$this->fileResolver->getSummary()}\n\n");
        } else {
            $this->error("  ❌ {$result['error']}\n\n");
        }

        return 0;
    }

    /**
     * Open the native file picker dialog (macOS or Linux with zenity/kdialog).
     */
    private function handleFilePicker(): int
    {
        if (!Platform::hasFilePicker()) {
            if (Platform::isLinux()) {
                $this->error("  ❌ File picker requires zenity or kdialog. Install with: sudo apt install zenity\n");
                $this->error("     Or use /file or @path instead.\n\n");
            } else {
                $this->error("  ❌ File picker is not available on this platform. Use /file or @path instead.\n\n");
            }
            return 0;
        }

        $this->output("\n📂 Opening file picker...\n");
        $path = $this->fileResolver->openFilePicker();

        if ($path === null) {
            $this->output("  (cancelled)\n\n");
            return 0;
        }

        $result = $this->fileResolver->attach($path);
        if ($result['success']) {
            $this->output("📎 Attached: {$this->fileResolver->getSummary()}\n\n");
        } else {
            $this->error("  ❌ {$result['error']}\n\n");
        }

        return 0;
    }

    /**
     * Attach a file explicitly by path.
     */
    private function handleAttach(string $path): int
    {
        $path = trim($path);
        if ($path === '') {
            $this->error("  Usage: /attach <path>\n\n");
            return 0;
        }

        // Support glob patterns
        if (str_contains($path, '*') || str_contains($path, '?')) {
            $result = $this->fileResolver->attachGlob($path);
            if (!empty($result['attached'])) {
                $this->output("📎 Attached " . count($result['attached']) . " file(s):\n");
                $this->output($this->fileResolver->getSummary() . "\n");
            }
            foreach ($result['errors'] as $error) {
                $this->error("  ❌ {$error}\n");
            }
            if (empty($result['attached']) && empty($result['errors'])) {
                $this->error("  No files matched pattern: {$path}\n");
            }
            $this->output("\n");
            return 0;
        }

        $result = $this->fileResolver->attach($path);
        if ($result['success']) {
            $this->output("📎 Attached: {$this->fileResolver->getSummary()}\n\n");
        } else {
            $this->error("  ❌ {$result['error']}\n\n");
        }

        return 0;
    }

    /**
     * Show currently attached files.
     */
    private function showAttachedFiles(): int
    {
        $count = $this->fileResolver->count();
        $this->output("\n📎 Attached Files ({$count}):\n");
        $this->output(str_repeat('-', 50) . "\n");

        if ($count === 0) {
            $this->output("  (no files attached)\n");
            $this->output("\n  Tip: Use @file.txt, /file, /pick, or drag files into the terminal.\n");
        } else {
            $this->output($this->fileResolver->getSummary() . "\n");
        }

        $this->output("\n");
        return 0;
    }

    /**
     * Detach a file or all files.
     */
    private function handleDetach(string $path): int
    {
        $path = trim($path);

        if ($path === '' || $path === 'all') {
            $count = $this->fileResolver->count();
            $this->fileResolver->clear();
            $this->output("🗑️  Detached all {$count} file(s).\n\n");
            return 0;
        }

        if ($this->fileResolver->detach($path)) {
            $this->output("🗑️  Detached: {$path}\n\n");
        } else {
            $this->error("  ❌ File not found in attachments: {$path}\n\n");
        }

        return 0;
    }

    // -------------------------------------------------------------------------
    // Conversation context commands
    // -------------------------------------------------------------------------

    /**
     * Show current conversation context info.
     */
    private function showConversationContext(): int
    {
        if ($this->conversationHistory === null) {
            $this->output("\n💬 Conversation context not available (single-command mode).\n\n");
            return 0;
        }

        $turnCount = $this->conversationHistory->getTurnCount();
        $layer = $this->conversationHistory->getActiveLayer();

        $this->output("\n💬 Conversation Context:\n");
        $this->output(str_repeat('-', 50) . "\n");
        $this->output("  Turns:  {$turnCount}\n");
        $this->output("  Layer:  {$layer->label()}\n");

        if ($turnCount === 0) {
            $this->output("\n  (no conversation history yet)\n");
        } else {
            $this->output("\n  Recent turns:\n");
            $turns = $this->conversationHistory->getTurns();
            $show = array_slice($turns, -5); // Show last 5
            $startIdx = max(0, count($turns) - 5);

            foreach ($show as $i => $turn) {
                $num = $startIdx + $i + 1;
                $status = $turn->isSuccess() ? 'ok' : 'err';
                $userPreview = mb_substr($turn->userInput, 0, 60);
                if (mb_strlen($turn->userInput) > 60) {
                    $userPreview .= '...';
                }
                $this->output("    #{$num} [{$status}] {$userPreview}\n");
                if ($turn->summary !== null) {
                    $summaryPreview = mb_substr($turn->summary, 0, 80);
                    if (mb_strlen($turn->summary) > 80) {
                        $summaryPreview .= '...';
                    }
                    $this->output("         {$summaryPreview}\n");
                }
            }
        }

        $this->output("\n  Tip: Use /layer <basic|summarized|full> to change context detail.\n");
        $this->output("\n");
        return 0;
    }

    /**
     * Switch the active conversation context layer.
     */
    private function handleLayerSwitch(string $layerName): int
    {
        if ($this->conversationHistory === null) {
            $this->output("\n💬 Conversation context not available (single-command mode).\n\n");
            return 0;
        }

        $layerName = trim(strtolower($layerName));

        if ($layerName === '') {
            // Show current layer and available options
            $current = $this->conversationHistory->getActiveLayer();
            $this->output("\n💬 Current context layer: {$current->label()}\n");
            $this->output("  Available layers:\n");
            foreach (ConversationLayer::cases() as $l) {
                $marker = $l === $current ? ' (active)' : '';
                $this->output("    {$l->value} — {$l->label()}{$marker}\n");
            }
            $this->output("\n  Usage: /layer <basic|summarized|full>\n\n");
            return 0;
        }

        $layer = ConversationLayer::tryFrom($layerName);

        if ($layer === null) {
            $this->error("  Unknown layer: '{$layerName}'. Use basic, summarized, or full.\n\n");
            return 0;
        }

        $previous = $this->conversationHistory->getActiveLayer();
        $this->conversationHistory->setActiveLayer($layer);
        $this->output("💬 Context layer changed: {$previous->value} -> {$layer->value} ({$layer->label()})\n\n");
        return 0;
    }

    // -------------------------------------------------------------------------
    // Input preprocessing
    // -------------------------------------------------------------------------

    /**
     * Preprocess user input to resolve file references.
     *
     * Handles:
     * - @file.txt and @path/to/file syntax
     * - @glob patterns (e.g. @src/*.php)
     * - Drag-and-drop absolute file paths
     */
    private function preprocessInput(string $input): string
    {
        $allAttached = [];
        $allErrors = [];

        // 1. Parse @file references
        if (str_contains($input, '@')) {
            $result = $this->fileResolver->parseAndAttach($input);
            $input = $result['input'];
            $allAttached = array_merge($allAttached, $result['attached']);
            $allErrors = array_merge($allErrors, $result['errors']);
        }

        // 2. Detect drag-and-drop absolute paths
        if (preg_match('#(?:^|\s)/\S+#', $input) || str_contains($input, "'/")) {
            $result = $this->fileResolver->detectDragAndDrop($input);
            $input = $result['input'];
            $allAttached = array_merge($allAttached, $result['attached']);
            $allErrors = array_merge($allErrors, $result['errors']);
        }

        // 3. Show feedback about attached files
        if (!empty($allAttached)) {
            $this->output("📎 Attached " . count($allAttached) . " file(s):\n");
            foreach ($allAttached as $path) {
                $this->output("   + {$path}\n");
            }
            $this->output("\n");
        }

        foreach ($allErrors as $error) {
            $this->error("  ⚠️  {$error}\n");
        }

        return $input;
    }

    /**
     * Build the effective input by prepending attached file context.
     */
    private function buildInputWithFileContext(string $input): string
    {
        $context = $this->fileResolver->buildContextBlock();

        if ($context === '') {
            return $input;
        }

        $fileCount = $this->fileResolver->count();
        $this->output("📄 Including {$fileCount} attached file(s) as context\n");

        return $context . "\n## User Request\n" . $input;
    }

    // -------------------------------------------------------------------------
    // Setup wizard
    // -------------------------------------------------------------------------

    /**
     * Check if this is the first run (no .env and no API key available).
     */
    private function isFirstRun(): bool
    {
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (is_file($envPath)) {
            return false;
        }

        // No .env, but maybe the user has a key via env var or KeyStore
        return $this->resolveApiKey() === '';
    }

    /**
     * Run the setup wizard (from /setup command or first run).
     */
    private function runSetup(): int
    {
        $wizard = $this->createSetupWizard();
        $wizard->run();

        // Reload config so the rest of the session uses the new values
        $this->reloadEnvAndConfig();

        // Re-initialize bot with new config if it exists
        if ($this->bot !== null) {
            $apiKey = $this->resolveApiKey();
            if ($apiKey !== '') {
                $this->config['api_key'] = $apiKey;
                $this->bot = new Bot($this->config, $this->verbose);
                if ($this->conversationHistory !== null) {
                    $this->bot->setConversationHistory($this->conversationHistory);
                }
            }
        }

        return 0;
    }

    private function createSetupWizard(): SetupWizard
    {
        $projectRoot = dirname(__DIR__, 2);

        return new SetupWizard(
            fn(string $msg) => $this->output($msg),
            fn(string $prompt) => $this->prompt($prompt),
            $projectRoot,
            $this->getKeyStore(),
        );
    }

    /**
     * Reload .env variables and config after setup writes new values.
     */
    private function reloadEnvAndConfig(): void
    {
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (!is_file($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");
            if ($key !== '') {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        // Reload config array
        $configPath = dirname(__DIR__, 2) . '/config/phpbot.php';
        if (file_exists($configPath)) {
            $this->config = require $configPath;
        }
    }

    // -------------------------------------------------------------------------
    // API key management
    // -------------------------------------------------------------------------

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

    /**
     * Estimate cost from a BotResult, using the token ledger when available.
     */
    private function estimateCostFromResult(\Dalehurley\Phpbot\BotResult $result): string
    {
        $ledger = $result->getTokenLedger();

        if ($ledger !== null && $ledger->hasEntries()) {
            $totals = $ledger->getOverallTotals();

            return number_format($totals['cost'], 4);
        }

        return $this->estimateCost($result->getTokenUsage());
    }

    /**
     * Estimate cost based on token usage and configured model.
     *
     * Pricing per million tokens (as of 2026):
     *   Haiku 4.5:  $1.00 input, $5.00 output
     *   Sonnet 4.5: $3.00 input, $15.00 output
     *   Opus 4.5:   $5.00 input, $25.00 output
     */
    private function estimateCost(array $usage): string
    {
        $model = $this->config['model'] ?? 'claude-sonnet-4-5';

        $pricing = match (true) {
            str_contains($model, 'haiku') => [1.00, 5.00],
            str_contains($model, 'opus') => [5.00, 25.00],
            default => [3.00, 15.00], // Sonnet pricing as default
        };

        $inputCost = (($usage['input'] ?? 0) / 1_000_000) * $pricing[0];
        $outputCost = (($usage['output'] ?? 0) / 1_000_000) * $pricing[1];
        $total = $inputCost + $outputCost;

        return number_format($total, 4);
    }

    /**
     * Create a log writer closure that appends timestamped lines to a run log file.
     *
     * Returns a no-op closure when logging is disabled via config.
     * Log files are stored in storage/logs/cli-{timestamp}-{id}.log.
     *
     * Controlled by PHPBOT_LOG_ENABLED (default: true) and PHPBOT_LOG_PATH.
     *
     * @return callable fn(string $message): void
     */
    private function createLogWriter(): callable
    {
        $enabled = (bool) ($this->config['log_enabled'] ?? true);

        if (!$enabled) {
            return function (string $message): void {};
        }

        $logDir = $this->config['log_path']
            ?? dirname(__DIR__, 2) . '/storage/logs';

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $runId = bin2hex(random_bytes(4));
        $logFile = $logDir . '/cli-' . $timestamp . '-' . $runId . '.log';

        return function (string $message) use ($logFile): void {
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
            @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        };
    }

    /**
     * Format a file size in bytes to a human-readable string.
     */
    private function formatFileSize(int|false $bytes): string
    {
        if ($bytes === false || $bytes < 0) {
            return 'unknown';
        }

        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
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
