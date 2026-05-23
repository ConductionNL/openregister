---
name: opsx-apply-loop
description: Iteratively run apply→verify in a loop until verify passes, then auto-archive — runs per-app in Docker context
metadata:
  category: Workflow
  tags: [workflow, automated, loop, docker, experimental]
---

**Check the active model** from your system context (it appears as "You are powered by the model named…").

- **On Haiku**: stop immediately:
  > "This command requires Sonnet or Opus — the apply→verify loop needs strong reasoning to implement tasks and evaluate verification results. Please switch to Sonnet (`/model sonnet`) or Opus (`/model opus`) and re-run."
- **On Sonnet or Opus**: proceed normally.

---

**Check container authentication** — follow the procedure in [references/container-auth-check.md](references/container-auth-check.md). Stop immediately if no credentials are found.

---

**AUTONOMOUS MODE — This skill is a fully automated orchestrator.** The standard CLAUDE.md workflow (ask clarifying questions → present plan → wait for approval) does NOT apply here. Do NOT pause between steps to ask for confirmation or approval unless a step explicitly says to use AskUserQuestion. Proceed through all steps automatically. When an inline skill completes and returns control, immediately continue to the next numbered step without waiting.

---

Automated orchestrator: runs `opsx-apply` → `opsx-verify` in a loop until verify is clean, optionally runs targeted tests (on host), then runs `opsx-archive`. The apply→verify loop runs inside an isolated Docker container (Claude CLI + app files only, no git, no GitHub). Tests run outside the container. Host handles testing, archive, branch creation, GitHub sync, and git commits.

Each app in this workspace has its own git repository. The container mounts the app's directory and a read-only copy of the shared `.claude/` skills. Nextcloud containers must be running on the host for environment checks and post-container testing.

```
[host] issue check → branch (from development) → container start (app dir + .claude skills)
  [container] apply → verify → loop (max 5) → verify-clean → exit
[host] test loop (optional, max 3) → deferred tests (optional, once) → archive (once) → git commit → github sync
```

**Input**: Optionally specify `<app> <change-name>` (e.g., `/opsx-apply-loop procest add-sla-tracking`). If omitted, prompt for app and change.

---

## Step 1: Select app and change

**If both app and change name are provided**, use them directly.

**If only one argument is provided**, treat it as the change name and scan all apps for a match.

**Otherwise**, scan all app directories for active changes and use **AskUserQuestion** to let the user select:

```bash
# Scan for active changes across all apps
for app in procest pipelinq openregister opencatalogi docudesk mydash nldesign openconnector softwarecatalog zaakafhandelapp openklant larpingapp planix; do
  if [ -d "$app/openspec/changes" ]; then
    for change_dir in $app/openspec/changes/*/; do
      if [ -f "${change_dir}tasks.md" ] && [[ "$change_dir" != *"/archive/"* ]]; then
        echo "$app: $(basename $change_dir)"
      fi
    done
  fi
done
```

Ask the user to select from the list. Do not auto-select.

Store as `{APP}` and `{CHANGE_NAME}`. All subsequent file paths use `{APP}/openspec/changes/{CHANGE_NAME}/`.

Always announce: "Using change: `<app>/<change-name>`" and how to override.

---

## Step 2: Check GitHub issue — create if missing

Check whether a GitHub tracking issue already exists for this change:

```bash
cat {APP}/openspec/changes/{CHANGE_NAME}/plan.json 2>/dev/null | grep -q '"tracking_issue"'
```

**If `plan.json` exists and `tracking_issue` is set**: log `✅ GitHub issue #<N> already exists` and proceed. Store as `{ISSUE_NUMBER}`.

**If `plan.json` is missing or has no `tracking_issue`**:
- Log `⚠️ No GitHub tracking issue found — running opsx-plan-to-issues first`
- **Invoke the `opsx-plan-to-issues` skill** for `{CHANGE_NAME}` with this explicit context passed to it: "Invoked from apply-loop — skip Step 6 AskUserQuestion and return control to apply-loop after completing."
- Pre-answer plan-to-issues's interactive prompts automatically:

