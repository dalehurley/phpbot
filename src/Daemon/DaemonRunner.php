<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Daemon;

use Dalehurley\Phpbot\Apple\AppleScriptRunner;
use Dalehurley\Phpbot\Bot;
use Dalehurley\Phpbot\Listener\EventListener;
use Dalehurley\Phpbot\Listener\EventRouter;
use Dalehurley\Phpbot\Listener\StateStore;
use Dalehurley\Phpbot\Listener\Watchers\CalendarWatcher;
use Dalehurley\Phpbot\Listener\Watchers\MailWatcher;
use Dalehurley\Phpbot\Listener\Watchers\MessageWatcher;
use Dalehurley\Phpbot\Listener\Watchers\GitHubPRWatcher;
use Dalehurley\Phpbot\Listener\Watchers\NotificationWatcher;
use Dalehurley\Phpbot\Scheduler\Scheduler;
use Dalehurley\Phpbot\Scheduler\TaskStore;
use Dalehurley\Phpbot\Tools\AppleServicesTool;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * Combined listener + scheduler daemon.
 *
 * Uses the React event loop (already installed via cboden/ratchet)
 * with periodic timers for:
 *  - Event listener polling (every N seconds)
 *  - Scheduler tick (every 60 seconds)
 *
 * Handles SIGTERM/SIGINT for graceful shutdown.
 */
class DaemonRunner
{
    private array $config;
    private bool $verbose;
    private ?Bot $bot = null;
    private ?EventListener $listener = null;
    private ?Scheduler $scheduler = null;
    private LoopInterface $loop;
    private bool $running = false;

    /** @var \Closure|null */
    private ?\Closure $logger;

    public function __construct(array $config, bool $verbose = false)
    {
        $this->config = $config;
        $this->verbose = $verbose;
        $this->logger = null;
        $this->loop = Loop::get();
    }

    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Start the daemon.
     */
    public function run(): void
    {
        $this->running = true;

        $this->log('Starting PhpBot daemon...');

        // Initialize components
        $this->initBot();
        $this->initListener();
        $this->initScheduler();

        // Register periodic timers
        $this->registerTimers();

        // Register signal handlers for graceful shutdown
        $this->registerSignalHandlers();

        $listenerWatchers = $this->listener !== null
            ? implode(', ', $this->listener->getWatcherNames())
            : 'none';
        $schedulerTasks = $this->scheduler !== null
            ? count($this->scheduler->getTaskStore()->all())
            : 0;

        $this->log("Daemon started. Watchers: [{$listenerWatchers}], Scheduled tasks: {$schedulerTasks}");
        $this->output("PhpBot daemon running. Press Ctrl+C to stop.\n");

        // Start the event loop (blocks until stopped)
        $this->loop->run();

        $this->log('Daemon stopped.');
        $this->output("PhpBot daemon stopped.\n");
    }

    /**
     * Stop the daemon gracefully.
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;
        $this->log('Shutting down...');
        $this->loop->stop();
    }

    /**
     * Get the event listener instance (for status queries).
     */
    public function getListener(): ?EventListener
    {
        return $this->listener;
    }

    /**
     * Get the scheduler instance (for task management).
     */
    public function getScheduler(): ?Scheduler
    {
        return $this->scheduler;
    }

    // -------------------------------------------------------------------------
    // Initialization
    // -------------------------------------------------------------------------

    private function initBot(): void
    {
        try {
            $this->bot = new Bot($this->config, $this->verbose);
            $this->log('Bot initialized');
        } catch (\Throwable $e) {
            $this->log('Bot initialization failed: ' . $e->getMessage());
            $this->log('Daemon will run in limited mode (no complex actions)');
        }
    }

    private function initListener(): void
    {
        $listenerConfig = $this->config['listener'] ?? [];
        $enabled = (bool) ($listenerConfig['enabled'] ?? true);

        if (!$enabled) {
            $this->log('Listener disabled via config');

            return;
        }

        $statePath = $listenerConfig['state_path']
            ?? dirname(__DIR__, 2) . '/storage/listener-state.json';

        $stateStore = new StateStore($statePath);
        $runner = new AppleScriptRunner();
        $appleTool = new AppleServicesTool();

        // Get the small model client from the bot for classification
        $classifier = null;
        // The Bot doesn't expose its SmallModelClient directly, so the
        // EventRouter will fall back to keyword classification when the
        // classifier is null. This is intentional — the daemon should
        // work without requiring Apple FM or cloud API calls for every event.

        $taskStore = $this->getOrCreateTaskStore();

        $router = new EventRouter(
            classifier: $classifier,
            appleTool: $appleTool,
            bot: $this->bot,
            taskStore: $taskStore,
        );
        $router->setLogger(fn(string $msg) => $this->log("[router] {$msg}"));

        $this->listener = new EventListener($stateStore, $router);
        $this->listener->setLogger(fn(string $msg) => $this->log("[listener] {$msg}"));

        // Register watchers based on config
        $enabledWatchers = $listenerConfig['watchers'] ?? ['mail', 'calendar', 'messages', 'notifications'];

        if (in_array('mail', $enabledWatchers, true)) {
            $watcher = new MailWatcher($runner);
            $watcher->setLogger(fn(string $msg) => $this->log("[mail] {$msg}"));
            $this->listener->addWatcher($watcher);
        }

        if (in_array('calendar', $enabledWatchers, true)) {
            $watcher = new CalendarWatcher($runner);
            $watcher->setLogger(fn(string $msg) => $this->log("[calendar] {$msg}"));
            $this->listener->addWatcher($watcher);
        }

        if (in_array('messages', $enabledWatchers, true)) {
            $watcher = new MessageWatcher();
            $watcher->setLogger(fn(string $msg) => $this->log("[messages] {$msg}"));
            $this->listener->addWatcher($watcher);
        }

        if (in_array('notifications', $enabledWatchers, true)) {
            $watcher = new NotificationWatcher($runner);
            $watcher->setLogger(fn(string $msg) => $this->log("[notifications] {$msg}"));
            $this->listener->addWatcher($watcher);
        }

        // Register GitHub PR watcher when self-improvement is enabled
        $si     = $this->config['self_improvement'] ?? [];
        $siRepo = (string) ($si['github_repo'] ?? '');
        if (!empty($si['enabled']) && $siRepo !== '') {
            $watcher = new GitHubPRWatcher($siRepo, fn(string $msg) => $this->log("[github_pr] {$msg}"));
            $this->listener->addWatcher($watcher);
        }

        $this->log('Listener initialized');
    }

