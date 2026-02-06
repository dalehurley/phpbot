<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Storage\KeyStore;

class GetKeysTool implements ToolInterface
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
        return 'get_keys';
    }

    public function getDescription(): string
    {
        return 'Check the keystore for stored credentials before asking the user. Use this BEFORE ask_user when you need API keys, tokens, or secrets (e.g. Twilio SID, Auth Token, OpenAI key). Common keys: twilio_account_sid, twilio_auth_token, twilio_phone_number, openai_api_key. Returns found values and lists any missing keys.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'keys' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Key names to look up (e.g. twilio_account_sid, twilio_auth_token, twilio_phone_number)',
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
            return ToolResult::error('Keys must be an array of key names.');
        }

        $found = [];
        $missing = [];

        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $value = $this->keyStore->get($key);
            if ($value !== null) {
                $found[$key] = $value;
            } else {
                $missing[] = $key;
            }
        }

        return ToolResult::success(json_encode([
            'found' => $found,
            'missing' => $missing,
            'all_found' => empty($missing),
        ]));
    }

}
