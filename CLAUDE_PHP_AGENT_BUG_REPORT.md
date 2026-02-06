# Bug Report: Tool Result Message Structure Corruption

**Package:** `claude-php/agent`  
**Version:** ^1.3  
**Severity:** High (causes task failures on long conversations)  
**Status:** Workaround Available

## Problem Summary

When executing long-running tasks that require many sequential tool calls (typically 10+ iterations), the agent framework produces malformed message structures that violate the Claude API's tool use protocol. Specifically, `tool_use` blocks are generated without corresponding `tool_result` blocks in the immediate following message.

### Error Message
```
messages.25: `tool_use` ids were found without `tool_result` blocks immediately after: toolu_01AXvhT4srNK5qdum2KyfGdn. 
Each `tool_use` block must have a corresponding `tool_result` block in the next message.
```

## Root Cause

The issue occurs in the **ReactLoop** (and potentially other loop strategies) when:

1. The conversation accumulates many messages over multiple iterations
2. Tool results are executed and added to the message history
3. The message structure becomes malformed due to lack of context management
4. The next API call includes tool_use blocks that are missing their paired tool_result blocks in the subsequent message

The problem manifests primarily in scenarios with:
- Many sequential tool calls (10+ iterations)
- Long output from tools (like extracting large text from PDFs)
- Complex task analysis that generates detailed bash command chains

## Reproduction Steps

```php
$bot->run("Prepare a financial analysis of /path/to/large/pdf.pdf");
```

This triggers repeated bash tool calls to extract and analyze financial data. After ~13-15 iterations, the message structure becomes corrupted.

## Current Workaround

In applications using `claude-php/agent`, enable context management when creating agents:

```php
$agent = Agent::create($client)
    ->withName('task_name')
    ->withSystemPrompt($systemPrompt)
    ->withModel('claude-sonnet-4-5')
    ->maxIterations(20)
    ->maxTokens(4096)
    ->withTools($tools)
    // Add this to prevent message corruption:
    ->withContextManagement(150000, [
        'clear_tool_results' => false,
        'preserve_system_prompt' => true,
    ]);
```

This enables automatic message compaction that maintains proper tool_use/tool_result pairing.

## Expected Behavior

Per the [Claude API Tool Use Documentation](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview), the message structure MUST follow this pattern:

```
[
  {
    "role": "assistant",
    "content": [
      {
        "type": "tool_use",
        "id": "toolu_...",
        "name": "tool_name",
        "input": {...}
      }
    ]
  },
  {
    "role": "user",
    "content": [
      {
        "type": "tool_result",
        "tool_use_id": "toolu_...",
        "content": "result text"
      }
    ]
  }
]
```

Every `tool_use` block in an assistant message **must** have a corresponding `tool_result` block in the immediately following user message.

## Recommended Fix

The issue is in `/src/Loops/ToolExecutionTrait.php` and `/src/Loops/ReactLoop.php`. The framework needs to:

1. **Validate message structure before API calls**: Ensure no tool_use blocks are orphaned
2. **Improve message compaction**: When messages get long, ensure tool_use/tool_result pairs remain intact during compaction
3. **Add context management by default**: Enable context management for all agent runs that use tools
4. **Validate tool results**: Ensure `executeTools()` always produces complete tool_result blocks

### Specific Code Areas to Review

**File:** `src/Loops/ReactLoop.php` (lines 124-130)
- When adding tool results to messages, verify that every tool_use in the previous assistant message has a corresponding tool_result

**File:** `src/Loops/ToolExecutionTrait.php` (lines 87-120)
- Ensure the `executeTools()` method produces tool_result blocks for every identified tool_use
- Add validation that tool_use_id matches correctly

**File:** `src/Context/ContextManager.php`
- When compacting messages, preserve the complete tool_use/tool_result block pairs
- Never split a tool_use block from its corresponding tool_result block

## Testing Recommendation

Add test cases that:
1. Run agents for 15+ iterations with multiple tool calls per iteration
2. Verify message structure at each iteration
3. Generate large tool outputs to stress the message accumulation
4. Run with both context management enabled and disabled

## Impact

This bug affects any application using `claude-php/agent` for:
- File analysis and extraction (PDFs, documents)
- Complex automation tasks requiring multiple bash commands
- Long-running agent workflows
- Data processing pipelines

## Related Claude API Documentation

- [Tool Use Overview](https://platform.claude.com/docs/en/agents-and-tools/tool-use/overview)
- [Tool Use Best Practices](https://platform.claude.com/docs/en/build-a-python-agent)
- [Message Structure Specification](https://platform.claude.com/docs/en/api/messages-api)

---

**Submitted:** 2026-02-06  
**Affected Users:** Production applications relying on multi-iteration tool use workflows
