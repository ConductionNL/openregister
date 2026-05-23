---
name: test-app
description: Run automated browser tests for a Nextcloud app — single agent or multi-perspective parallel testing
---

# Test App — Automated Browser Testing

Run automated browser tests for any of the Nextcloud apps in this workspace. Tests every page, button, and form by exploring the live application, guided by existing documentation and specs.

> **Experimental**: This is agentic browser testing. Agents navigate the real application using Playwright MCP browsers. Results may include false positives (elements not found due to timing) or false negatives (bugs missed due to exploration order). Always verify critical findings manually.

**Input**: Optional argument after `/test-app`:
- No argument → ask which app to test
- App name → test that app directly (e.g., `procest`, `pipelinq`, `nldesign`, `mydash`)

---

## Step 0: Select App

If no app name was provided, ask the user using AskUserQuestion:

**"Which app do you want to test?"**
- **procest** — Case management (7 feature groups)
- **pipelinq** — CRM / Pipeline management (8 feature groups)
- **nldesign** — NL Design System theming (admin settings only)
- **mydash** — Customizable dashboard (widgets, tiles, templates)

Store the selected app as `{APP}`.

---

## Step 1: Select Testing Mode

Ask the user using AskUserQuestion:

**"Which testing mode?"**
- **Quick (single agent)** — One agent walks through the entire app, testing every page and interaction. Fastest, good for smoke testing.
- **Full (multi-perspective)** — 6 agents test in parallel, each with a different focus (functional, UX, performance, accessibility, security, API). More thorough, takes longer.

Store the selected mode as `{MODE}`.

---

## Step 2: Environment Configuration

Use the local development environment by default:
- `{BACKEND}` = `http://nextcloud.local`
- `{APP_URL}` = `http://nextcloud.local/index.php/apps/{APP}`
- `{ADMIN_SETTINGS_URL}` = `http://nextcloud.local/settings/admin/{APP}` (except nldesign which uses `/settings/admin/theming`)
- `{USER}` = `admin`
- `{PASS}` = `admin`

