# Skill: Review Pull Request (Self-Improvement)

## Purpose
Guides a PHPBot instance acting as a community reviewer for self-improvement PRs. Reviewer bots post structured verdict comments that are tallied by `VoteTallier` to determine whether a PR reaches quorum.

## When This Skill Activates
The daemon's `EventRouter` automatically invokes this skill when a `github_pr` event is received (emitted by `GitHubPRWatcher`). The `PRClaimManager` first secures a review slot before this skill runs.

## Review Checklist

Perform each check before posting a verdict:

### 1. Security
- [ ] No changes to `src/Security/`, `config/`, `.env`, `.github/workflows/`
- [ ] No hardcoded credentials, API keys, or tokens
- [ ] No new network calls to unexpected external services
- [ ] No obfuscated or encoded strings

### 2. Protected Paths
- [ ] All changed files are within the allowed risk tier for this PR
- [ ] The `src/SelfImprovement/` directory is untouched
- [ ] `composer.json` / `composer.lock` are untouched

### 3. Code Quality
- [ ] PHP syntax is valid (implied by PR smoke tests passing)
- [ ] No obvious infinite loops or resource leaks
- [ ] No dead code blocks or commented-out production logic
- [ ] Follows PSR-4 namespace conventions

### 4. Correctness
- [ ] The implementation matches the PR title and description
- [ ] New tools/skills are registered correctly (if applicable)
- [ ] No regressions in existing functionality are apparent

### 5. Test Evidence
- [ ] PR description includes smoke test output or CI passing
- [ ] Changed files have been through `php -l`

## Verdict Comment Format

Post **exactly one** comment with this format (the HTML comment must be the first line):

```
<!-- phpbot-verdict -->
{"botId":"<your-bot-id>","prNumber":<n>,"verdict":"pass","confidence":0.87,"notes":"Brief rationale","reviewedAt":"<ISO8601>"}
```

- `verdict`: `"pass"` or `"fail"` only
- `confidence`: 0.0–1.0 (your certainty in the verdict)
- `notes`: one or two sentences explaining the decision

## Scoring Guide

| Score | Confidence | Verdict |
|---|---|---|
| All checks pass, clear benefit | 0.85–1.0 | pass |
| Minor concerns, no blockers | 0.6–0.84 | pass |
| Significant concern, uncertain | 0.4–0.59 | fail |
| Any security or blocked-path violation | 0.0–0.39 | fail |
