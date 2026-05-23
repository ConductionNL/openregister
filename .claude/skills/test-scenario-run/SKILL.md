---
name: test-scenario-run
description: Execute a specific test scenario against a live Nextcloud app using a browser agent
---

# Run Test Scenario

Executes one or more specific test scenarios from `{APP}/test-scenarios/` against the live Nextcloud environment. Uses a browser agent to follow the Given-When-Then steps and verify the acceptance criteria.

**Input**: Optional arguments after `/test-scenario-run`:
- No argument → list available scenarios and ask which to run
- Scenario ID → run that scenario directly (e.g., `TS-001`)
- App name + ID → run scenario from a specific app (e.g., `openregister TS-001`)
- `--all {APP}` → run all scenarios for an app
- `--tag {TAG}` → run all scenarios with a specific tag (e.g., `--tag smoke`)
- `--persona {PERSONA}` → run all scenarios relevant to a specific persona (e.g., `--persona mark-visser`)

---

## Step 1: Discover Scenarios

Scan for all scenario files across apps:
```bash
find . -path "*/test-scenarios/TS-*.md" | sort
```

If an app was specified, filter to `{APP}/test-scenarios/TS-*.md`.

Parse the frontmatter of each found file to build a list with: ID, title, app, priority, category, personas, status.

**If a specific scenario ID was provided** as argument: locate that file and skip to Step 3.

**If `--all`, `--tag`, or `--persona` was provided**: collect matching scenarios and skip to Step 3.
- `--tag {TAG}`: keep only scenarios whose `tags` list contains `{TAG}`
- `--persona {PERSONA}`: keep only scenarios whose `personas` list contains `{PERSONA}` (use the persona slug, e.g. `mark-visser`)

**Otherwise**: ask the user using AskUserQuestion:

**"Which test scenario do you want to run?"**

Display scenarios grouped by app, showing ID, title, priority, and category:
```
openregister/
  TS-001  [HIGH]  functional  — Create a new register
  TS-002  [MED]   api         — API returns paginated results
  TS-003  [HIGH]  security    — Unauthenticated access is blocked

opencatalogi/
  TS-001  [HIGH]  functional  — Publish a catalogue item
```

Allow multiple selection (comma-separated IDs). Store selected scenarios as `{SCENARIOS}`.

---

## Step 2: Environment Configuration

Ask using AskUserQuestion:

**"Which environment should the scenario(s) run against?"**
- **Local development** — `http://nextcloud.local`, admin/admin
- **Custom** — I'll provide the URL and credentials

For **Custom**, ask:
1. "Backend URL?"
2. "Username and password? (format: user:pass)"

Store as `{BACKEND}`, `{TEST_USER}`, `{TEST_PASS}`.

---

## Step 3: Read and Parse Scenarios

For each scenario in `{SCENARIOS}`, read its file and extract:
- `{SCENARIO_ID}`, `{SCENARIO_TITLE}`, `{APP}`, `{CATEGORY}`, `{PRIORITY}`
- `{PERSONAS}` — list of linked personas
- `{PRECONDITIONS}` — what must be true before starting
- `{SCENARIO_STEPS}` — Given/When/Then steps
- `{TEST_DATA}` — specific values to use
- `{ACCEPTANCE_CRITERIA}` — the checklist to verify

---

## Step 4: Select Agent Model

Ask using AskUserQuestion:

**"Which model should the test agent use?"**
- **Haiku (default)** — Fast, cost-efficient
- **Sonnet** — More capable for complex scenarios

Store as `{MODEL}`.

---

## Step 5: Launch Test Agent(s)

**Single scenario**: Launch 1 agent on `browser-1`.
**Multiple scenarios**: Launch agents in parallel (up to 5), assigning `browser-1` through `browser-5`.

For each scenario, launch a `general-purpose` agent with `model: "{MODEL}"` using this prompt:

---

### Agent Prompt Template

