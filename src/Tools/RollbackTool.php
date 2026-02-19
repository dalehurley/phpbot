<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Storage\RollbackManager;

class RollbackTool implements ToolInterface
{
    public function __construct(private RollbackManager $rollbackManager) {}

    public function getName(): string
    {
        return 'rollback';
    }

    public function getDescription(): string
    {
        return 'Roll back all file changes made in the current or a previous session. '
            . 'Use "list" to see available rollback points, "rollback" to revert a session\'s changes. '
            . 'Call this if a bulk operation fails and you need to undo all changes atomically.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'rollback'],
                    'description' => '"list" to see available sessions, "rollback" to revert a session',
                ],
                'session_id' => [
                    'type' => 'string',
                    'description' => 'Session ID to roll back (required for "rollback" action)',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $action = (string) ($input['action'] ?? '');
        $sessionId = (string) ($input['session_id'] ?? '');

        return match ($action) {
            'list' => $this->listSessions(),
            'rollback' => $this->rollback($sessionId),
            default => ToolResult::error("Unknown action: {$action}. Use 'list' or 'rollback'."),
        };
    }

    private function listSessions(): ToolResultInterface
    {
        $sessions = $this->rollbackManager->listSessions();

        if (empty($sessions)) {
            return ToolResult::success(json_encode([
                'sessions' => [],
                'message' => 'No rollback sessions available.',
            ]));
        }

        return ToolResult::success(json_encode([
            'session_count' => count($sessions),
            'sessions' => $sessions,
        ]));
    }

    private function rollback(string $sessionId): ToolResultInterface
    {
        if ($sessionId === '') {
            return ToolResult::error('session_id is required for rollback action.');
        }

        try {
            $report = $this->rollbackManager->rollback($sessionId);

            $message = sprintf(
                'Rollback complete: %d file(s) restored, %d file(s) deleted.',
                count($report['restored']),
                count($report['deleted']),
            );

            if (!empty($report['errors'])) {
                $message .= ' Errors: ' . implode('; ', $report['errors']);
            }

            return ToolResult::success(json_encode([
                'session_id' => $sessionId,
                'restored' => $report['restored'],
                'deleted' => $report['deleted'],
                'errors' => $report['errors'],
                'message' => $message,
            ]));
        } catch (\Throwable $e) {
            return ToolResult::error("Rollback failed: {$e->getMessage()}");
        }
    }

    public function toDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'input_schema' => $this->getInputSchema(),
        ];
    }
}
