# Skill Creation Bug Fix

## Problem

When the PHPBot successfully completed a task (sending an SMS), it did not automatically create a reusable skill, even though the interaction involved:
- Checking for credentials (get_keys)
- Asking the user for input (ask_user) 
- Making an external API call (bash with curl)

This is a valuable learned pattern that should be reused in the future.

## Root Causes

There were TWO issues preventing skill creation:

### 1. Task Analyzer Underestimating Complexity (TaskAnalyzer.php)

The TaskAnalyzer had no guidance on how to classify tasks, so it likely classified "send an SMS" as:
- `complexity: simple` (too low!)
- `estimated_steps: 1` (too low!)

But sending an SMS actually requires multiple steps:
1. Check credentials (get_keys)
2. Ask user for phone number (ask_user)
3. Make API call (bash)

**Fix:** Added detailed complexity guidelines to TaskAnalyzer's system prompt so it correctly classifies tasks involving external APIs as "medium" complexity with realistic step counts.

### 2. Overly Restrictive Skill Creation Threshold (SkillAutoCreator.php)

The `shouldCreateSkill()` method had this logic:

```php
// OLD CODE (BROKEN)
if ($complexity === 'simple') {
    return false;  // Reject ALL simple tasks!
}

if ($steps < 3) {
    return false;  // Reject if fewer than 3 steps!
}

return true;
```

This meant that even a task like "send SMS" (which should be medium complexity) would need 3+ steps to create a skill.

**Fix:** Made the threshold much more permissive:

```php
// NEW CODE (FIXED)
// Always keep medium and complex tasks
if ($complexity !== 'simple') {
    return true;
}

// For simple tasks, require at least 2 steps to consider it repeatable
// Single-step simple tasks (e.g., "echo hello") are not worth capturing
if ($steps >= 2) {
    return true;
}

// Reject only the most trivial single-step simple tasks
return false;
```

## Philosophy

The new logic follows these principles:

1. **Low threshold for skill creation**: If a user asked for something and the bot got it done, that's worth capturing for reuse.

2. **Storage is cheap**: Redundant or marginal skills cause no harm.

3. **False negatives are worse**: Not creating a skill (false negative) is worse than creating a trivial one (false positive).

4. **Smart complexity classification**: Tasks involving external APIs, credentials, or user interaction are correctly identified as "medium" complexity, not "simple".

## Changed Files

- **src/SkillAutoCreator.php**: Modified `shouldCreateSkill()` method with better logic and documentation
- **src/TaskAnalyzer.php**: Enhanced system prompt with complexity guidelines and examples

## Behavior After Fix

Now when the bot completes a task like "send an SMS saying 'bo yo'":

1. TaskAnalyzer correctly classifies it as `complexity: medium` with `estimated_steps: 3-4`
2. SkillAutoCreator sees `complexity !== 'simple'` and immediately returns `true`
3. The skill is created with the procedure, bundled scripts, and reference commands
4. Future SMS requests can reuse the learned skill

## Testing

To verify the fix works:

```bash
phpbot> send an sms saying "hello world"
# ... bot completes the task ...
# ✅ Should see: "Created skill: send-sms-message"

phpbot> send an sms saying "goodbye"
# ... bot should now use the created skill (faster)
```