```
You are a test execution agent running scenario **{SCENARIO_ID}: {SCENARIO_TITLE}** for the **{APP}** Nextcloud app.

## Browser
Use `browser-1` tools (`mcp__browser-1__*`) for all interactions. (Replace 1 with assigned browser number.)

## Environment
- **Backend**: {BACKEND}
- **App URL**: {BACKEND}/index.php/apps/{APP}
- **Login**: {TEST_USER} / {TEST_PASS}

## Scenario Context

**Goal**: {USER_GOAL}
**Category**: {CATEGORY}  |  **Priority**: {PRIORITY}

## Step 1: Set Up (Preconditions)

Before running the scenario, verify and set up the preconditions:

{PRECONDITIONS as numbered list}

For each precondition:
- If it requires login: navigate to {BACKEND}/index.php/apps/{APP}, log in with {TEST_USER}/{TEST_PASS}
- If it requires existing data: create or verify it exists first
- If it requires a specific permission/role: verify the test user has it
- If a precondition cannot be met: mark the scenario as BLOCKED and explain why

Set viewport to 1920x1080 before any navigation:
```javascript
// via browser_resize: width=1920, height=1080
```

## Step 2: Execute the Scenario

Follow these steps exactly:

{SCENARIO_STEPS — formatted as numbered actions}

**Test data to use**:
{TEST_DATA}

For each step:
1. Execute the action as described
2. Take a screenshot: `{APP}/test-results/screenshots/test-scenario-run/{SCENARIO_ID}-step-{N}.png`
3. Check `browser_console_messages` for errors after every action
4. Note any unexpected behaviour

## Step 3: Verify Acceptance Criteria

After completing the steps, verify each acceptance criterion:

{ACCEPTANCE_CRITERIA as numbered list}

For each criterion:
- Mark as ✅ PASS if verified
- Mark as ❌ FAIL if not met — describe what you observed instead
- Mark as ⚠️ PARTIAL if partially met — describe what worked and what didn't
- Mark as ⛔ BLOCKED if you could not reach this point

## Step 4: Write Results

Write results to `{APP}/test-results/scenarios/{SCENARIO_ID}-results.md`:

```markdown
# Scenario Results: {SCENARIO_ID} — {SCENARIO_TITLE}

**Date**: {today's date}
**App**: {APP}
**Environment**: {BACKEND}
**Agent**: browser-{N}
**Overall**: PASS / FAIL / PARTIAL / BLOCKED

## Preconditions
| Precondition | Status | Notes |
|---|---|---|
| {precondition} | ✅ MET / ❌ NOT MET | {details} |

## Execution Summary
| Step | Action | Status | Notes |
|---|---|---|---|
| {N} | {action description} | ✅ / ❌ / ⚠️ | {observation} |

## Acceptance Criteria
| Criterion | Status | Evidence |
|---|---|---|
| {criterion} | ✅ PASS / ❌ FAIL / ⚠️ PARTIAL / ⛔ BLOCKED | {what was observed} |

## Console Errors
| Page/Step | Error | Severity |
|---|---|---|
| {page} | {error} | HIGH / MEDIUM / LOW |

## Screenshots
{list of screenshot filenames with descriptions}

## Notes
{any additional observations, edge cases found, or recommendations}
```
```

---

## Step 6: Synthesize Results (multiple scenarios)

If more than one scenario was run, after all agents complete, read all result files and produce a summary:

```markdown
# Test Scenario Run Summary

**Date**: {today}
**App(s)**: {apps}
**Scenarios run**: {count}
**Environment**: {BACKEND}

| Scenario | Title | Priority | Overall | PASS | FAIL | PARTIAL | BLOCKED |
|---|---|---|---|---|---|---|---|
| TS-001 | {title} | HIGH | ✅ PASS | 5 | 0 | 0 | 0 |
| TS-002 | {title} | MED | ❌ FAIL | 2 | 1 | 1 | 0 |

## Failed Criteria

| Scenario | Criterion | Observed |
|---|---|---|
| {id} | {criterion} | {what happened} |

## Console Errors (across all scenarios)

| Error | Scenarios | Severity |
|---|---|---|
| {error} | {scenario IDs} | HIGH / MEDIUM / LOW |
```

Write to `{APP}/test-results/scenarios/run-summary-{DATE}.md`.

---

## Step 7: Report to User

Display a concise summary:
- Scenarios run: {count}
- Overall: X passed, Y failed, Z partial, W blocked
- Any failed acceptance criteria (brief list)
- Any console errors found
- Links to result files
- Offer: "Run `/test-scenario-create` to add more scenarios, or `/test-counsel` for full persona testing"
