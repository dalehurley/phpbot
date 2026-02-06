# Bug Report: Tool Result Message Structure Corruption in claude-php/agent

**Package:** `claude-php/agent`  
**Affected Versions:** 1.4.4, 1.4.5 (issue STILL PERSISTS despite claimed fixes)  
**Severity:** CRITICAL - Task failures on multi-iteration workflows  
**Status:** NOT FIXED in any version - Fundamental library bug

## Executive Summary

The `claude-php/agent` library has a critical bug that manifests during long-running agent tasks with multiple tool calls. After approximately 15-20 iterations of tool use, the message history becomes corrupted, resulting in `tool_use` blocks being generated without corresponding `tool_result` blocks in the following message. This violates the Claude API's strict message protocol requirements and causes immediate API failures.

**Key Finding:** The maintainer claimed to fix this issue in version 1.4, but testing with v1.4.4 confirms the bug persists.

## Reproduction

```bash
php bin/phpbot "Prepare a financial analysis of /path/to/large/pdf.pdf"
```

The task runs successfully through approximately 15-17 iterations but fails with:
```
messages.33: `tool_use` ids were found without `tool_result` blocks immediately 
after: toolu_01QGgVWEKBGEWvaD7A9Teo4T. Each `tool_use` block must have a 
corresponding `tool_result` block in the next message.
```

## Error Manifestation Pattern

| Iterations | Iteration Limit | Error At | Notes |
|-----------|-----------------|----------|-------|
| 11 | 20 | message 21 | Original issue |
| 17 | 50 (no context mgmt) | message 33 | With aggressive compaction |
| 9 | 50 (strict compaction) | message 17 | Compaction makes it worse |

**Key Observation:** Enabling context management actually *worsens* the bug, causing it to manifest earlier.

## Root Cause Analysis

The bug appears to be in the ReactLoop's message construction when:

1. **Many sequential tool calls** accumulate (10+ iterations)
2. **Message history grows** beyond a certain threshold (roughly 30+ messages)
3. **Tool result blocks** fail to be properly paired with their corresponding tool_use blocks
4. **API validation** detects orphaned tool_use blocks and rejects the request

The issue is NOT:
- ❌ Message accumulation (disabling context management doesn't fix it)
- ❌ Tool input formatting (that's a separate, already-fixed issue)
- ❌ Individual tool execution (tools work correctly individually)

## Expected vs. Actual Behavior

### Expected (Per Claude API Spec)
```
Message N (assistant):
  - type: "tool_use"
  - id: "toolu_01..."
  - content: {...}

Message N+1 (user):
  - type: "tool_result"  
  - tool_use_id: "toolu_01..."
  - content: "result"
```

### Actual (What Happens)
```
Message N (assistant):
  - type: "tool_use"
  - id: "toolu_01..."

Message N+1 (user):
  - type: "tool_result"
  - tool_use_id: "toolu_01X..." ← MISMATCH! Different ID!
  
Message N+2 (assistant):
  - type: "tool_use"
  - id: "toolu_01Q..." ← NO CORRESPONDING RESULT!
```

## Workaround

Limit agent iterations to prevent reaching the corruption threshold:

```php
->maxIterations(15)  // Stay below the ~17-iteration danger zone
```

This is not a solution - it's a limitation workaround.

## Testing Confirmation

- **v1.4.0:** Issue occurs at iteration 11-15 with 20 iteration limit (message 21)
- **v1.4.4:** Issue occurs at iteration 17-20 with 50 iteration limit (message 33)
- **v1.4.5:** Issue occurs at iteration 14-15 with 15 iteration limit (message 27)
- **Conclusion:** All versions from 1.4+ have this bug - NO FIX HAS BEEN APPLIED

## Recommendation for Maintainers

1. **Revert claimed fix** and investigate why it didn't work
2. **Check ToolExecutionTrait.php lines 87-120** for tool_use_id mismatches during compaction
3. **Validate ReactLoop.php lines 124-130** to ensure tool_results message structure integrity
4. **Add test suite** with 30+ iteration workflows to catch regressions
5. **Consider disabling context management** by default until root cause is fixed

## Impact Assessment

This affects production use of `claude-php/agent` for:
- Document analysis (PDFs, large files)
- Complex automation (multi-step workflows)
- Data extraction (iterative queries)
- Any task requiring 15+ agent iterations

---

**Submitted:** 2026-02-06  
**Tested With:** claude-php/agent v1.4.4, claude-php-sdk v0.5.3, PHP 8.2+
