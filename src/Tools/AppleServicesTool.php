<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Apple\AppleScriptRunner;
use Dalehurley\Phpbot\Platform;

/**
 * Apple on-device services tool for macOS.
 *
 * Provides structured access to Mail, Calendar, Reminders, Notes,
 * Contacts, Messages, Music, and Shortcuts via AppleScript (osascript)
 * and the shortcuts CLI.
 *
 * Only available on macOS — bails early on other platforms.
 */
class AppleServicesTool implements ToolInterface
{
    use ToolDefinitionTrait;

    private AppleScriptRunner $runner;

    public function __construct(array $config = [])
    {
        $maxOutput = (int) ($config['apple_services_max_output_chars'] ?? 15000);
        $this->runner = new AppleScriptRunner($maxOutput);
    }

    public function getName(): string
    {
        return 'apple_services';
    }

    public function getDescription(): string
    {
        return 'Interact with Apple on-device services on macOS via AppleScript. '
            . 'Access Mail (list/read/send/search emails), Calendar (list calendars/events, create events), '
            . 'Reminders (list/create/complete), Notes (list/create/search), '
            . 'Contacts (search/get), Messages (send iMessage), Music (now playing/play/pause), '
            . 'and Shortcuts (list/run). Requires macOS with Automation permissions granted in '
            . 'System Settings > Privacy & Security > Automation.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'The Apple service action to perform.',
                    'enum' => [
                        // Mail
                        'list_emails',
                        'read_email',
                        'send_email',
                        'search_emails',
                        // Calendar
                        'list_calendars',
                        'list_events',
                        'create_event',
                        // Reminders
                        'list_reminders',
                        'create_reminder',
                        'complete_reminder',
                        // Notes
                        'list_notes',
                        'create_note',
                        'search_notes',
                        // Contacts
                        'search_contacts',
                        'get_contact',
                        // Messages
                        'send_message',
                        // Music
                        'now_playing',
                        'play_pause',
                        // Shortcuts
                        'list_shortcuts',
                        'run_shortcut',
                    ],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max number of items to return for list actions. Default: 20.',
                ],
                'message_id' => [
                    'type' => 'integer',
                    'description' => 'Message ID for read_email action.',
                ],
                'to' => [
                    'type' => 'string',
                    'description' => 'Recipient email or phone/iMessage address.',
                ],
                'subject' => [
                    'type' => 'string',
                    'description' => 'Email subject line.',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Email body, note content, or message text.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query for search actions.',
                ],
                'calendar_name' => [
                    'type' => 'string',
                    'description' => 'Calendar name for calendar actions. Default: first calendar.',
                ],
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Start date/time for events (e.g. "February 10, 2026 at 2:00:00 PM").',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'End date/time for events (e.g. "February 10, 2026 at 3:00:00 PM").',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Title/name for events, reminders, or notes.',
                ],
                'list_name' => [
                    'type' => 'string',
                    'description' => 'Reminders list name. Default: "Reminders".',
                ],
                'due_date' => [
                    'type' => 'string',
                    'description' => 'Due date for reminders (e.g. "February 10, 2026").',
                ],
                'reminder_name' => [
                    'type' => 'string',
                    'description' => 'Name of the reminder to complete.',
                ],
                'folder_name' => [
                    'type' => 'string',
                    'description' => 'Notes folder name. Default: "Notes".',
                ],
                'contact_name' => [
                    'type' => 'string',
                    'description' => 'Name to search for in Contacts.',
                ],
                'shortcut_name' => [
                    'type' => 'string',
                    'description' => 'Name of the Shortcut to run.',
                ],
                'shortcut_input' => [
                    'type' => 'string',
                    'description' => 'Input to pass to the Shortcut.',
                ],
                'mailbox' => [
                    'type' => 'string',
                    'description' => 'Mailbox name for email actions. Default: "INBOX".',
                ],
                'account' => [
                    'type' => 'string',
                    'description' => 'Mail account name for email actions. Default: first account.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        if (!Platform::isMacOS()) {
            return ToolResult::error(
                'Apple services are only available on macOS. Current platform: ' . Platform::osName()
            );
        }

        $action = $input['action'] ?? '';

        return match ($action) {
            // Mail
            'list_emails' => $this->listEmails($input),
            'read_email' => $this->readEmail($input),
            'send_email' => $this->sendEmail($input),
            'search_emails' => $this->searchEmails($input),
            // Calendar
            'list_calendars' => $this->listCalendars(),
            'list_events' => $this->listEvents($input),
            'create_event' => $this->createEvent($input),
            // Reminders
            'list_reminders' => $this->listReminders($input),
            'create_reminder' => $this->createReminder($input),
            'complete_reminder' => $this->completeReminder($input),
            // Notes
            'list_notes' => $this->listNotes($input),
            'create_note' => $this->createNote($input),
            'search_notes' => $this->searchNotes($input),
            // Contacts
            'search_contacts' => $this->searchContacts($input),
            'get_contact' => $this->getContact($input),
            // Messages
            'send_message' => $this->sendMessage($input),
            // Music
            'now_playing' => $this->nowPlaying(),
            'play_pause' => $this->playPause(),
            // Shortcuts
            'list_shortcuts' => $this->listShortcuts(),
            'run_shortcut' => $this->runShortcut($input),
            default => ToolResult::error(
                "Unknown action: {$action}. Use one of: list_emails, read_email, send_email, search_emails, "
                . 'list_calendars, list_events, create_event, list_reminders, create_reminder, complete_reminder, '
                . 'list_notes, create_note, search_notes, search_contacts, get_contact, send_message, '
                . 'now_playing, play_pause, list_shortcuts, run_shortcut.'
            ),
        };
    }

    // -------------------------------------------------------------------------
    // Mail Actions
    // -------------------------------------------------------------------------

    private function listEmails(array $input): ToolResultInterface
    {
        $limit = (int) ($input['limit'] ?? 20);
        $limit = max(1, min($limit, 100));
        $mailbox = $this->escapeAppleScript($input['mailbox'] ?? 'INBOX');

        $script = <<<APPLESCRIPT
tell application "Mail"
    set output to ""
    set msgList to messages of mailbox "{$mailbox}" of (first account)
    if (count of msgList) < {$limit} then
        set maxCount to (count of msgList)
    else
        set maxCount to {$limit}
    end if
    repeat with i from 1 to maxCount
        set m to item i of msgList
        set output to output & (id of m) & "\t" & (subject of m) & "\t" & (sender of m) & "\t" & (date received of m) & linefeed
    end repeat
    return output
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Mail');
        }

        $emails = $this->parseTsvOutput($result['stdout'], ['id', 'subject', 'sender', 'date']);

        return ToolResult::success(json_encode([
            'action' => 'list_emails',
            'mailbox' => $input['mailbox'] ?? 'INBOX',
            'emails' => $emails,
            'count' => count($emails),
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function readEmail(array $input): ToolResultInterface
    {
        $messageId = (int) ($input['message_id'] ?? 0);
        if ($messageId <= 0) {
            return ToolResult::error('message_id is required and must be a positive integer.');
        }

        $script = <<<APPLESCRIPT
tell application "Mail"
    set m to first message of mailbox "INBOX" of (first account) whose id is {$messageId}
    set output to (subject of m) & linefeed & "From: " & (sender of m) & linefeed & "Date: " & (date received of m) & linefeed & linefeed & (content of m)
    return output
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Mail');
        }

        return ToolResult::success(json_encode([
            'action' => 'read_email',
            'message_id' => $messageId,
            'content' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function sendEmail(array $input): ToolResultInterface
    {
        $to = $input['to'] ?? '';
        $subject = $input['subject'] ?? '';
        $body = $input['body'] ?? '';

        if (empty(trim($to))) {
            return ToolResult::error('Recipient "to" is required for send_email.');
        }
        if (empty(trim($subject))) {
            return ToolResult::error('Subject is required for send_email.');
        }

        $toSafe = $this->escapeAppleScript($to);
        $subjectSafe = $this->escapeAppleScript($subject);
        $bodySafe = $this->escapeAppleScript($body);

        $script = <<<APPLESCRIPT
tell application "Mail"
    set newMsg to make new outgoing message with properties {subject:"{$subjectSafe}", content:"{$bodySafe}", visible:true}
    tell newMsg
        make new to recipient at end of to recipients with properties {address:"{$toSafe}"}
    end tell
    send newMsg
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 60);
        if ($result === null) {
            return $this->permissionError('Mail');
        }

        return ToolResult::success(json_encode([
            'action' => 'send_email',
            'to' => $to,
            'subject' => $subject,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
            'error' => $result['exit_code'] !== 0 ? $result['stderr'] : null,
        ]));
    }

    private function searchEmails(array $input): ToolResultInterface
    {
        $query = $input['query'] ?? '';
        $limit = (int) ($input['limit'] ?? 20);
        $limit = max(1, min($limit, 50));

        if (empty(trim($query))) {
            return ToolResult::error('Search query is required for search_emails.');
        }

        $querySafe = $this->escapeAppleScript($query);

        $script = <<<APPLESCRIPT
tell application "Mail"
    set output to ""
    set found to (messages of mailbox "INBOX" of (first account) whose subject contains "{$querySafe}" or sender contains "{$querySafe}")
    if (count of found) < {$limit} then
        set maxCount to (count of found)
    else
        set maxCount to {$limit}
    end if
    repeat with i from 1 to maxCount
        set m to item i of found
        set output to output & (id of m) & "\t" & (subject of m) & "\t" & (sender of m) & "\t" & (date received of m) & linefeed
    end repeat
    return output
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Mail');
        }

        $emails = $this->parseTsvOutput($result['stdout'], ['id', 'subject', 'sender', 'date']);

        return ToolResult::success(json_encode([
            'action' => 'search_emails',
            'query' => $query,
            'emails' => $emails,
            'count' => count($emails),
            'success' => $result['exit_code'] === 0,
        ]));
    }

    // -------------------------------------------------------------------------
    // Calendar Actions
    // -------------------------------------------------------------------------

    private function listCalendars(): ToolResultInterface
    {
        $script = <<<'APPLESCRIPT'
tell application "Calendar"
    set output to ""
    repeat with c in calendars
        set output to output & (name of c) & "\t" & (uid of c) & linefeed
    end repeat
    return output
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Calendar');
        }

        $calendars = $this->parseTsvOutput($result['stdout'], ['name', 'uid']);

        return ToolResult::success(json_encode([
            'action' => 'list_calendars',
            'calendars' => $calendars,
            'count' => count($calendars),
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function listEvents(array $input): ToolResultInterface
    {
        $limit = (int) ($input['limit'] ?? 20);
        $limit = max(1, min($limit, 100));
        $calendarName = $input['calendar_name'] ?? '';

        // Default: get events from today onward for 7 days
        $startDate = $input['start_date'] ?? date('F j, Y') . ' at 12:00:00 AM';
        $endDate = $input['end_date'] ?? date('F j, Y', strtotime('+7 days')) . ' at 11:59:59 PM';

        $startSafe = $this->escapeAppleScript($startDate);
        $endSafe = $this->escapeAppleScript($endDate);

        if (!empty($calendarName)) {
            $calSafe = $this->escapeAppleScript($calendarName);
            $calTarget = "calendar \"{$calSafe}\"";
        } else {
            $calTarget = 'first calendar';
        }

        $script = <<<APPLESCRIPT
tell application "Calendar"
    set startD to date "{$startSafe}"
    set endD to date "{$endSafe}"
    set output to ""
    set evts to (every event of {$calTarget} whose start date >= startD and start date <= endD)
    if (count of evts) < {$limit} then
        set maxCount to (count of evts)
    else
        set maxCount to {$limit}
    end if
    repeat with i from 1 to maxCount
        set e to item i of evts
        set output to output & (summary of e) & "\t" & (start date of e) & "\t" & (end date of e) & "\t" & (uid of e) & linefeed
    end repeat
    return output
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Calendar');
        }

        $events = $this->parseTsvOutput($result['stdout'], ['summary', 'start_date', 'end_date', 'uid']);

        return ToolResult::success(json_encode([
            'action' => 'list_events',
            'events' => $events,
            'count' => count($events),
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function createEvent(array $input): ToolResultInterface
    {
        $title = $input['title'] ?? '';
        $startDate = $input['start_date'] ?? '';
        $endDate = $input['end_date'] ?? '';
        $calendarName = $input['calendar_name'] ?? '';

        if (empty(trim($title))) {
            return ToolResult::error('Title is required for create_event.');
        }
        if (empty(trim($startDate)) || empty(trim($endDate))) {
            return ToolResult::error('Both start_date and end_date are required for create_event (e.g. "February 10, 2026 at 2:00:00 PM").');
        }

        $titleSafe = $this->escapeAppleScript($title);
        $startSafe = $this->escapeAppleScript($startDate);
        $endSafe = $this->escapeAppleScript($endDate);

        if (!empty($calendarName)) {
            $calSafe = $this->escapeAppleScript($calendarName);
            $calTarget = "calendar \"{$calSafe}\"";
        } else {
            $calTarget = 'first calendar';
        }

        $script = <<<APPLESCRIPT
tell application "Calendar"
    tell {$calTarget}
        make new event with properties {summary:"{$titleSafe}", start date:date "{$startSafe}", end date:date "{$endSafe}"}
    end tell
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 60);
        if ($result === null) {
            return $this->permissionError('Calendar');
        }

        return ToolResult::success(json_encode([
            'action' => 'create_event',
            'title' => $title,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
            'error' => $result['exit_code'] !== 0 ? $result['stderr'] : null,
        ]));
    }

    // -------------------------------------------------------------------------
    // Reminders Actions
    // -------------------------------------------------------------------------

    private function listReminders(array $input): ToolResultInterface
    {
        $limit = (int) ($input['limit'] ?? 20);
        $limit = max(1, min($limit, 100));
        $listName = $input['list_name'] ?? 'Reminders';
        $listSafe = $this->escapeAppleScript($listName);

        $script = <<<APPLESCRIPT
tell application "Reminders"
    set output to ""
    set rems to reminders of list "{$listSafe}" whose completed is false
    if (count of rems) < {$limit} then
        set maxCount to (count of rems)
    else
        set maxCount to {$limit}
    end if
    repeat with i from 1 to maxCount
        set r to item i of rems
        try
            set dueStr to (due date of r) as string
        on error
            set dueStr to "no due date"
        end try
        set output to output & (name of r) & "\t" & dueStr & "\t" & (id of r) & linefeed
    end repeat
    return output
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Reminders');
        }

        $reminders = $this->parseTsvOutput($result['stdout'], ['name', 'due_date', 'id']);

        return ToolResult::success(json_encode([
            'action' => 'list_reminders',
            'list_name' => $listName,
            'reminders' => $reminders,
            'count' => count($reminders),
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function createReminder(array $input): ToolResultInterface
    {
        $title = $input['title'] ?? '';
        $listName = $input['list_name'] ?? 'Reminders';
        $dueDate = $input['due_date'] ?? '';

        if (empty(trim($title))) {
            return ToolResult::error('Title is required for create_reminder.');
        }

        $titleSafe = $this->escapeAppleScript($title);
        $listSafe = $this->escapeAppleScript($listName);

        if (!empty($dueDate)) {
            $dueSafe = $this->escapeAppleScript($dueDate);
            $props = "{name:\"{$titleSafe}\", due date:date \"{$dueSafe}\"}";
        } else {
            $props = "{name:\"{$titleSafe}\"}";
        }

        $script = <<<APPLESCRIPT
tell application "Reminders"
    make new reminder in list "{$listSafe}" with properties {$props}
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 60);
        if ($result === null) {
            return $this->permissionError('Reminders');
        }

        return ToolResult::success(json_encode([
            'action' => 'create_reminder',
            'title' => $title,
            'list_name' => $listName,
            'due_date' => $dueDate ?: null,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
            'error' => $result['exit_code'] !== 0 ? $result['stderr'] : null,
        ]));
    }

    private function completeReminder(array $input): ToolResultInterface
    {
        $reminderName = $input['reminder_name'] ?? '';
        $listName = $input['list_name'] ?? 'Reminders';

        if (empty(trim($reminderName))) {
            return ToolResult::error('reminder_name is required for complete_reminder.');
        }

        $nameSafe = $this->escapeAppleScript($reminderName);
        $listSafe = $this->escapeAppleScript($listName);

        $script = <<<APPLESCRIPT
tell application "Reminders"
    set targetReminder to first reminder of list "{$listSafe}" whose name is "{$nameSafe}"
    set completed of targetReminder to true
    return "Completed: " & name of targetReminder
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Reminders');
        }

        return ToolResult::success(json_encode([
            'action' => 'complete_reminder',
            'reminder_name' => $reminderName,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
            'error' => $result['exit_code'] !== 0 ? $result['stderr'] : null,
        ]));
    }

    // -------------------------------------------------------------------------
    // Notes Actions
    // -------------------------------------------------------------------------

    private function listNotes(array $input): ToolResultInterface
    {
        $limit = (int) ($input['limit'] ?? 20);
        $limit = max(1, min($limit, 100));
        $folderName = $input['folder_name'] ?? '';

        if (!empty($folderName)) {
            $folderSafe = $this->escapeAppleScript($folderName);
            $target = "notes of folder \"{$folderSafe}\"";
        } else {
            $target = 'every note';
        }

        $script = <<<APPLESCRIPT
tell application "Notes"
    set output to ""
    set noteList to {$target}
    if (count of noteList) < {$limit} then
        set maxCount to (count of noteList)
    else
        set maxCount to {$limit}
    end if
    repeat with i from 1 to maxCount
        set n to item i of noteList
        set output to output & (name of n) & "\t" & (id of n) & "\t" & (modification date of n) & linefeed
    end repeat
    return output
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Notes');
        }

        $notes = $this->parseTsvOutput($result['stdout'], ['name', 'id', 'modified']);

        return ToolResult::success(json_encode([
            'action' => 'list_notes',
            'notes' => $notes,
            'count' => count($notes),
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function createNote(array $input): ToolResultInterface
    {
        $title = $input['title'] ?? '';
        $body = $input['body'] ?? '';
        $folderName = $input['folder_name'] ?? 'Notes';

        if (empty(trim($title)) && empty(trim($body))) {
            return ToolResult::error('At least title or body is required for create_note.');
        }

        $folderSafe = $this->escapeAppleScript($folderName);
        $titleSafe = $this->escapeAppleScript($title);
        $bodySafe = $this->escapeAppleScript($body);

        // Notes uses HTML for body content
        $htmlBody = "<h1>{$titleSafe}</h1>";
        if (!empty($bodySafe)) {
            $htmlBody .= "<br>{$bodySafe}";
        }

        $script = <<<APPLESCRIPT
tell application "Notes"
    make new note in folder "{$folderSafe}" with properties {body:"{$htmlBody}"}
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 60);
        if ($result === null) {
            return $this->permissionError('Notes');
        }

        return ToolResult::success(json_encode([
            'action' => 'create_note',
            'title' => $title,
            'folder_name' => $folderName,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
            'error' => $result['exit_code'] !== 0 ? $result['stderr'] : null,
        ]));
    }

    private function searchNotes(array $input): ToolResultInterface
    {
        $query = $input['query'] ?? '';
        $limit = (int) ($input['limit'] ?? 20);
        $limit = max(1, min($limit, 50));

        if (empty(trim($query))) {
            return ToolResult::error('Search query is required for search_notes.');
        }

        $querySafe = $this->escapeAppleScript($query);

        $script = <<<APPLESCRIPT
tell application "Notes"
    set output to ""
    set found to (every note whose name contains "{$querySafe}")
    if (count of found) < {$limit} then
        set maxCount to (count of found)
    else
        set maxCount to {$limit}
    end if
    repeat with i from 1 to maxCount
        set n to item i of found
        set output to output & (name of n) & "\t" & (id of n) & "\t" & (modification date of n) & linefeed
    end repeat
    return output
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Notes');
        }

        $notes = $this->parseTsvOutput($result['stdout'], ['name', 'id', 'modified']);

        return ToolResult::success(json_encode([
            'action' => 'search_notes',
            'query' => $query,
            'notes' => $notes,
            'count' => count($notes),
            'success' => $result['exit_code'] === 0,
        ]));
    }

    // -------------------------------------------------------------------------
    // Contacts Actions
    // -------------------------------------------------------------------------

    private function searchContacts(array $input): ToolResultInterface
    {
        $query = $input['query'] ?? ($input['contact_name'] ?? '');
        $limit = (int) ($input['limit'] ?? 20);
        $limit = max(1, min($limit, 50));

        if (empty(trim($query))) {
            return ToolResult::error('Search query or contact_name is required for search_contacts.');
        }

        $querySafe = $this->escapeAppleScript($query);

        $script = <<<APPLESCRIPT
tell application "Contacts"
    set output to ""
    set found to (every person whose name contains "{$querySafe}")
    if (count of found) < {$limit} then
        set maxCount to (count of found)
    else
        set maxCount to {$limit}
    end if
    repeat with i from 1 to maxCount
        set p to item i of found
        try
            set emailStr to (value of first email of p) as string
        on error
            set emailStr to "no email"
        end try
        try
            set phoneStr to (value of first phone of p) as string
        on error
            set phoneStr to "no phone"
        end try
        set output to output & (name of p) & "\t" & emailStr & "\t" & phoneStr & "\t" & (id of p) & linefeed
    end repeat
    return output
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Contacts');
        }

        $contacts = $this->parseTsvOutput($result['stdout'], ['name', 'email', 'phone', 'id']);

        return ToolResult::success(json_encode([
            'action' => 'search_contacts',
            'query' => $query,
            'contacts' => $contacts,
            'count' => count($contacts),
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function getContact(array $input): ToolResultInterface
    {
        $contactName = $input['contact_name'] ?? '';

        if (empty(trim($contactName))) {
            return ToolResult::error('contact_name is required for get_contact.');
        }

        $nameSafe = $this->escapeAppleScript($contactName);

        $script = <<<APPLESCRIPT
tell application "Contacts"
    set p to first person whose name is "{$nameSafe}"
    set output to "Name: " & (name of p) & linefeed
    try
        set output to output & "Organization: " & (organization of p) & linefeed
    end try
    try
        set output to output & "Job Title: " & (job title of p) & linefeed
    end try
    repeat with e in emails of p
        set output to output & "Email: " & (value of e) & " (" & (label of e) & ")" & linefeed
    end repeat
    repeat with ph in phones of p
        set output to output & "Phone: " & (value of ph) & " (" & (label of ph) & ")" & linefeed
    end repeat
    repeat with a in addresses of p
        set output to output & "Address: " & (formatted address of a) & linefeed
    end repeat
    try
        set output to output & "Birthday: " & (birth date of p) & linefeed
    end try
    try
        set output to output & "Note: " & (note of p) & linefeed
    end try
    return output
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Contacts');
        }

        return ToolResult::success(json_encode([
            'action' => 'get_contact',
            'contact_name' => $contactName,
            'details' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
            'error' => $result['exit_code'] !== 0 ? $result['stderr'] : null,
        ]));
    }

    // -------------------------------------------------------------------------
    // Messages Actions
    // -------------------------------------------------------------------------

    private function sendMessage(array $input): ToolResultInterface
    {
        $to = $input['to'] ?? '';
        $body = $input['body'] ?? '';

        if (empty(trim($to))) {
            return ToolResult::error('Recipient "to" (phone number or iMessage address) is required for send_message.');
        }
        if (empty(trim($body))) {
            return ToolResult::error('Message body is required for send_message.');
        }

        $toSafe = $this->escapeAppleScript($to);
        $bodySafe = $this->escapeAppleScript($body);

        $script = <<<APPLESCRIPT
tell application "Messages"
    set targetService to 1st account whose service type = iMessage
    set targetBuddy to participant "{$toSafe}" of targetService
    send "{$bodySafe}" to targetBuddy
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 60);
        if ($result === null) {
            return $this->permissionError('Messages');
        }

        return ToolResult::success(json_encode([
            'action' => 'send_message',
            'to' => $to,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
            'error' => $result['exit_code'] !== 0 ? $result['stderr'] : null,
        ]));
    }

    // -------------------------------------------------------------------------
    // Music Actions
    // -------------------------------------------------------------------------

    private function nowPlaying(): ToolResultInterface
    {
        $script = <<<'APPLESCRIPT'
tell application "Music"
    if player state is playing then
        set trackName to name of current track
        set trackArtist to artist of current track
        set trackAlbum to album of current track
        set trackDuration to duration of current track
        set trackPosition to player position
        return "Playing: " & trackName & " by " & trackArtist & " from " & trackAlbum & " (" & (round (trackPosition / 60)) & "m/" & (round (trackDuration / 60)) & "m)"
    else if player state is paused then
        set trackName to name of current track
        set trackArtist to artist of current track
        return "Paused: " & trackName & " by " & trackArtist
    else
        return "Not playing"
    end if
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Music');
        }

        return ToolResult::success(json_encode([
            'action' => 'now_playing',
            'status' => trim($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function playPause(): ToolResultInterface
    {
        $script = <<<'APPLESCRIPT'
tell application "Music"
    playpause
    if player state is playing then
        return "Playing"
    else
        return "Paused"
    end if
end tell
APPLESCRIPT;

        $result = $this->runOsascript($script, 30);
        if ($result === null) {
            return $this->permissionError('Music');
        }

        return ToolResult::success(json_encode([
            'action' => 'play_pause',
            'status' => trim($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    // -------------------------------------------------------------------------
    // Shortcuts Actions
    // -------------------------------------------------------------------------

    private function listShortcuts(): ToolResultInterface
    {
        $result = $this->run('shortcuts list 2>&1', 30);

        $items = array_filter(
            array_map('trim', explode("\n", $result['stdout'])),
            fn($line) => $line !== ''
        );

        return ToolResult::success(json_encode([
            'action' => 'list_shortcuts',
            'shortcuts' => array_values($items),
            'count' => count($items),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
        ]));
    }

    private function runShortcut(array $input): ToolResultInterface
    {
        $name = $input['shortcut_name'] ?? '';
        $shortcutInput = $input['shortcut_input'] ?? '';

        if (empty(trim($name))) {
            return ToolResult::error('shortcut_name is required for run_shortcut.');
        }

        $nameEscaped = escapeshellarg($name);

        if (!empty($shortcutInput)) {
            $inputEscaped = escapeshellarg($shortcutInput);
            $command = "echo {$inputEscaped} | shortcuts run {$nameEscaped} 2>&1";
        } else {
            $command = "shortcuts run {$nameEscaped} 2>&1";
        }

        $result = $this->run($command, 120);

        return ToolResult::success(json_encode([
            'action' => 'run_shortcut',
            'shortcut_name' => $name,
            'output' => $this->truncate($result['stdout']),
            'exit_code' => $result['exit_code'],
            'success' => $result['exit_code'] === 0,
            'error' => $result['exit_code'] !== 0 ? $result['stderr'] : null,
        ]));
    }

    // -------------------------------------------------------------------------
    // Helpers — delegate to AppleScriptRunner
    // -------------------------------------------------------------------------

    private function runOsascript(string $script, int $timeout = 30): ?array
    {
        return $this->runner->runOsascript($script, $timeout);
    }

    private function run(string $command, int $timeout = 60): array
    {
        return $this->runner->runCommand($command, $timeout);
    }

    private function escapeAppleScript(string $value): string
    {
        return $this->runner->escapeAppleScript($value);
    }

    private function parseTsvOutput(string $output, array $fields): array
    {
        return $this->runner->parseTsvOutput($output, $fields);
    }

    private function truncate(string $output): string
    {
        return $this->runner->truncate($output);
    }

    /**
     * Return a helpful permission error for the given app.
     */
    private function permissionError(string $appName): ToolResultInterface
    {
        return ToolResult::error(
            "macOS blocked access to {$appName}. The user must grant Automation permission: "
            . "System Settings > Privacy & Security > Automation > enable phpbot (or Terminal) access to {$appName}. "
            . 'After granting permission, try the action again.'
        );
    }
}