(`nextcloud.local` is the canonical URL set in Nextcloud's `overwrite.cli.url`. If your dev stack uses a different hostname, override `{BACKEND}` accordingly.)

---

## Step 2.5: Load Test Scenarios (optional)

Check whether the app has any saved test scenarios:
```bash
ls {APP}/test-scenarios/TS-*.md 2>/dev/null
```

If scenario files exist, parse their frontmatter and list them — showing only those with `status: active` and `test-commands` containing `test-app`:

```
Found {N} test scenario(s) for {APP}:
  TS-001  [HIGH]  functional  — Create a new register
  TS-003  [HIGH]  security    — Unauthenticated access is blocked
```

Ask the user using AskUserQuestion:

**"Test scenarios exist for this app. Include them in this test run?"**
- **Yes, include all** — agents will execute every listed scenario's Given-When-Then steps as part of their testing, in addition to their normal exploration
- **Yes, let me choose** — show the list and let the user pick which to include
- **No, skip scenarios** — proceed with standard testing only

Store the selected scenarios (if any) as `{INCLUDED_SCENARIOS}`. Pass their IDs and steps to the relevant sub-agents in Step 5.

**If no scenarios exist**: proceed silently to Step 3. Mention at the end: "No test scenarios defined yet. Create them with `/test-scenario-create`."

---

## Step 3: Read App Documentation

Before launching agents, read the app's documentation to build the test scope. Read these files in order:

1. **`{APP}/DEVELOPMENT.md`** — How to access the app
2. **`{APP}/docs/features/README.md`** — Feature index (which feature files exist)
3. **All files in `{APP}/docs/features/`** — Detailed feature descriptions
4. **`{APP}/openspec/ROADMAP.md`** (if exists) — What's NOT yet implemented (agents should skip these)

This documentation tells agents what the app does, what pages exist, and what to expect. Agents should NOT test features listed as "V1 / Roadmap" — those are planned but not yet built.

---

## Step 4: Prepare Output Directory

```bash
mkdir -p {APP}/test-results/screenshots/test-app
```

---

## Step 4.5: Select Agent Model

Ask the user using AskUserQuestion:

**"Which model should the test agents use?"**

| Model | Speed | Quota | Best for |
|---|---|---|---|
| **Haiku** | Fastest | Low | Parallel runs — broad coverage, efficient |
| **Sonnet** | Balanced | Moderate | Better reasoning, more nuanced findings |
| **Opus** | Slowest | High | Deepest analysis — for critical or final runs |

- **Haiku (default)** — Recommended for parallel runs. Fast and quota-efficient. Its 200k context window is smaller than Sonnet/Opus (both 1M) — for browser-heavy runs with many snapshots, consider Sonnet.
- **Sonnet** — Better reasoning depth for more nuanced findings. Uses more quota than Haiku across 6 parallel agents.
- **Opus** — Highest quality analysis. With 6 agents running in parallel this uses substantial quota — best reserved for final pre-release testing or targeted critical reviews.

Store as `{MODEL}`:
- Haiku → `"haiku"`
- Sonnet → `"sonnet"`
- Opus → `"opus"`

---

## Step 5: Launch Agent(s)

### Quick Mode (Single Agent)

Launch 1 Task agent with `subagent_type: "general-purpose"`.

**Browser**: `browser-1` (headless)

Use the prompt template below with `{PERSPECTIVE}` set to "comprehensive" and `{PERSPECTIVE_INSTRUCTIONS}` set to the Quick Mode Focus section.

### Full Mode (Multi-Perspective)

Launch 6 Task agents **in parallel** (all in one message), each with a different perspective. All use `subagent_type: "general-purpose"` and `model: "{MODEL}"` (from Step 4.5).

| Agent | Perspective | Browser | Focus |
|-------|-------------|---------|-------|
| 1 | Functional | `browser-2` | Does every feature work? CRUD, navigation, forms, buttons |
| 2 | UX | `browser-3` | Usability: labels, empty states, loading indicators, feedback messages |
| 3 | Performance | `browser-4` | API response times, rendering speed, network requests |
| 4 | Accessibility | `browser-5` | Keyboard navigation, contrast, focus indicators, screen reader hints |
| 5 | Security | `browser-7` | Auth boundaries, URL manipulation, console errors, XSS vectors |
| 6 | API | `browser-1` | All API endpoints via fetch(), error responses, data integrity |

---

## Sub-Agent Prompt Template

Read the full prompt template at [templates/agent-prompt-template.md](templates/agent-prompt-template.md).

Replace all `{VARIABLES}` in the template before sending to the sub-agent:
- `{APP}` — app name
- `{PERSPECTIVE}` — agent's perspective (e.g., "functional", "accessibility")
- `{APP_URL}`, `{ADMIN_SETTINGS_URL}`, `{BACKEND}`, `{USER}`, `{PASS}` — environment values
- `{N}` — browser number (2-7)
- `{PERSPECTIVE_INSTRUCTIONS}` — paste the matching block from [templates/perspective-instructions.md](templates/perspective-instructions.md)
- `{INCLUDED_SCENARIOS}` — list of TS-NNN IDs and steps, or empty string
- `{MODEL}` — model name from Step 4.5

### Perspective-Specific Instructions

Read [templates/perspective-instructions.md](templates/perspective-instructions.md) for the full instruction block for each perspective. Copy the matching block as `{PERSPECTIVE_INSTRUCTIONS}` into the sub-agent prompt template.

---

## Step 6: Generate Summary Report

After all agents complete, read all result files from `{APP}/test-results/` and generate a summary.

Read the summary report template at [templates/summary-report-template.md](templates/summary-report-template.md) and write the completed summary to `{APP}/test-results/README.md`.

---

## Step 7: Report to User

Display a concise summary:
- Total features tested
- PASS/FAIL/PARTIAL/CANNOT_TEST counts
- Top critical findings (FAILs)
- Link to full report: `{APP}/test-results/README.md`

---

## Capture Learnings

After testing completes, review what happened and append any new observations to [learnings.md](learnings.md):

- **Patterns That Work** — test approaches that found real bugs (e.g., "testing navigation before login catches auth redirect issues")
- **Mistakes to Avoid** — false positives or testing errors (e.g., "element not found due to loading delay, not actual bug")
- **Domain Knowledge** — facts about the app's behavior discovered during testing
- **Open Questions** — unresolved testing challenges

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.

---

## Returning to caller

After generating the report and summary, output a structured result line and return control:

```
APP_TEST_RESULT: PASS | FAIL  CRITICAL_COUNT: <n>  SUMMARY: <one-line summary>
```

- **PASS** = no FAIL-level findings
- **FAIL** = any FAIL-level findings

**If invoked from `/opsx-apply-loop`**: your work is complete after outputting the result line. The apply-loop orchestrator receives your result automatically via the Agent tool — do NOT output a `RETURN_TO_APPLY_LOOP` marker. Do NOT start new work, do NOT suggest fixes, do NOT ask what to do next.