| Prompt from opsx-plan-to-issues | Answer |
|--------------------------------|--------|
| "Which change(s) should I create GitHub issues for?" | Select `{CHANGE_NAME}` |
| "Create these N issue(s) in `<owner/repo>`?" | **Yes, create all** |

The repo is determined from the app's `project.md` table (GitHub Repo column) or `git remote get-url origin` inside the app directory.

When plan-to-issues completes (look for its summary output or "plan.json saved"), **immediately and automatically continue to Step 3** — do NOT pause, do NOT ask the user anything, do NOT wait for confirmation. You are in autonomous mode.

After plan-to-issues completes, verify `plan.json` now contains a `tracking_issue`. Store as `{ISSUE_NUMBER}`.

---

## Step 3: Create feature branch

Each app is its own git repository. All git operations run from the app directory.

```bash
cd {APP}

# First check if the feature branch already exists locally
git fetch origin
git branch --list "feature/{ISSUE_NUMBER}/{CHANGE_NAME}"
```

**If the branch already exists** (e.g., resuming a previous run):
- Use **AskUserQuestion** to ask: "Branch `feature/{ISSUE_NUMBER}/{CHANGE_NAME}` already exists. Resume work on it or reset it?"
  - **Resume — check it out** → `git checkout feature/{ISSUE_NUMBER}/{CHANGE_NAME}` (skip development checkout/pull)
  - **Reset — delete and recreate** → `git branch -D feature/{ISSUE_NUMBER}/{CHANGE_NAME}`, then follow the **"If the branch does not exist"** flow right below
  - **Cancel** → stop here

**If the branch does not exist** (or after reset):
```bash
# Only checkout and pull development when we need to create a new branch
git checkout development
git pull origin development

# Branch follows the convention: feature/<issue-number>/<change-name>
git checkout -b feature/{ISSUE_NUMBER}/{CHANGE_NAME}
```

Log: `✅ On branch feature/{ISSUE_NUMBER}/{CHANGE_NAME} in {APP}/`

---

## Step 4: Analyze test-plan (silent)

Silently read the test-plan before asking the user anything. This feeds the test cycle option in Step 5.

Check if `{APP}/openspec/changes/{CHANGE_NAME}/test-plan.md` exists.

**If it exists:** read all `test command` field values, deduplicate, then classify each:

| Fits in loop? | Commands | Reason |
|---|---|---|
| **Yes** | `/test-functional` | Single agent, uses Playwright on host against live Nextcloud — tests GIVEN/WHEN/THEN from specs |
| **Yes** | `/test-api` | Single agent, REST API and ZGW compliance via curl — text output, no browser needed |
| **Yes** | `/test-security` | Single agent, uses Playwright on host — include only if change touches auth, roles, or permissions |
| **Yes** | `/test-accessibility` | Single agent, uses Playwright on host to inject axe-core — include only if change touches frontend UI |
| **No (deferred)** | `/test-counsel` | 8 parallel agents |
| **No (deferred)** | `/test-app` | Multi-agent or full-app sweep |
| **No (deferred)** | `/test-persona-*` | Too broad, not change-specific |
| **No (deferred)** | `/test-regression`, `/test-performance` | Cross-feature or non-blocking |

Rules:
- Any persona-specific command (`/test-persona-*`) that appears in the test-plan → replace with `/test-functional` in `{TEST_COMMANDS_IN_LOOP}` (same coverage, single agent)
- If no test-plan exists but tests are opted in → default `{TEST_COMMANDS_IN_LOOP}` = `[/test-functional]`
- All "fits in loop" commands run on the **host** (Step 9) via Playwright MCP and the live Nextcloud app — none of them run inside the Docker container

Store:
- `{TEST_COMMANDS_IN_LOOP}` — the filtered commands to run in the automated test loop
- `{TEST_COMMANDS_DEFERRED}` — the excluded commands to surface at the end (Step 13)
- `{TEST_PLAN_EXISTS}` — true/false

---

## Step 5: Confirm and show plan

Use **AskUserQuestion** to ask:

