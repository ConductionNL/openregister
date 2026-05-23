---
name: opsx-apply
description: Implement tasks from an OpenSpec change (Experimental)
metadata:
  category: Workflow
  tags: [workflow, artifacts, experimental]
---

**Check the active model** from your system context (it appears as "You are powered by the model named…").

- **On Haiku**: stop immediately:
  > "This command requires Sonnet or Opus — implementing tasks from OpenSpec changes needs stronger reasoning than Haiku can reliably provide. Please switch to Sonnet (`/model sonnet`) or Opus (`/model opus`) and re-run."
- **On Sonnet or Opus**: proceed normally.

---

## Headless Mode Contract (Hydra CI)

This skill is also used by the Hydra builder container (`images/builder/entrypoint.sh`) in headless CI mode. When the prompt explicitly says **"HEADLESS"** or sets `HYDRA_HEADLESS=1`, apply these overrides:

| Step | Interactive behavior | Headless override |
|------|----------------------|-------------------|
| Model gate (top of file) | Block Haiku | **Ignore** — proceed on any model |
| Step 6 (confirm before start) | `AskUserQuestion` prompt | **Skip** — auto-proceed with all pending tasks |
| Step 7 pause points | Pause and ask user | **Skip asking** — implement best effort; on true blocker, exit 1 with clear error |
| Step 10 ("what's next") | `AskUserQuestion` menu | **Skip entirely** — orchestrator schedules next phase |
| Capture Learnings | Append to `learnings.md` | **Skip** — container filesystem is disposable |

All other steps (load ADRs, read context files, implement tasks, **update tasks.md checkboxes**, **update GitHub issue checkboxes**, **update plan.json**, **post pipeline progress comment**, run quality checks) are MANDATORY in headless mode — these are the steps the CI pipeline depends on.

**If you add a new interactive pause to this skill, add it to this table and to the override list in `images/builder/entrypoint.sh` (build-mode prompt).** Drift between the two breaks Hydra silently.

---

Implement tasks from an OpenSpec change.

**Input**: Optionally specify a change name (e.g., `/opsx-apply add-auth`). If omitted, check if it can be inferred from conversation context. If vague or ambiguous you MUST prompt for available changes.

**Steps**

1. **Select the change**

   If a name is provided, use it. Otherwise:
   - Infer from conversation context if the user mentioned a change
   - Auto-select if only one active change exists
   - If ambiguous, run `openspec list --json` to get available changes and use the **AskUserQuestion tool** to let the user select

   Always announce: "Using change: <name>" and how to override (e.g., `/opsx-apply <other>`).

2. **Check status to understand the schema**
   ```bash
   openspec status --change "<name>" --json
   ```
   Parse the JSON to understand:
   - `schemaName`: The workflow being used (e.g., "spec-driven")
   - Which artifact contains the tasks (typically "tasks" for spec-driven, check status for others)

3. **Get apply instructions**

   ```bash
   openspec instructions apply --change "<name>" --json
   ```

   This returns:
   - Context file paths (varies by schema)
   - Progress (total, complete, remaining)
   - Task list with status
   - Dynamic instruction based on current state

   **Handle states:**
   - If `state: "blocked"` (missing artifacts): show message, suggest using `/opsx-continue`
   - If `state: "all_done"`: congratulate, suggest archive
   - Otherwise: proceed to implementation

