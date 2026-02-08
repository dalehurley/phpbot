<?php

declare(strict_types=1);

namespace Dalehurley\Phpbot\Tools;

use ClaudeAgents\Contracts\ToolInterface;
use ClaudeAgents\Contracts\ToolResultInterface;
use ClaudeAgents\Tools\ToolResult;
use Dalehurley\Phpbot\Conversation\ConversationHistory;
use Dalehurley\Phpbot\Conversation\ConversationLayer;

/**
 * Tool that gives the agent access to conversation history.
 *
 * Actions:
 *   get_context   — Returns conversation history at the current (or specified) layer
 *   switch_layer  — Changes the active context layer (basic / summarized / full)
 *   get_turn_detail — Returns full detail for a specific previous turn
 */
class ConversationContextTool implements ToolInterface
{
    public function __construct(
        private ConversationHistory $history,
    ) {}

    public function getName(): string
    {
        return 'conversation_context';
    }

    public function getDescription(): string
    {
        return 'Access conversation history from previous turns. Use to recall what was discussed, '
            . 'what tools were used, or what files were modified in earlier requests. '
            . 'You can switch between detail levels: basic (requests+answers), '
            . 'summarized (basic+execution summaries), or full (complete message history).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['get_context', 'switch_layer', 'get_turn_detail'],
                    'description' => 'Action to perform: get_context (view history), switch_layer (change detail level), get_turn_detail (inspect a specific turn)',
                ],
                'layer' => [
                    'type' => 'string',
                    'enum' => ['basic', 'summarized', 'full'],
                    'description' => 'For switch_layer: the target layer. basic=requests+answers, summarized=basic+tool summaries, full=complete messages.',
                ],
                'turn_index' => [
                    'type' => 'integer',
                    'description' => 'For get_turn_detail: which turn to inspect (1 = most recent, 2 = second most recent, etc.)',
                    'minimum' => 1,
                ],
                'max_turns' => [
                    'type' => 'integer',
                    'description' => 'For get_context: maximum number of turns to return (default depends on layer)',
                    'minimum' => 1,
                    'maximum' => 20,
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $input): ToolResultInterface
    {
        $action = trim((string) ($input['action'] ?? ''));

        return match ($action) {
            'get_context' => $this->handleGetContext($input),
            'switch_layer' => $this->handleSwitchLayer($input),
            'get_turn_detail' => $this->handleGetTurnDetail($input),
            default => ToolResult::error("Unknown action: '{$action}'. Use get_context, switch_layer, or get_turn_detail."),
        };
    }

    public function toDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'input_schema' => $this->getInputSchema(),
        ];
    }

    // -----------------------------------------------------------------

    private function handleGetContext(array $input): ToolResultInterface
    {
        if ($this->history->isEmpty()) {
            return ToolResult::success(json_encode([
                'status' => 'empty',
                'message' => 'No conversation history yet. This is the first turn.',
                'turn_count' => 0,
            ]));
        }

        $maxTurns = isset($input['max_turns']) ? (int) $input['max_turns'] : null;
        $context = $this->history->buildContextBlock(null, $maxTurns);

        return ToolResult::success(json_encode([
            'status' => 'ok',
            'active_layer' => $this->history->getActiveLayer()->value,
            'turn_count' => $this->history->getTurnCount(),
            'context' => $context,
        ]));
    }

    private function handleSwitchLayer(array $input): ToolResultInterface
    {
        $layerValue = trim((string) ($input['layer'] ?? ''));

        $layer = ConversationLayer::tryFrom($layerValue);
        if ($layer === null) {
            return ToolResult::error("Invalid layer: '{$layerValue}'. Use basic, summarized, or full.");
        }

        $previousLayer = $this->history->getActiveLayer();
        $this->history->setActiveLayer($layer);

        return ToolResult::success(json_encode([
            'status' => 'ok',
            'previous_layer' => $previousLayer->value,
            'new_layer' => $layer->value,
            'description' => $layer->label(),
            'max_turns' => $layer->defaultMaxTurns(),
        ]));
    }

    private function handleGetTurnDetail(array $input): ToolResultInterface
    {
        $turnIndex = (int) ($input['turn_index'] ?? 1);

        if ($this->history->isEmpty()) {
            return ToolResult::success(json_encode([
                'status' => 'empty',
                'message' => 'No conversation history yet.',
            ]));
        }

        $detail = $this->history->buildTurnDetailBlock($turnIndex);

        return ToolResult::success(json_encode([
            'status' => 'ok',
            'turn_index' => $turnIndex,
            'turn_count' => $this->history->getTurnCount(),
            'detail' => $detail,
        ]));
    }
}
