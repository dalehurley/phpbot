<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Listener;

use Dalehurley\Phpbot\Listener\Watchers\WatcherInterface;

/**
 * Orchestrates event source watchers and feeds new events to the router.
 *
 * Iterates through all registered watchers on each poll cycle, collecting
 * new events and forwarding them to the EventRouter for classification
 * and action.
 */
class EventListener
{
    /** @var array<WatcherInterface> */
    private array $watchers = [];

    private StateStore $stateStore;
    private EventRouter $router;

    /** @var \Closure|null */
    private ?\Closure $logger;

    /** @var int Total events processed across all polls */
    private int $totalEventsProcessed = 0;

    /** @var int Total poll cycles completed */
    private int $pollCount = 0;

    public function __construct(StateStore $stateStore, EventRouter $router)
    {
        $this->stateStore = $stateStore;
        $this->router = $router;
        $this->logger = null;
    }

    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Register a watcher if it is available on this platform.
     */
    public function addWatcher(WatcherInterface $watcher): void
    {
        if (!$watcher->isAvailable()) {
            $this->log("Watcher '{$watcher->getName()}' not available, skipping");

            return;
        }

        $this->watchers[] = $watcher;
        $this->log("Watcher '{$watcher->getName()}' registered");
    }

    /**
     * Run a single poll cycle across all watchers.
     *
     * Called periodically by the daemon's event loop timer.
     */
    public function poll(): void
    {
        $this->pollCount++;
        $totalNewEvents = 0;

        foreach ($this->watchers as $watcher) {
            try {
                $events = $watcher->poll($this->stateStore);

                foreach ($events as $event) {
                    try {
                        $this->router->handle($event);
                        $totalNewEvents++;
                    } catch (\Throwable $e) {
                        $this->log("Error routing event from {$watcher->getName()}: {$e->getMessage()}");
                    }
                }
            } catch (\Throwable $e) {
                $this->log("Error polling {$watcher->getName()}: {$e->getMessage()}");
            }
        }

        $this->totalEventsProcessed += $totalNewEvents;

        if ($totalNewEvents > 0) {
            $this->log("Poll #{$this->pollCount}: {$totalNewEvents} new event(s) processed");
        }
    }

    /**
     * Get the list of registered watcher names.
     *
     * @return array<string>
     */
    public function getWatcherNames(): array
    {
        return array_map(fn(WatcherInterface $w) => $w->getName(), $this->watchers);
    }

    /**
     * Get listener statistics.
     *
     * @return array{watchers: int, poll_count: int, total_events: int}
     */
    public function getStats(): array
    {
        return [
            'watchers' => count($this->watchers),
            'watcher_names' => $this->getWatcherNames(),
            'poll_count' => $this->pollCount,
            'total_events' => $this->totalEventsProcessed,
        ];
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
