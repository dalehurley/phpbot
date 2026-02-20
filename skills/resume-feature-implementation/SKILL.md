---
name: resume-feature-implementation
description: "Resume and complete a partially-implemented feature by reviewing conversation history, identifying completed work, and finishing all remaining steps without duplication. Use this skill when asked to continue a project, pick up where you left off, complete a feature in progress, or resume work from a previous session. Triggers include phrases like 'continue implementing', 'resume work', 'finish the feature', 'complete what was started', or 'pick up from where we left off'."
---

# Resume Feature Implementation

## Input Parameters

| Parameter | Required | Description | Example |
|-----------|----------|-------------|---------|
| `conversation_history` | Yes | Previous conversation context showing what was already completed | User asked to build a browser automation skill. Files created: skill.md, README.md, package.json, playwright_template.js, gemini_enhanced_browser.js |
| `feature_requirements` | Yes | Original feature specification or task description | Implement browser automation using Playwright and Google Gemini Flash |
| `current_branch` | No | Git branch name where work is being continued | phpbot/feat-use-playwrite-and-google-gemini-flash-to-control-b-20260221 |

## Procedure

1. Review conversation history and git log to identify all previously completed work: `git log --oneline -10` and `git status`
2. List all modified or created files from the previous session: `git diff --name-only HEAD~5..HEAD` or `git show --name-only {{COMMIT_HASH}}`
3. Examine the current state of key files to understand what has been implemented: `head -50 {{FILE_PATH}}` and `tail -20 {{FILE_PATH}}`
4. Identify remaining tasks by comparing completed work against the original feature requirements
5. Execute only the incomplete steps, referencing the sanitized recipe and bundled scripts as needed
6. Commit completed work with a descriptive message: `git add . && git commit -m "{{COMMIT_MESSAGE}}"`
7. Verify completion by running validation checks and confirming all files are in the expected state
8. Generate a summary of what was accomplished in this session

## Output

Summary report including: list of files reviewed, work already completed, remaining tasks executed, new files created or modified, git commit hash, and validation confirmation that feature is complete

## Reference Commands

```bash
git log --oneline -10
git diff --name-only HEAD~5..HEAD
git show --name-only {{COMMIT_HASH}}
head -50 {{FILE_PATH}}
git add . && git commit -m "{{COMMIT_MESSAGE}}"
git status
```

## Example

```
Continue implementing the feature from exactly where you left off
Resume the browser automation skill development and finish all remaining steps
Pick up where we left off on the API integration feature
Complete the partially-implemented data pipeline without repeating work already done
Finish building the authentication module from the previous session
```

## Notes

- Always check git history first to avoid duplicating completed work
- Review file timestamps and git blame to understand what was done in each session
- Use `git diff` to see exactly what changed in previous commits
- Validate that all dependencies and setup from previous work are still in place
- Preserve commit history by understanding what was already committed vs. uncommitted changes
- If work spans multiple files, verify all related files are in a consistent state before resuming

