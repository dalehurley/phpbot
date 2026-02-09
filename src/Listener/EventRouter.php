<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Listener;

use Dalehurley\Phpbot\Apple\SmallModelClient;
use Dalehurley\Phpbot\Bot;
use Dalehurley\Phpbot\Scheduler\TaskStore;
use Dalehurley\Phpbot\Scheduler\Task;
use Dalehurley\Phpbot\Stats\TokenLedger;
use Dalehurley\Phpbot\Tools\AppleServicesTool;

/**
 * Classifies incoming events and routes them to the appropriate action.
 *
 * Uses the SmallModelClient (Apple FM or Haiku) for cheap on-device
 * classification of whether an event is actionable, then dispatches
 * to one of:
 *  - Direct AppleServicesTool call (create reminder, etc.)
 *  - Full Bot::run() for complex multi-step actions
 *  - TaskStore for scheduling future-dated actions
 *  - Ignore (non-actionable)
 */
class EventRouter
{
    private ?SmallModelClient $classifier;
    private AppleServicesTool $appleTool;
    private ?Bot $bot;
    private ?TaskStore $taskStore;
    private TokenLedger $tokenLedger;

    /** @var \Closure|null */
    private ?\Closure $logger;

    /** @var array<array{event: ListenerEvent, action: string, result: string}> */
    private array $actionLog = [];

    public function __construct(
        ?SmallModelClient $classifier,
        AppleServicesTool $appleTool,
        ?Bot $bot = null,
        ?TaskStore $taskStore = null,
        ?TokenLedger $tokenLedger = null,
    ) {
        $this->classifier = $classifier;
        $this->appleTool = $appleTool;
        $this->bot = $bot;
        $this->taskStore = $taskStore;
        $this->tokenLedger = $tokenLedger ?? new TokenLedger('classifier');
        $this->logger = null;
    }

    public function setLogger(?\Closure $logger): void
    {
        $this->logger = $logger;
    }

    public function setBot(Bot $bot): void
    {
        $this->bot = $bot;
    }

    public function setTaskStore(TaskStore $taskStore): void
    {
        $this->taskStore = $taskStore;
    }

    /**
     * Handle a single event: classify it and execute the appropriate action.
     */
    public function handle(ListenerEvent $event): void
    {
        $this->log("Routing event: [{$event->source}] {$event->type} — {$event->subject}");

        // Skip upcoming event alerts — just log them, don't classify
        if ($event->type === 'upcoming_event') {
            $this->log("Upcoming event alert: {$event->subject} in {$event->metadata['minutes_until']} min");
            $this->recordAction($event, 'alert', 'Logged upcoming event');

            return;
        }

        // Classify the event
        $classification = $this->classify($event);

        if ($classification === null || $classification['action'] === 'ignore') {
            $this->log("Ignored: {$event->subject}");
            $this->recordAction($event, 'ignore', 'Not actionable');

            return;
        }

        $action = $classification['action'];
        $reason = $classification['reason'] ?? '';

        $this->log("Classified as '{$action}' (reason: {$reason})");

        match ($action) {
            'create_reminder' => $this->createReminder($event, $classification),
            'schedule_task' => $this->scheduleTask($event, $classification),
            'complex_action' => $this->runBot($event, $classification),
            default => $this->log("Unknown action '{$action}', ignoring"),
        };
    }

    /**
     * Classify an event using the SmallModelClient.
     *
     * @return array{action: string, priority: string, reason: string, title?: string, due_date?: string}|null
     */
    private function classify(ListenerEvent $event): ?array
    {
        if ($this->classifier === null) {
            // No classifier available — use simple keyword heuristics
            return $this->classifyByKeywords($event);
        }

        $prompt = <<<PROMPT
Analyze this incoming event and decide if it requires action.

{$event->toSummary()}

Respond with ONLY a JSON object (no markdown, no explanation):
{
  "action": "create_reminder" | "schedule_task" | "complex_action" | "ignore",
  "priority": "high" | "medium" | "low",
  "reason": "brief explanation",
  "title": "suggested reminder/task title if applicable",
  "due_date": "suggested due date if applicable, format: YYYY-MM-DD"
}

Rules:
- "create_reminder": For clear action items (pay bill, review document, reply to email, etc.)
- "schedule_task": For items that need action at a specific future date
- "complex_action": For items requiring multi-step processing (research, compose reply, etc.)
- "ignore": For newsletters, marketing, notifications, FYI-only items
PROMPT;

        try {
            $response = $this->classifier->call($prompt, 256, 'event_classification');

            // Extract JSON from response
            $json = $response;
            if (preg_match('/\{[^}]+\}/', $response, $matches)) {
                $json = $matches[0];
            }

            $parsed = json_decode($json, true);

            if (is_array($parsed) && isset($parsed['action'])) {
                return $parsed;
            }
        } catch (\Throwable $e) {
            $this->log('Classification failed: ' . $e->getMessage());
        }

        // Fallback to keywords
        return $this->classifyByKeywords($event);
    }

