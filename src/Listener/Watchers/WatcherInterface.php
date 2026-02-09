<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Listener\Watchers;

use Dalehurley\Phpbot\Listener\ListenerEvent;
use Dalehurley\Phpbot\Listener\StateStore;

/**
 * Contract for event source watchers.
 *
 * Each watcher polls a single source (Mail, Calendar, Messages, etc.)
 * and returns any new events since the last poll.
 */
interface WatcherInterface
{
    /**
     * Unique name for this watcher (e.g. 'mail', 'calendar').
     */
    public function getName(): string;

    /**
     * Poll the source for new events.
     *
     * Compares current state against the stored watermark in the StateStore
     * and returns only events that are new since the last poll.
     *
     * @return array<ListenerEvent>
     */
    public function poll(StateStore $state): array;

    /**
     * Whether this watcher is available on the current platform.
     */
    public function isAvailable(): bool;
}
