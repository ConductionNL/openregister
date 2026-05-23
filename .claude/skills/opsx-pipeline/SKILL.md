---
name: opsx-pipeline
description: Process multiple OpenSpec changes in parallel using subagents — full lifecycle from proposal to merged PR
metadata:
  category: Workflow
  tags: [workflow, parallel, pipeline, experimental]
---

Process one or more OpenSpec change proposals through the full lifecycle using parallel subagents. Each change gets its own agent, worktree, branch, and PR.

**Input**: Optionally specify change names, a repo name, or `all` to process all open proposals.

Examples:
- `/opsx-pipeline all` — process all open proposals across all repos
- `/opsx-pipeline procest` — process all open proposals in Procest
- `/opsx-pipeline sla-tracking routing` — process specific changes by name

**Overview**

This command automates the full OpenSpec lifecycle per change:

```
proposal → ff (specs/design/tasks) → apply (implement) → verify → browser verify (optional) → archive + feature docs → PR
```

Each change runs as an independent subagent in an isolated git worktree on its own feature branch. The main agent orchestrates, monitors, and reports.

---

## Steps

### 1. Discover changes to process

Scan for open proposals (directories in `openspec/changes/` that contain a `proposal.md` but are NOT in `archive/`).

```bash
# For each app directory, find open proposals
for app in procest pipelinq docudesk openregister opencatalogi mydash nldesign larpingapp openconnector softwarecatalog zaakafhandelapp; do
  if [ -d "$app/openspec/changes" ]; then
    for change in $app/openspec/changes/*/proposal.md; do
      echo "$app:$(basename $(dirname $change))"
    done
  fi
done
```

Also check `.github/openspec/changes/` for org-wide proposals.

**Filter** based on input:
- `all` → process everything found
- `<repo-name>` → only changes in that repo
- `<change-name> [change-name...]` → only those specific changes (search across all repos)

**If no input provided**, list all discovered changes and use **AskUserQuestion** to let the user select which to process.

### 2. Build the execution plan

For each change, determine:
- **App directory**: e.g., `procest/`
- **Change name**: e.g., `brp-kvk-register-sets`
- **GitHub repo**: e.g., `ConductionNL/procest` (from git remote)
- **Existing issue**: Check if an `openspec`-labeled issue already exists for this change (search GitHub issues by title)

Display the plan:

```
## Pipeline Plan

| # | App | Change | GitHub Repo | Issue |
|---|-----|--------|-------------|-------|
| 1 | procest | brp-kvk-register-sets | ConductionNL/procest | #103 |
| 2 | pipelinq | sla-tracking | ConductionNL/pipelinq | #79 |
| ... | ... | ... | ... | ... |

Total: N changes across M repositories
Max parallel agents: 5 (browser-2 through browser-5, browser-7)
```

Use **AskUserQuestion** to confirm: "Process these N changes? Each will get a feature branch, full implementation, and PR to development."

Options:
- **Yes, start the pipeline** — proceed
- **Let me adjust the selection** — re-select changes
- **Cancel** — abort

### 2.5. Select model strategy

Explain the model options for implementation sub-agents:

| Model | Speed | Quota | Best for |
|---|---|---|---|
| **Haiku** | Fastest | Low | Config tweaks, text changes, single-file edits, minor fixes |
| **Sonnet** | Balanced | Moderate | Feature additions, standard CRUD, multi-file changes |
| **Opus** | Slowest | High | Complex architectural changes, new services/schemas, cross-app impact |

Ask the user using AskUserQuestion:

**"How should models be assigned across the {N} changes in this pipeline run?"**

- **One model for all** — pick a single model for every sub-agent
- **Choose per change** — you select the model for each change individually
- **Auto-select per change** — I'll read each change's proposal and assign the best model based on scope

---

**If "One model for all"**, ask using AskUserQuestion:

> **"Which model for all sub-agents?"**
> - **Haiku** — fastest, lowest quota. Best if all changes are small/simple.
> - **Sonnet (recommended)** — balanced. Handles most feature work well.
> - **Opus** — highest quality. Best for complex architectural changes, but uses significant quota per agent — consider carefully with multiple parallel changes.

Store as `{PIPELINE_MODEL}` (apply uniformly to all agents).

---

**If "Choose per change"**, for each change show its name and (if available) the title from its `proposal.md`. Ask per change using AskUserQuestion:

> **"Model for `[change-name]`?"**
> - **Haiku** — simple change (config, text, minor fix)
> - **Sonnet (recommended)** — standard feature work
> - **Opus** — complex change (new architecture, cross-app impact)

Store results as `{CHANGE_MODELS}` map of `change-name → model`.

---

