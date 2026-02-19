<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Security\KeyRotationManager;
use Dalehurley\Phpbot\Storage\RollbackManager;

class KeyRotationTool implements ToolInterface
{
    public function __construct(
        private KeyRotationManager $keyRotationManager,
        private ?RollbackManager $rollbackManager = null,
        private ?string $sessionId = null,
    ) {}

    public function getName(): string
    {
        return 'rotate_keys';
    }

    public function getDescription(): string
    {
        return 'Rotate API keys across multiple files for multiple providers. '
            . 'Detects existing keys (OpenAI, Anthropic, Google, Twilio, Stripe, GitHub, SendGrid, Slack), '
            . 'creates a rollback snapshot, then replaces old keys with new ones. '
            . 'Use "detect" action first to find where keys are used, then "rotate" to replace them.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['detect', 'rotate', 'list_providers'],
                    'description' => '"detect" scans files for existing keys, "rotate" replaces old keys with new ones, "list_providers" shows supported providers',
                ],
                'files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'File paths to scan or update (required for detect/rotate)',
                ],
                'replacements' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'description' => 'Map of old_key => new_key for the rotate action',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $action = (string) ($input['action'] ?? '');
        $files = array_map('strval', (array) ($input['files'] ?? []));
        $replacements = (array) ($input['replacements'] ?? []);

        return match ($action) {
            'detect' => $this->detect($files),
            'rotate' => $this->rotate($files, $replacements),
            'list_providers' => $this->listProviders(),
            default => ToolResult::error("Unknown action: {$action}. Use 'detect', 'rotate', or 'list_providers'."),
        };
    }

    private function detect(array $files): ToolResultInterface
    {
        if (empty($files)) {
            return ToolResult::error('At least one file path is required for detect.');
        }

        $detected = $this->keyRotationManager->detectProviders($files);

        if (empty($detected)) {
            return ToolResult::success(json_encode([
                'detected' => [],
                'message' => 'No known credential patterns found in the provided files.',
            ]));
        }

        // Redact key values for safety — only show file locations
        $safe = [];
        foreach ($detected as $provider => $info) {
            $safe[$provider] = [
                'label' => $info['label'],
                'env_var' => $info['env_var'],
                'found_in_files' => array_keys($info['files']),
                'occurrence_count' => array_sum(array_map('count', $info['files'])),
            ];
        }

        return ToolResult::success(json_encode([
            'providers_detected' => count($safe),
            'detected' => $safe,
        ]));
    }

    private function rotate(array $files, array $replacements): ToolResultInterface
    {
        if (empty($files)) {
            return ToolResult::error('At least one file path is required for rotate.');
        }

        if (empty($replacements)) {
            return ToolResult::error('replacements map is required for rotate (old_key => new_key).');
        }

        // Snapshot files before rotation for rollback safety
        if ($this->rollbackManager !== null && $this->sessionId !== null) {
            try {
                $this->rollbackManager->createSnapshot($this->sessionId, $files);
            } catch (\Throwable $e) {
                return ToolResult::error("Cannot create rollback snapshot: {$e->getMessage()}");
            }
        }

        $report = $this->keyRotationManager->replaceKeys($replacements, $files);

        $totalReplacements = array_sum($report['replaced']);
        $changedFiles = count($report['replaced']);

        $message = sprintf(
            '%d replacement(s) made across %d file(s).',
            $totalReplacements,
            $changedFiles,
        );

        if (!empty($report['errors'])) {
            $message .= ' Errors: ' . implode('; ', $report['errors']);
        }

        if ($this->rollbackManager !== null && $this->sessionId !== null) {
            $message .= " Rollback available via session: {$this->sessionId}";
        }

        return ToolResult::success(json_encode([
            'replacements_made' => $totalReplacements,
            'files_changed' => $changedFiles,
            'changed_files' => array_keys($report['replaced']),
            'errors' => $report['errors'],
            'rollback_session' => $this->sessionId,
            'message' => $message,
        ]));
    }

    private function listProviders(): ToolResultInterface
    {
        return ToolResult::success(json_encode([
            'supported_providers' => $this->keyRotationManager->getSupportedProviders(),
        ]));
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