> "Ready to run `opsx-apply-loop` for `{APP}/{CHANGE_NAME}`?
>
> **Branch:** `feature/{ISSUE_NUMBER}/{CHANGE_NAME}` in `{APP}/`
> **GitHub issue:** #`{ISSUE_NUMBER}`
>
> **Apply→verify loop** (inside isolated Docker container):
> - Max 5 iterations; CRITICAL issues stop the loop; warnings-only proceeds to archive
>
> **Optional: test cycle** (outside container, requires running Nextcloud environment):
> - After verify is clean, runs: `<list TEST_COMMANDS_IN_LOOP, or 'test-functional (default)' if no test-plan>`
> - Max 3 test iterations; if tests fail, loops back into apply→verify
> - ⚠️ These tests run on your host using the live Nextcloud app — NOT inside the container"

Options:
- **Start with test cycle** — include Phase 4 tests (set `{TESTS_ENABLED}=true`)
- **Start without tests** — skip test cycle (set `{TESTS_ENABLED}=false`)
- **Cancel** — stop here

---

## Step 6: Set up the apply-loop container

Read [references/container-setup-guide.md](references/container-setup-guide.md) for the full procedure: creating the log folder (6.1), scanning prior run history (6.2), version checks and image build (6.3), network creation (6.4), container startup with prompt construction (6.5, including the full startup prompt text), monitoring loop (6.6), and container exit handling with all 5 scenarios (6.7). Apply all sub-steps now.

> Steps 7–8 execute **inside the container** by the container's Claude CLI session. The startup prompt (in references/container-setup-guide.md Step 6.5) directs the container to read [references/apply-verify-loop-protocol.md](references/apply-verify-loop-protocol.md).

---

> The steps below (9–15) execute on the **host**, after the container has exited.

---

## Step 9: Host test loop (conditional)

**Skip this step entirely if `{TESTS_ENABLED}=false`.** Proceed directly to Step 10.

Read [references/host-test-loop-protocol.md](references/host-test-loop-protocol.md) for the full protocol: Nextcloud environment check, in-loop test command execution via Agent tool (9a), test result evaluation (9b), and container re-entry for test-failure fixes (9c). Apply the full procedure now.

---

## Step 10: Deferred tests (conditional)

**Skip this step if any of these are true:**
- `{TESTS_ENABLED}=false`
- `{TEST_COMMANDS_DEFERRED}` is empty
- The test loop exhausted in Step 9 with unresolved failures and the user chose to cancel

If applicable, use **AskUserQuestion** to ask:

> "The following test commands were in your test-plan but were not included in the automated loop (multi-agent or broad-scope):
>
> <list {TEST_COMMANDS_DEFERRED} with reason each was excluded>
>
> Would you like to run these now? If any fail, one final apply→verify cycle will run to address the findings before archiving."

Options:
- **Yes, run them** — proceed
- **Skip** — proceed to Step 11 (archive)

**If yes:**

Use the **Agent tool** (NOT the Skill tool) to run each command in `{TEST_COMMANDS_DEFERRED}` sequentially, exactly as described in Step 9a — construct the skill file path, launch a general-purpose Agent in READ-ONLY mode, and read its structured result line. Run all commands once (not looped). After each Agent returns, immediately continue to the next — do NOT pause between commands.

> **Note — reduced parallelism**: Sub-agents spawned via the Agent tool do not have access to the Agent tool themselves, so multi-agent skills like `/test-counsel` (which normally runs 8 persona agents in parallel) will run sequentially instead. Coverage is the same; it just takes longer. This is expected and acceptable for the deferred test pass.

**If all pass**: log `✅ Deferred tests all passed` — **immediately and automatically continue to Step 11** (archive).

**If any fail**:
- Log: `⚠️ Deferred test failures found — running one final apply→verify cycle`
- Write failures: `FAIL_TIME=$(date +%H:%M)` then write to `${LOG_DIR}/apply-loop-${FAIL_TIME}-test-failures-${TEST_ITERATION}.log`
- Start one final container run (Step 6.5, test-failure re-entry variant)
- Wait for exit; handle per Step 6.7
- **Immediately and automatically continue to Step 11** regardless of `STATUS` (report exhaustion if needed, but archive once)

---

## Step 11: Archive (host)