**If "Auto-select per change"**, for each change read the first 50 lines of its `proposal.md` (title, type, scope). Assign:
- **Haiku**: Config/copy/text changes, single-file edits, documentation only
- **Sonnet**: New features, multi-file changes, standard CRUD, UI additions
- **Opus**: New services or schemas, cross-app changes, migrations, architectural decisions, security-sensitive changes

Show the assignments and ask using AskUserQuestion:

> **"Auto-assigned models — proceed or adjust?"**
> - **Proceed** — use as assigned
> - **Adjust** — change any individual assignments

Store results as `{CHANGE_MODELS}` map of `change-name → model`.

---

### 2.75. Check local environment & browser testing

Before launching agents, check whether the Nextcloud development environment is reachable:

```bash
curl -s -o /dev/null -w "%{http_code}" http://nextcloud.local/status.php
```

**If reachable (HTTP 200)**, ask using AskUserQuestion:

**"Nextcloud is running at nextcloud.local. Include browser testing in the pipeline?"**

- **Yes, test all changes** — every subagent gets a browser and runs UI verification after implementation
- **Yes, but only for UI changes** — only assign browsers to changes that touch frontend files (`.vue`, `.js`, `.ts`, `.css`)
- **No, skip browser testing** — code-only verification, no browser

Store as `{BROWSER_TESTING}` (`all`, `ui-only`, or `none`).

**If NOT reachable**, ask using AskUserQuestion:

**"Nextcloud is not running at nextcloud.local. Browser testing requires a running environment."**

- **Start it and retry** — I'll wait while you start the environment, then re-check
- **Skip browser testing** — proceed without browser verification
- **Cancel pipeline** — abort so you can fix the environment first

If the user chooses "Start it and retry", re-run the health check after they confirm. If still unreachable, ask again.

When `{BROWSER_TESTING}` is `all` or `ui-only`, assign browser numbers to eligible agents: browser-2 through browser-5, browser-7 (max 5 concurrent).

---

### 3. Prepare branches and worktrees

For each change, **before launching agents**:

a. **Determine branch name**: `feature/<issue-number>/<change-name>`
   - If no issue exists yet, create one first (titled `[OpenSpec] <change-title>`, labeled `openspec`)
   - Example: `feature/103/brp-kvk-register-sets`

b. **Create git worktree**:
   ```bash
   cd <app-directory>
   git fetch origin development
   git worktree add /tmp/worktrees/<app>-<change-name> -b feature/<issue-number>/<change-name> origin/development
   ```

c. **Update issue status**: Add a comment "🚀 Pipeline started — processing change"

### 4. Launch parallel subagents

Launch one subagent per change. **Maximum 5 concurrent agents** — if more changes exist, queue them and launch new agents as earlier ones complete.

Read the full sub-agent prompt template at [templates/sub-agent-prompt-template.md](templates/sub-agent-prompt-template.md).

Replace `{VARIABLES}` before sending: `<app-name>`, `<change-name>`, `<issue-number>`, `<owner/repo>`, worktree path, branch name, `{BROWSER_TESTING}`, and the assigned `<browser-N>` (or `"none"`).

**Agent configuration:**
- Use `isolation: "worktree"` if the agent supports it, OR pre-create worktrees in Step 3
- Use `run_in_background: true` for all agents
- If `{BROWSER_TESTING}` is `all`: assign browser numbers (browser-2 through browser-5, browser-7) to all agents
- If `{BROWSER_TESTING}` is `ui-only`: assign browser numbers only to agents whose changes touch `.vue`, `.js`, `.ts`, or `.css` files; pass `"none"` for others
- If `{BROWSER_TESTING}` is `none`: pass browser `"none"` for all agents
- Pass `model: {PIPELINE_MODEL}` (one model for all) or `model: {CHANGE_MODELS}[change-name]` (per-change) when launching each agent

### 5. Monitor progress

While agents are running:
- Track which agents have completed
- As each agent completes, capture its result summary
- If an agent fails, log the error and continue with others
- Launch queued agents as slots become available

Display progress updates:

```
## Pipeline Progress

| # | App | Change | Model | Status | Tasks | Quality | Browser | Docs |
|---|-----|--------|-------|--------|-------|---------|---------|------|
| 1 | procest | brp-kvk-register-sets | Sonnet | ✓ Complete | 7/7 | All pass | ✓ 3 scenarios | ✓ Updated |
| 2 | pipelinq | sla-tracking | Opus | ⏳ Running | 3/5 | — | — | — |
| 3 | pipelinq | routing | Sonnet | ⏳ Queued | — | — | — | — |
```

### 6. Create Pull Requests

For each successfully completed change, **first push the feature branch to origin** (the worktree may not have pushed yet), then create a PR from the feature branch to `development`:

```bash
# Push the feature branch to origin (required before PR creation)
git -C /tmp/worktrees/<app>-<change-name> push -u origin feature/<issue-number>/<change-name>

# Now open the PR
gh pr create \
  --repo <owner/repo> \
  --base development \
  --head feature/<issue-number>/<change-name> \
  --title "feat(<app>): <Change Title>" \
  --body "$(cat templates/pr-body-template.md)"
```

Read the PR body template at [templates/pr-body-template.md](templates/pr-body-template.md) and fill in the placeholders (summary bullets, tracking issue, task count, quality/browser results, feature doc paths, standards) before running `gh pr create`.

Update the original issue with a link to the PR.

### 7. Clean up worktrees

After all PRs are created:
```bash
# For each completed change
cd <app-directory>
git worktree remove /tmp/worktrees/<app>-<change-name>
```

### 8. Final report

Display the complete pipeline results:

```
## Pipeline Complete

### Results
| # | App | Change | Model | Branch | PR | Tasks | Quality | Browser | Docs | Status |
|---|-----|--------|-------|--------|-----|-------|---------|---------|------|--------|
| 1 | procest | brp-kvk-register-sets | Sonnet | feature/103/brp-kvk-register-sets | #105 | 7/7 | ✓ | ✓ 3 | ✓ | Merged-ready |
| 2 | pipelinq | sla-tracking | Opus | feature/79/sla-tracking | #82 | 5/5 | ✓ | Skipped | ✓ | Merged-ready |
| ... |

### Summary
- Changes processed: N
- Successful: N
- Failed: N (with reasons)
- PRs created: N
- Total tasks implemented: N
- Total tests written: N
- Browser scenarios tested: N (or "Skipped")
- Feature docs created/updated: N

### Failed Changes (if any)
- <change-name>: <reason for failure>
  Worktree preserved at: /tmp/worktrees/<app>-<change-name>
  To resume: fix the issue and run `/opsx-pipeline <change-name>`
```

---

## Capture Learnings

After execution, review what happened and append new observations to [learnings.md](learnings.md) under the appropriate section:

- **Patterns That Work** — approaches that produced good results
- **Mistakes to Avoid** — errors encountered and how they were resolved
- **Domain Knowledge** — facts discovered during this run
- **Open Questions** — unresolved items for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.

---

## Guardrails

- **Worktree isolation**: Each change works in `/tmp/worktrees/<app>-<change-name>` — NEVER modify the main working directory from a subagent
- **Branch naming**: Always `feature/<issue-number>/<change-name>` based off `origin/development`
- **No destructive git ops**: No force push, no reset, no clean, no rebase on shared branches
- **Max parallelism**: 5 concurrent agents (limited by browser pool and system resources)
- **Autonomous operation**: Subagents resolve issues themselves. Only escalate to user if fundamentally blocked (e.g., missing dependency, ambiguous requirement with no reasonable default)
- **Quality gates**: Every change must pass quality checks before PR. If checks fail after 3 fix cycles, mark as failed and preserve worktree for manual intervention
- **Issue hygiene**: Every change gets issues, every task updates its issue, every PR references its issues
- **No Co-Authored-By**: Commit messages must NOT include Co-Authored-By trailers
- **Commit per task**: Each implemented task gets its own commit with a descriptive message
- **PR to development**: Always target `development` branch, never `main` or `beta`
- **Feature docs**: Every change must update or create its feature doc in `docs/features/` during archive. The feature overview table in `docs/features/README.md` must stay in sync.
- **Browser testing**: Controlled by user choice in Step 2.75. When enabled, subagents use their assigned browser for UI verification. Never silently skip — the user's choice is authoritative.

## Error Handling

- **Agent timeout**: If an agent runs for more than 30 minutes with no progress, consider it stuck. Preserve worktree and report.
- **Quality check failures**: Agent fixes up to 3 cycles. After that, mark as failed with details.
- **Git conflicts**: If worktree creation fails due to branch conflicts, create from a fresh development checkout.
- **Missing openspec CLI**: If `openspec` command is not available, fall back to manual artifact creation (read proposal, create specs/design/tasks manually following the templates).
- **Org-wide changes (.github)**: These don't follow the same app structure. Skip ff/apply for these — they are documentation/compliance changes that need manual implementation per app.
- **Missing vendor/node_modules**: Install dependencies in the worktree. If install fails, run what you can (php -l syntax checks, pattern matching against reference code) and note that full checks will run in CI.
- **Browser tools fail mid-test**: If browser tools error during Phase 4b (e.g., Nextcloud crashed, browser unresponsive), mark browser testing as "Partial" in the report with the failure reason. Do NOT re-ask the user — the pipeline continues, but the PR description must note the partial result.