    private function initScheduler(): void
    {
        $schedulerConfig = $this->config['scheduler'] ?? [];
        $enabled = (bool) ($schedulerConfig['enabled'] ?? true);

        if (!$enabled) {
            $this->log('Scheduler disabled via config');

            return;
        }

        $taskStore = $this->getOrCreateTaskStore();

        $this->scheduler = new Scheduler(
            taskStore: $taskStore,
            bot: $this->bot,
            appleTool: new AppleServicesTool(),
        );
        $this->scheduler->setLogger(fn(string $msg) => $this->log("[scheduler] {$msg}"));

        $this->log('Scheduler initialized with ' . count($taskStore->all()) . ' task(s)');
    }

    /**
     * Get or create a shared TaskStore instance.
     */
    private ?TaskStore $taskStore = null;

    private function getOrCreateTaskStore(): TaskStore
    {
        if ($this->taskStore !== null) {
            return $this->taskStore;
        }

        $schedulerConfig = $this->config['scheduler'] ?? [];
        $tasksPath = $schedulerConfig['tasks_path']
            ?? dirname(__DIR__, 2) . '/storage/scheduler/tasks.json';

        $this->taskStore = new TaskStore($tasksPath);

        return $this->taskStore;
    }

    // -------------------------------------------------------------------------
    // Timers
    // -------------------------------------------------------------------------

    private function registerTimers(): void
    {
        $listenerConfig = $this->config['listener'] ?? [];
        $schedulerConfig = $this->config['scheduler'] ?? [];

        // Listener poll timer
        if ($this->listener !== null) {
            $pollInterval = (int) ($listenerConfig['poll_interval'] ?? 30);
            $pollInterval = max(10, $pollInterval); // Minimum 10s

            $this->loop->addPeriodicTimer($pollInterval, function () {
                if (!$this->running) {
                    return;
                }

                try {
                    $this->listener->poll();
                } catch (\Throwable $e) {
                    $this->log("[listener] Poll error: {$e->getMessage()}");
                }
            });

            $this->log("Listener timer registered: every {$pollInterval}s");
        }

        // Scheduler tick timer
        if ($this->scheduler !== null) {
            $tickInterval = (int) ($schedulerConfig['tick_interval'] ?? 60);
            $tickInterval = max(30, $tickInterval); // Minimum 30s

            $this->loop->addPeriodicTimer($tickInterval, function () {
                if (!$this->running) {
                    return;
                }

                try {
                    $this->scheduler->tick();
                } catch (\Throwable $e) {
                    $this->log("[scheduler] Tick error: {$e->getMessage()}");
                }
            });

            $this->log("Scheduler timer registered: every {$tickInterval}s");
        }

        // Heartbeat timer (every 5 minutes, log stats)
        $this->loop->addPeriodicTimer(300, function () {
            if (!$this->running) {
                return;
            }

            $this->logHeartbeat();
        });
    }

    // -------------------------------------------------------------------------
    // Signal handling
    // -------------------------------------------------------------------------

    private function registerSignalHandlers(): void
    {
        // pcntl_signal requires the pcntl extension
        if (!function_exists('pcntl_signal')) {
            $this->log('pcntl extension not available, signal handling disabled');

            return;
        }

        $handler = function () {
            $this->output("\nReceived shutdown signal.\n");
            $this->stop();
        };

        // Register via the event loop for non-blocking signal handling
        if (method_exists($this->loop, 'addSignal')) {
            $this->loop->addSignal(SIGINT, $handler);
            $this->loop->addSignal(SIGTERM, $handler);
            $this->log('Signal handlers registered (SIGINT, SIGTERM)');
        }
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    private function logHeartbeat(): void
    {
        $parts = ['Heartbeat:'];

        if ($this->listener !== null) {
            $stats = $this->listener->getStats();
            $parts[] = "listener({$stats['poll_count']} polls, {$stats['total_events']} events)";
        }

        if ($this->scheduler !== null) {
            $stats = $this->scheduler->getStats();
            $parts[] = "scheduler({$stats['tick_count']} ticks, {$stats['total_executed']} executed, {$stats['pending']} pending)";
        }

        $this->log(implode(' ', $parts));
    }

    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] [daemon] {$message}";

        if ($this->verbose) {
            $this->output($line . "\n");
        }

        if ($this->logger !== null) {
            ($this->logger)($line);
        }
    }

    private function output(string $message): void
    {
        fwrite(STDOUT, $message);
        flush();
    }
}