**Use the Agent tool (NOT the Skill tool)** to run `opsx-archive`. The Agent tool runs as a subprocess and returns results directly — this is what allows the orchestrator to continue to Steps 12–15 after archiving. Using the Skill tool inline would terminate the conversation instead of returning control.

Construct the skill file path:
```
CLAUDE_SKILLS="$(cd {APP}/.. && pwd)/.claude/skills"
SKILL_FILE="${CLAUDE_SKILLS}/opsx-archive/SKILL.md"
```

Launch a **general-purpose Agent** with a prompt that includes:
1. "Read and follow the skill instructions at `{SKILL_FILE}`."
2. "Change: `{CHANGE_NAME}`. Working directory: `$(pwd)/{APP}/`."
3. "You are invoked from apply-loop — do NOT close the GitHub issue (that is handled by the host in Step 13c). Return control to apply-loop after completing."
4. Pre-answered prompts:

| Prompt from opsx-archive | Answer |
|--------------------------|--------|
| "Sync delta specs first?" | **Sync now** |
| "Convert test cases to test scenarios?" (step 4.5) | **Skip** — apply-loop asks after all loops finish (Step 14) |
| "Close GitHub issue #N?" | **No, leave it open** |

5. "End with the line `ARCHIVE_RESULT: DONE  ARCHIVE_PATH: <path>`. Output nothing after the result line."

The archive skill handles: artifact completion check, delta spec sync, spec link updates in main specs, `docs/features/` updates, and `CHANGELOG.md`.

When the Agent returns, extract `{ARCHIVE_PATH}` from the result line.

**Immediately and automatically continue to Step 12** — do NOT pause or wait for user input.

Log: `📦 Change archived`

---

## Step 12: Git commit (host)

Commit all changes — implementation, test fixes, and archive artifacts — in one commit. Run from the app directory:

```bash
cd {APP}
git add .
git status  # review what changed
git commit -m "feat: implement {CHANGE_NAME}

Co-Authored-By: Claude Sonnet 4.7 <noreply@anthropic.com>"
```

Log: `✅ Changes committed to feature/{ISSUE_NUMBER}/{CHANGE_NAME} in {APP}/`

---

## Step 13: GitHub sync (host)

The container skipped all GitHub operations. Run them now from the host using the gh CLI.

**13a. Final checkbox sync** — verify the issue reflects the fully archived state of `tasks.md`. Earlier syncs (Step 6.7 Scenario A and Step 9c) updated checkboxes from the pre-archive location; this final sync reads the archived copy to ensure nothing was missed:
- Read `{APP}/openspec/changes/archive/YYYY-MM-DD-{CHANGE_NAME}/tasks.md` (archived location)
- For every task marked `[x]`, ensure the corresponding checkbox is checked in issue #`{ISSUE_NUMBER}` — update any that are still unchecked:
  - **MCP (preferred):** `get_issue` → find any remaining `- [ ]` task lines → change to `- [x]` → `update_issue` (single call)
  - **CLI (fallback):** `gh issue view {ISSUE_NUMBER} --repo <owner/app> --json body --jq '.body'` → update checkboxes → `gh issue edit {ISSUE_NUMBER} --repo <owner/app> --body "<updated>"`

**13b. Add a completion comment** to the issue:
- **MCP (preferred):** `add_issue_comment` → `{owner, repo, issue_number: {ISSUE_NUMBER}, body: "✓ apply-loop complete — all tasks implemented and verified. Change archived to {APP}/openspec/changes/archive/YYYY-MM-DD-{CHANGE_NAME}/"}`
- **CLI (fallback):** `gh issue comment {ISSUE_NUMBER} --repo <owner/app> --body "..."`

**13c. Ask about closing the issue** using **AskUserQuestion**:

> "Close GitHub issue #{ISSUE_NUMBER}?"

Options:
- **Yes, close it** — `gh issue close {ISSUE_NUMBER} --repo <owner/app>`
- **No, leave it open** — skip (e.g., if a PR review will handle it)

Log: `✅ GitHub issue synced`

---

## Step 14: Post-archive — test scenario conversion

Check if `{APP}/openspec/changes/archive/YYYY-MM-DD-{CHANGE_NAME}/test-plan.md` exists.

**If test-plan.md exists**, use **AskUserQuestion** to ask:

> "The change had a test-plan.md. Convert test cases to reusable test scenarios?
>
> Test scenarios are picked up automatically by `/test-counsel`, `/test-app`, and persona test commands."

Options:
- **Yes, convert all** — run the test scenario conversion step from opsx-archive
- **Let me choose** — list each TC, user picks which to convert
- **Skip** — do not create test scenarios

**If test-plan.md does not exist**: skip silently.

---

## Step 15: Final report and what's next

Before composing the report, **read all log files** in `${LOG_DIR}` (`ls -t "${LOG_DIR}"/`) to ensure the summary reflects everything that actually happened across all container runs and test iterations. Read each file:
- All `apply-loop-*-result.log` files — collect STATUS, ITERATIONS, WARNINGS_ONLY from each run
- All `apply-loop-*-container.log` files — skim for apply/verify outcomes per iteration
- All `apply-loop-*-test-failures-*.log` files — note which test iterations failed and what was reported
- The in-memory iteration log you maintained throughout this session

Reconcile any discrepancies between the in-memory log and the file contents — the files are authoritative.

Deliver the final report using the template in [references/loop-report-template.md](references/loop-report-template.md). Fill in all placeholders: loop log entries, summary table, pass/fail counts, remaining issues list.

Then use **AskUserQuestion** to ask: "What would you like to do next?"

Options:
- **Create a PR** (`/create-pr`) — open a pull request from `feature/{ISSUE_NUMBER}/{CHANGE_NAME}` in `{APP}/`
- **Sync app docs** (`/sync-docs app {APP}`) — update `{APP}/docs/` to reflect the new feature
- **Sync dev docs** (`/sync-docs dev`) — update `.claude/docs/`
- **Start a new change** (`/opsx-new`)
- **Done for now** — end the session

---

## Container Limitations

See [references/container-limitations.md](references/container-limitations.md) for the full table of what the container cannot do (and which host step handles each), container volume mappings, and the optional iptables network restriction to `api.anthropic.com`.

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

- **Orchestrator only — NO direct code changes** — the host (orchestrator) session MUST NOT edit, create, or delete code files directly. All code changes — including fixes to dependency bugs discovered during testing — must go through the container's apply→verify loop. If a discovered bug is in a file the container cannot access (e.g., a different app's directory not mounted in the container), use **AskUserQuestion** to ask the user how they want to handle it before proceeding.
- **Orchestrator only** — this skill does not implement, verify, archive, or test directly; it delegates to `opsx-apply`, `opsx-verify`, `opsx-archive`, and the test commands
- **Per-app isolation** — all git operations, quality checks, and openspec commands run from within `{APP}/`; never across app boundaries
- **Host handles git, GitHub, tests, and archive** — all git commits, GitHub API calls, browser tests, and archive happen on the host; the container only runs apply→verify
- **Max 5 apply→verify iterations** — CRITICAL issues stop the loop after all iterations are exhausted; warnings-only always proceeds
- **Max 3 test iterations** — test failures loop back into apply→verify a maximum of 3 times; on exhaustion the user chooses whether to archive anyway
- **Deferred tests run once** — multi-agent/broad tests deferred from the loop are run once at most; if they fail, exactly one more apply→verify cycle runs (no further test looping)
- **Container is stateless** — it writes file changes to the mounted app volume and result/failure files to `hydra/.claude/logs/` via the third volume mount (gitignored); the host reads those on exit
- **Pre-answer all interactive prompts** — apply, verify, and archive prompts are answered automatically (see prompt tables); the only interactive moments are closing the GitHub issue (Step 13c) and deferred tests (Step 10)
- **Archive runs exactly once** — deferred tests (Step 10) run before archive (Step 11); there is no re-archive
- **Single git commit** — all changes from all apply→verify cycles, all test fixes, and the archive are committed together in Step 12
- **Test scenario conversion is deferred** — archive's step 4.5 is skipped; apply-loop asks in Step 14
- **No force push, no destructive git ops** — same git safety rules as all opsx skills
- **Branch naming convention** — `feature/<issue-number>/<change-name>` to match the opsx-pipeline convention used across this workspace

> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`) when done.
