# Skill: Self-Improvement Pipeline

## Purpose
Allows PHPBot to propose, build, and submit its own feature improvements as GitHub pull requests for community review.

## Triggers
Use this skill when the user says any of:
- "improve yourself"
- "add a feature to yourself"
- "submit a feature"
- "make yourself better at ..."
- `/feature <description>`
- `/feature status`
- `/feature withdraw <pr-number>`

## Pipeline Steps

When `/feature <description>` is invoked:

1. **Classify** — Determine the change type (`skill`, `tool`, or `core`) and risk tier using `ProtectedPathGuard`.
2. **Branch** — Create a new git branch `phpbot/feat-<slug>-<date>`.
3. **Build** — The bot implements the feature using its standard tools (write_file, edit_file, bash, etc.).
4. **Smoke test** — Validate with `php -l`, PHPStan (if available), and a bot self-test.
5. **Commit & Push** — Stage all changes, commit with a structured message, push to origin.
6. **PR** — Create a GitHub PR with the `phpbot-self-improvement` label and a review table.
7. **Return to main** — Switch the local branch back to `main`.
8. **Notify** — Inform the user of the PR URL and review requirements.

## Risk Tiers

| Tier | Scope | Quorum |
|------|-------|--------|
| `skill` | `skills/` directory only | 2 of 3 |
| `tool` | New files in `src/Tools/` | 3 of 5 |
| `core` | Existing `src/` files | 5 of 7 + maintainer |
| `blocked` | Security, config, self-improvement infra | Not allowed |

## Passive Gap Detection

After each task completes, `ImprovementDetector` scores the run. If the score exceeds the threshold (complex task, many iterations, no matching skill, repeated errors), the bot will suggest:

> "Tip: I noticed I could handle tasks like this more efficiently with a dedicated capability. Type `/feature <description>` to submit it for community review."

The user must explicitly confirm by running `/feature` — improvements are never submitted automatically.

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `PHPBOT_SELF_IMPROVEMENT` | `true` | Enable/disable the pipeline |
| `PHPBOT_BOT_ID` | hostname | Unique ID for this bot instance |
| `PHPBOT_GITHUB_REPO` | _(none)_ | `owner/repo` for PR creation |
| `PHPBOT_MAX_RISK_TIER` | `tool` | Max allowed risk tier |
| `PHPBOT_FEATURE_CONFIRM` | `true` | Require confirmation before branching |