    /**
     * Simple keyword-based classification fallback.
     */
    private function classifyByKeywords(ListenerEvent $event): array
    {
        $text = strtolower($event->subject . ' ' . $event->body);

        // High-priority action keywords
        $urgentPatterns = ['urgent', 'asap', 'action required', 'due today', 'deadline', 'overdue'];
        foreach ($urgentPatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return [
                    'action' => 'create_reminder',
                    'priority' => 'high',
                    'reason' => "Contains urgent keyword: {$pattern}",
                    'title' => $event->subject,
                ];
            }
        }

        // Action keywords
        $actionPatterns = ['please review', 'please send', 'please pay', 'invoice', 'approval needed', 'sign', 'submit', 'complete', 'respond', 'reply needed'];
        foreach ($actionPatterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return [
                    'action' => 'create_reminder',
                    'priority' => 'medium',
                    'reason' => "Contains action keyword: {$pattern}",
                    'title' => $event->subject,
                ];
            }
        }

        // Default: ignore
        return [
            'action' => 'ignore',
            'priority' => 'low',
            'reason' => 'No actionable keywords detected',
        ];
    }

    /**
     * Create a reminder in Apple Reminders via AppleServicesTool.
     */
    private function createReminder(ListenerEvent $event, array $classification): void
    {
        $title = $classification['title'] ?? $event->subject;
        $dueDate = $classification['due_date'] ?? '';
        $priority = $classification['priority'] ?? 'medium';

        $input = [
            'action' => 'create_reminder',
            'title' => "[{$priority}] {$title}",
            'list_name' => 'PhpBot Actions',
        ];

        if (!empty($dueDate)) {
            $input['due_date'] = $dueDate;
        }

        $result = $this->appleTool->execute($input);
        $resultStr = $result->getContent();

        $this->log("Created reminder: {$title}");
        $this->recordAction($event, 'create_reminder', $resultStr);
    }

    /**
     * Schedule a task for future execution via TaskStore.
     */
    private function scheduleTask(ListenerEvent $event, array $classification): void
    {
        if ($this->taskStore === null) {
            $this->log('TaskStore not available, falling back to reminder');
            $this->createReminder($event, $classification);

            return;
        }

        $title = $classification['title'] ?? $event->subject;
        $dueDate = $classification['due_date'] ?? '';

        try {
            $nextRunAt = !empty($dueDate)
                ? new \DateTimeImmutable($dueDate . ' 09:00:00')
                : (new \DateTimeImmutable())->modify('+1 day');
        } catch (\Throwable) {
            $nextRunAt = (new \DateTimeImmutable())->modify('+1 day');
        }

        $task = new Task(
            id: bin2hex(random_bytes(8)),
            name: $title,
            command: "Follow up on: {$event->subject} from {$event->sender}. Original content: {$event->body}",
            type: 'once',
            nextRunAt: $nextRunAt,
            status: 'pending',
            metadata: [
                'created_by' => 'listener',
                'source_event' => $event->toArray(),
                'priority' => $classification['priority'] ?? 'medium',
            ],
        );

        $this->taskStore->save($task);

        $this->log("Scheduled task '{$title}' for {$nextRunAt->format('Y-m-d H:i')}");
        $this->recordAction($event, 'schedule_task', "Scheduled for {$nextRunAt->format('Y-m-d H:i')}");
    }

    /**
     * Run a complex action via Bot::run().
     */
    private function runBot(ListenerEvent $event, array $classification): void
    {
        if ($this->bot === null) {
            $this->log('Bot not available, falling back to reminder');
            $this->createReminder($event, $classification);

            return;
        }

        $prompt = "Handle this incoming event:\n\n{$event->toSummary()}\n\n"
            . "Reason for action: {$classification['reason']}\n"
            . 'Take the appropriate action (create reminders, draft replies, etc.)';

        try {
            $result = $this->bot->run($prompt);
            $this->log('Bot action completed: ' . ($result->isSuccess() ? 'success' : 'failed'));
            $this->recordAction($event, 'complex_action', $result->getAnswer() ?? $result->getError() ?? 'unknown');
        } catch (\Throwable $e) {
            $this->log('Bot action failed: ' . $e->getMessage());
            $this->recordAction($event, 'complex_action_failed', $e->getMessage());
        }
    }

    /**
     * Get the action log for the current session.
     *
     * @return array<array{event: ListenerEvent, action: string, result: string}>
     */
    public function getActionLog(): array
    {
        return $this->actionLog;
    }

    /**
     * Clear the action log.
     */
    public function clearActionLog(): void
    {
        $this->actionLog = [];
    }

    private function recordAction(ListenerEvent $event, string $action, string $result): void
    {
        $this->actionLog[] = [
            'event' => $event,
            'action' => $action,
            'result' => $result,
        ];
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
