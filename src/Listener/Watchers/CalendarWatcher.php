<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Listener\Watchers;

use Dalehurley\Phpbot\Apple\AppleScriptRunner;
use Dalehurley\Phpbot\Listener\ListenerEvent;
use Dalehurley\Phpbot\Listener\StateStore;
use Dalehurley\Phpbot\Platform;

/**
 * Watches for new and upcoming calendar events.
 *
 * Detects:
 * - Newly created events (UIDs not previously seen)
 * - Events starting within the next 15 minutes (upcoming alerts)
 */
class CalendarWatcher implements WatcherInterface
{
    private AppleScriptRunner $runner;
    private int $upcomingMinutes;

    /** @var \Closure|null */
    private ?\Closure $logger;

    public function __construct(AppleScriptRunner $runner, int $upcomingMinutes = 15)
    {
        $this->runner = $runner;
        $this->upcomingMinutes = $upcomingMinutes;
        $this->logger = null;
    }

    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'calendar';
    }

    public function isAvailable(): bool
    {
        return Platform::isMacOS();
    }

    public function poll(StateStore $state): array
    {
        $seenUids = $state->get('calendar', 'seen_uids', []);
        $alertedUids = $state->get('calendar', 'alerted_uids', []);

        if (!is_array($seenUids)) {
            $seenUids = [];
        }
        if (!is_array($alertedUids)) {
            $alertedUids = [];
        }

        $now = new \DateTimeImmutable();
        $startDate = $now->format('F j, Y') . ' at 12:00:00 AM';
        $endDate = $now->modify('+24 hours')->format('F j, Y') . ' at 11:59:59 PM';

        $startSafe = $this->runner->escapeAppleScript($startDate);
        $endSafe = $this->runner->escapeAppleScript($endDate);

        $script = <<<APPLESCRIPT
tell application "Calendar"
    set startD to date "{$startSafe}"
    set endD to date "{$endSafe}"
    set output to ""
    repeat with c in calendars
        set evts to (every event of c whose start date >= startD and start date <= endD)
        repeat with e in evts
            set output to output & (summary of e) & "\t" & (start date of e) & "\t" & (end date of e) & "\t" & (uid of e) & "\t" & (name of c) & linefeed
        end repeat
    end repeat
    return output
end tell
APPLESCRIPT;

        $result = $this->runner->runOsascript($script, 30);

        if ($result === null || $result['exit_code'] !== 0) {
            $this->log('Calendar poll failed or permission denied');

            return [];
        }

        $events = $this->runner->parseTsvOutput($result['stdout'], ['summary', 'start_date', 'end_date', 'uid', 'calendar']);

        if (empty($events)) {
            return [];
        }

        $listenerEvents = [];
        $updatedSeenUids = $seenUids;
        $updatedAlertedUids = $alertedUids;

        foreach ($events as $event) {
            $uid = $event['uid'];

            try {
                $startTime = new \DateTimeImmutable($event['start_date']);
            } catch (\Throwable) {
                $startTime = $now;
            }

            try {
                $endTime = new \DateTimeImmutable($event['end_date']);
            } catch (\Throwable) {
                $endTime = $startTime->modify('+1 hour');
            }

            // Detect new events (UID not previously seen)
            if (!in_array($uid, $seenUids, true)) {
                $listenerEvents[] = new ListenerEvent(
                    source: 'calendar',
                    type: 'new_event',
                    subject: $event['summary'],
                    sender: $event['calendar'],
                    body: "Event: {$event['summary']}\nStart: {$event['start_date']}\nEnd: {$event['end_date']}\nCalendar: {$event['calendar']}",
                    timestamp: $now,
                    rawId: $uid,
                    metadata: [
                        'start_date' => $event['start_date'],
                        'end_date' => $event['end_date'],
                        'calendar' => $event['calendar'],
                    ],
                );
                $updatedSeenUids[] = $uid;
            }

            // Detect upcoming events (starting within N minutes, not yet alerted)
            $minutesUntilStart = ($startTime->getTimestamp() - $now->getTimestamp()) / 60;
            if ($minutesUntilStart > 0 && $minutesUntilStart <= $this->upcomingMinutes && !in_array($uid, $alertedUids, true)) {
                $listenerEvents[] = new ListenerEvent(
                    source: 'calendar',
                    type: 'upcoming_event',
                    subject: $event['summary'],
                    sender: $event['calendar'],
                    body: "Upcoming in " . round($minutesUntilStart) . " minutes: {$event['summary']}\nStart: {$event['start_date']}\nEnd: {$event['end_date']}",
                    timestamp: $startTime,
                    rawId: $uid . '_upcoming',
                    metadata: [
                        'start_date' => $event['start_date'],
                        'end_date' => $event['end_date'],
                        'calendar' => $event['calendar'],
                        'minutes_until' => round($minutesUntilStart),
                    ],
                );
                $updatedAlertedUids[] = $uid;
            }
        }

        // Prune seen UIDs older than 48 hours to prevent unbounded growth
        // (keep only UIDs from the current batch)
        $currentUids = array_column($events, 'uid');
        $updatedSeenUids = array_values(array_intersect($updatedSeenUids, $currentUids));
        $updatedAlertedUids = array_values(array_intersect($updatedAlertedUids, $currentUids));

        $state->set('calendar', 'seen_uids', $updatedSeenUids);
        $state->set('calendar', 'alerted_uids', $updatedAlertedUids);
        $state->set('calendar', 'last_check', $now->format('c'));
        $state->save();

        if (!empty($listenerEvents)) {
            $this->log('Found ' . count($listenerEvents) . ' calendar event(s)');
        }

        return $listenerEvents;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
