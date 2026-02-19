<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\DryRun\DryRunContext;
use Dalehurley\Phpbot\Storage\KeyStore;

class StoreKeysTool implements ToolInterface
{
    use ToolDefinitionTrait;
    private ?KeyStore $keyStore = null;

    public function __construct(array $config = [])
    {
        $path = $config['keys_storage_path'] ?? '';
        if (is_string($path) && $path !== '') {
            $this->keyStore = new KeyStore($path);
        }
    }

    public function getName(): string
    {
        return 'store_keys';
    }

    public function getDescription(): string
    {
        return 'Store credentials in the keystore after receiving them from the user. Call this AFTER ask_user returns with API keys, tokens, or secrets so they are available for future runs. Common keys: twilio_account_sid, twilio_auth_token, twilio_phone_number, openai_api_key. Use lowercase snake_case for key names.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keys' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'description' => 'Key-value pairs to store (e.g. {"twilio_account_sid": "AC...", "twilio_auth_token": "xxx", "twilio_phone_number": "+1..."})',
                ],
            ],
            'required' => ['keys'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        if ($this->keyStore === null) {
            return ToolResult::error('KeyStore not configured (keys_storage_path is missing).');
        }

        $keys = $input['keys'] ?? [];
        if (!is_array($keys)) {
            return ToolResult::error('Keys must be an object of key-value pairs.');
        }

        // Dry-run: simulate without storing
        if (DryRunContext::isActive()) {
            $keyNames = array_filter(array_keys($keys), fn($k) => trim((string) $k) !== '');
            DryRunContext::record('store_keys', 'Store credentials', [
                'keys' => implode(', ', $keyNames),
            ]);
            return ToolResult::success(json_encode([
                'stored' => $keyNames,
                'dry_run' => true,
                'message' => '[DRY-RUN] Key storage simulated — no keys saved.',
            ]));
        }

        $stored = [];

        foreach ($keys as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '') {
                $this->keyStore->set($key, $value);
                $stored[] = $key;
            }
        }

        return ToolResult::success(json_encode([
            'stored' => $stored,
            'message' => count($stored) > 0 ? 'Stored ' . count($stored) . ' key(s) for future use.' : 'No keys to store.',
        ]));
    }

}