4. **Load architectural context**

   Before reading change-specific files, load ALL applicable ADRs. These are hard constraints on implementation — violating an ADR is a bug.

   | Location | What | Priority |
   |----------|------|----------|
   | `openspec/changes/<name>/context-brief.md` | Specter intelligence brief — features, user stories, stakeholders, schemas, standards. Contains market research data that informed the spec. | Load if present — provides domain context for implementation decisions |
   | `../../../openspec/architecture/adr-*.md` (relative to skill base dir) | Company-wide ADRs (17 Conduction-wide decisions: data layer, API patterns, frontend, security, i18n, testing, etc.) — these live in the Hydra repo that contains this skill | **Always load — these are non-negotiable** |
   | `openspec/architecture/adr-*.md` | Repo-specific ADRs (app's data model, workflows, standards compliance, security model) | Always load if present |
   | `docs/ARCHITECTURE.md` | App-specific technology decisions and component structure | Load if present |

   Read these silently. Key ADRs to enforce during implementation:
   - **ADR-001 (data layer)**: All data via OpenRegister, no custom DB tables
   - **ADR-002 (API)**: REST-API Design Rules, HAL+JSON responses
   - **ADR-003 (backend)**: PHP AppFramework patterns, service layer
   - **ADR-004 (frontend)**: Vue 2.7 + Pinia, @nextcloud/vue components
   - **ADR-005 (security)**: Nextcloud auth, RBAC, no direct SQL
   - **ADR-007 (i18n)**: Dutch + English minimum, t() for all strings
   - **ADR-001 (data layer)**: @self envelope pattern for seed data

5. **Read context files**

   Read the files listed in `contextFiles` from the apply instructions output.
   The files depend on the schema being used:
   - **spec-driven**: proposal, specs, design, tasks
   - Other schemas: follow the contextFiles from CLI output

   **Additionally, load optional artifacts if present:**
   - `openspec/changes/<name>/contract.md` — if it exists, treat it as the authoritative interface definition. Do not deviate from its declared endpoints, schemas, or error codes during implementation.
   - `openspec/changes/<name>/test-plan.md` — if it exists, use it to guide verification steps for each task. Each TC's acceptance criteria tell you how to verify the task is done.

6. **Show current progress and confirm**

   Display:
   - Schema being used
   - Progress: "N/M tasks complete"
   - Remaining tasks overview
   - Dynamic instruction from CLI

   Then use **AskUserQuestion** to ask:

   > "Ready to implement <N> remaining tasks for `<change-name>`?"

   Options:
   - **Start implementing** — proceed through all pending tasks in order
   - **Show me the full task list first** — display all tasks with titles and acceptance criteria, then ask again
   - **Start from a specific task** — ask "Which task number?" and skip to that task
   - **Cancel** — end the session without making changes

7. **Implement tasks (loop until done or blocked)**

   For each pending task:
   - Show which task is being worked on
   - Make the code changes required
   - Keep changes minimal and focused
   - **Write tests for every new PHP service/controller** — PHPUnit test in `tests/Unit/` or `tests/unit/` with at least 3 test methods covering the happy path, error handling, and edge cases
   - **Write tests for every new Vue component** — if the project has a test framework (Jest/Vitest), create a basic mount + render test
   - **Update documentation** — add/update the feature description in the project's README.md or docs/ folder. At minimum, document new API endpoints (method, path, request/response) and new admin settings
   - Mark task complete in the tasks file: `- [ ]` → `- [x]`
   - **Update GitHub issue checkboxes** (if `plan.json` exists):
     - Read `openspec/changes/<name>/plan.json`, find the `tracking_issue` number
     1. **Check off this task and ALL its sub-checkboxes in the issue body**:
        - Fetch the issue body once
        - Find the parent task line by matching the task title (e.g., `- [ ] **1.1 Task title**`)
        - Change that line from `- [ ]` to `- [x]`
        - Scan every line immediately following the parent — for each line that starts with `  - [ ]` (2-space indent), change it to `  - [x]`
        - Stop scanning when you hit a line that is NOT an indented sub-checkbox (blank line, new parent checkbox, section header, etc.)
        - **MCP (preferred):** `get_issue` → `{owner, repo, issue_number: <tracking_issue>}` → apply the above changes to the body → `update_issue` → `{owner, repo, issue_number: <tracking_issue>, body: <updated_body>}`
        - **CLI (fallback):** `gh issue view <tracking_issue> --repo <repo> --json body --jq '.body'` → apply the above changes → `gh issue edit <tracking_issue> --repo <repo> --body "<updated_body>"`
     - Update `plan.json`: set `"status": "done"` for that task
     - **Do NOT close the issue** — the issue will be closed when the PR is merged or during archive
   - Continue to next task

   **Seed data requirement (ADR-001):**
   - When implementing tasks that introduce or modify OpenRegister schemas, MUST also generate seed data entries in `lib/Settings/{app}_register.json`
   - Use the Seed Data section from `design.md` as the source — it defines objects, field values, and related items
   - Seed objects MUST use the `@self` envelope pattern (`register`, `schema`, `slug`) per ADR-001
   - Use general organization data that feels natural for a municipality, consultancy, or travel agency
   - Include 3-5 objects per schema with varied, realistic field values
   - If `design.md` has no Seed Data section, flag this and suggest adding one before continuing

   **Pause if:**
   - Task is unclear → ask for clarification
   - Implementation reveals a design issue → suggest updating artifacts
   - Error or blocker encountered → report and wait for guidance
   - User interrupts

8. **On completion or pause, show status and update progress comment**

   Display:
   - Tasks completed this session
   - Overall progress: "N/M tasks complete"
   - If paused: explain why and wait for guidance

   **Pipeline progress comment (if plan.json exists with tracking_issue):**

   Post or update a **single progress comment** on the GitHub issue. Search existing comments for one starting with `## Pipeline Progress` — if found, update it via PATCH; if not, create a new one. This keeps progress in one place instead of spamming multiple comments.

   Format:
   ```markdown
   ## Pipeline Progress

   | Stage | Status | Details |
   |-------|--------|---------|
   | Implementation | ✓ N/M tasks | Completed tasks X, Y, Z |
   | Quality Checks | pending | |
   | Verification | pending | |
   | Archive | pending | |

   *Updated: YYYY-MM-DD HH:MM UTC*
   ```

   - **MCP (preferred):** Use `list_issue_comments` to find existing progress comment, then `update_issue_comment` or `add_issue_comment`
   - **CLI (fallback):** `gh api repos/{owner}/{repo}/issues/{n}/comments` to search, then PATCH or POST

   **If all tasks done:** proceed to Step 8 (quality checks). Update the progress comment with Implementation = "✓ Complete" and Quality Checks = "running...".

   **If plan.json exists and all tasks are now done:** also add a separate brief comment:
   - **MCP (preferred):** GitHub MCP `add_issue_comment` → `{owner, repo, issue_number: <tracking_issue>, body: "✓ All tasks implemented. Running quality checks."}`
   - **CLI (fallback):** `gh issue comment <tracking_issue> --repo <repo> --body "✓ All tasks implemented. Running quality checks."`

9. **Run code quality checks**

   After all tasks are complete, run the full quality suite from the project directory.

   **PHP quality** (if the project has a `composer.json` with quality scripts):
   ```bash
   cd <project-dir> && composer check:strict 2>&1
   ```
   This runs: lint + named-args check + phpcs + phpmd + psalm + phpstan + unit tests.

   If `check:strict` is not available, fall back to running individually:
   ```bash
   composer phpcs 2>&1
   composer phpmd 2>&1
   composer psalm 2>&1
   ```

   **Frontend quality** (if the project has a `package.json` with lint scripts):
   ```bash
   cd <project-dir> && npm run lint 2>&1
   npm run stylelint 2>&1
   ```

   **Handle failures:**
   - Parse the output to identify specific errors
   - For auto-fixable issues, run the fixer first:
     - `composer phpcs:fix` (PHPCBF auto-fixes ~60% of PHPCS issues)
     - `npm run lint -- --fix` (ESLint auto-fix)
   - For remaining issues, fix them manually in the code
   - Re-run the quality checks to confirm all issues are resolved
   - Maximum 3 fix cycles — if issues persist after 3 rounds, report remaining issues and continue

   **Show quality results:**
   ```
   ## Quality Checks

   | Tool | Status |
   |------|--------|
   | PHPCS | ✓ Pass (or X errors fixed) |
   | PHPMD | ✓ Pass |
   | Psalm | ✓ Pass |
   | PHPStan | ✓ Pass |
   | ESLint | ✓ Pass |
   | Stylelint | ✓ Pass |
   | Unit Tests | ✓ Pass (N tests) |
   ```

10. **Ask what's next**

   After quality checks pass (or remaining issues are reported), show the completion summary, then use **AskUserQuestion** to ask:

   > "Implementation complete! What would you like to do next?"

   Options:
   - **Verify implementation** (`/opsx-verify`) — recommended: check the code matches specs and run API/browser tests
   - **Get a code review first** (`/team-reviewer`) — have a reviewer look at the code before verifying
   - **Sync delta specs** (`/opsx-sync`) — sync this change's specs to main specs first
   - **Archive directly** (`/opsx-archive`) — skip verification if already reviewed externally
   - **Done for now** — end the session

**Output During Implementation**

```
## Implementing: <change-name> (schema: <schema-name>)

Working on task 3/7: <task description>
[...implementation happening...]
✓ Task complete

Working on task 4/7: <task description>
[...implementation happening...]
✓ Task complete
```

**Output On Completion**

```
## Implementation Complete

**Change:** <change-name>
**Schema:** <schema-name>
**Progress:** 7/7 tasks complete ✓

### Completed This Session
- [x] Task 1
- [x] Task 2
...

### Quality Checks
| Tool | Status |
|------|--------|
| PHPCS | ✓ Pass |
| PHPMD | ✓ Pass |
| Psalm | ✓ Pass |
| ESLint | ✓ Pass |
| Unit Tests | ✓ 42 tests passed |

**What's Next**
Recommended: `/opsx-verify` | Optional: `/team-reviewer`, `/opsx-sync` | Alternative: `/opsx-archive`
```

**Output On Pause (Issue Encountered)**

```
## Implementation Paused

**Change:** <change-name>
**Schema:** <schema-name>
**Progress:** 4/7 tasks complete

### Issue Encountered
<description of the issue>

**Options:**
1. <option 1>
2. <option 2>
3. Other approach

What would you like to do?
```

## Capture Learnings

After completing tasks, review what happened and append any new observations to [learnings.md](learnings.md):

- **Patterns That Work** — implementation approaches that consistently navigate OpenSpec tasks well
- **Mistakes to Avoid** — errors, wrong assumptions, or approaches that caused implementation problems
- **Domain Knowledge** — facts about OpenSpec workflows or project-specific implementation patterns
- **Open Questions** — unresolved challenges or edge cases for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.

---

**Guardrails**
- Keep going through tasks until done or blocked
- Always read context files before starting (from the apply instructions output)
- If task is ambiguous, pause and ask before implementing
- If implementation reveals issues, pause and suggest artifact updates
- Keep code changes minimal and scoped to each task
- Update task checkbox immediately after completing each task
- Pause on errors, blockers, or unclear requirements - don't guess
- Use contextFiles from CLI output, don't assume specific file names

**Fluid Workflow Integration**

This skill supports the "actions on a change" model:

- **Can be invoked anytime**: Before all artifacts are done (if tasks exist), after partial implementation, interleaved with other actions
- **Allows artifact updates**: If implementation reveals design issues, suggest updating artifacts - not phase-locked, work fluidly

> 💡 If you switched models to run this command, don't forget to switch back to your preferred model with `/model <name>` (e.g. `/model default` or `/model sonnet`) when done.
