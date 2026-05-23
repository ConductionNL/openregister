---
name: test-counsel
description: Test a project's features from 8 persona perspectives using browser, API, and documentation testing
---

# Test Counsel — Multi-Persona Feature Testing

Test a project's implemented features from 8 persona perspectives using browser interaction, API testing, and documentation review — all driven by the project's OpenSpec specifications.

**Input**: Optional argument after `/test-counsel`:
- No argument → ask which project to test
- Project name → test that project directly (e.g., `opencatalogi`, `openregister`)

**Available projects**: Any directory under apps-extra with an `openspec/` folder.

---

## Personas

The Test Counsel uses 8 personas representing the full spectrum of Dutch public sector users. Each persona card is stored in `hydra/personas/`:

| Persona | File | Testing Focus |
|---------|------|---------------|
| Henk Bakker | `henk-bakker.md` | Readability, text size, Dutch language, simple navigation, elderly UX |
| Fatima El-Amrani | `fatima-el-amrani.md` | Visual clarity, icon usage, mobile viewport, text density, literacy barriers |
| Sem de Jong | `sem-de-jong.md` | Performance, keyboard nav, dark mode, console errors, modern UX patterns |
| Noor Yilmaz | `noor-yilmaz.md` | Security controls, audit trails, RBAC, org isolation, data leaks, BIO2 |
| Annemarie de Vries | `annemarie-de-vries.md` | API standards, NLGov compliance, GEMMA mapping, OpenAPI spec, publiccode.yml |
| Mark Visser | `mark-visser.md` | Business workflows, CRUD efficiency, form clarity, status indicators, Dutch terms |
| Priya Ganpat | `priya-ganpat.md` | API quality via browser fetch(), DX, error responses, pagination, integration |
| Jan-Willem van der Berg | `janwillem-van-der-berg.md` | Plain language, jargon-free, findability, 3-click rule, contact info, help |

---

## Steps

### Step -1: Environment Configuration

Ask the user about the target environment using AskUserQuestion:

**"Which environment do you want to test against?"**
- **Local development** — Backend: nextcloud.local, Frontend: localhost:3000 (if separate UI), Admin: admin/admin
- **Custom environment** — I'll provide URLs and credentials

If **Custom**, ask follow-up questions one at a time:
1. "What is the backend URL?"
2. "What is the frontend URL? (or same as backend if no separate UI)"
3. "What are the test user credentials? (format: username:password)"

Store as `{BACKEND}`, `{FRONTEND}`, `{TEST_USER}`, `{TEST_PASS}`.

For **Local development**, use:
- `{BACKEND}` = `http://nextcloud.local`
- `{FRONTEND}` = `http://nextcloud.local` (or `http://localhost:3000` if project has separate UI)
- `{TEST_USER}` = `admin`
- `{TEST_PASS}` = `admin`

### Step 0: Determine the Project

If no project was provided as argument, use AskUserQuestion to ask:

**"Which project would you like the Test Counsel to test?"**

List the available projects by checking which directories have `openspec/` folders.

Store the chosen project as `{PROJECT}`.

### Step 1: Read the Project's Specs and Understand What to Test

Read the following files:

1. `{PROJECT}/project.md` — Project context, URLs, architecture
2. `{PROJECT}/openspec/specs/` — All spec files (what was specified)
3. `{PROJECT}/openspec/changes/` — Active changes (recently added features)
4. `openspec/specs/` — Shared specs (api-patterns, nl-design, nextcloud-app)

Build a test plan:
- What features exist and should be testable?
- What URLs/pages should be visited?
- What API endpoints should be tested?
- What documentation should exist?

### Step 1.5a: Load Test Scenarios (optional)

Check whether the project has saved test scenarios:
```bash
ls {PROJECT}/test-scenarios/TS-*.md 2>/dev/null
```

If scenario files exist, parse their frontmatter. Filter to those with `status: active` and `test-commands` containing `test-counsel`.

Group them by persona relevance using the `personas` frontmatter field:

```
Found {N} test scenario(s) for {PROJECT}:

Relevant to all personas:
  TS-001  [HIGH]  functional  — Create a new register

Relevant to specific personas:
  TS-002  [MED]   api         — API returns paginated results   → Priya Ganpat, Annemarie de Vries
  TS-003  [HIGH]  security    — Unauthenticated access blocked  → Noor Yilmaz
  TS-004  [LOW]   accessibility — Form labels are readable      → Henk Bakker, Fatima El-Amrani
```

Ask the user using AskUserQuestion:

**"Test scenarios exist for this project. Include them in this test run?"**
- **Yes, include all** — each persona agent receives the scenarios relevant to their persona (matched by persona slug in frontmatter), plus any scenario with no specific persona
- **Yes, let me choose** — show the list and let the user select which to include
- **No, skip scenarios** — proceed with standard testing only

Store `{INCLUDED_SCENARIOS}` — a mapping of persona slug → list of relevant scenario objects (id, title, steps, preconditions, acceptance criteria).

Each persona sub-agent will receive only the scenarios matching their persona slug (or all scenarios if the user chose "include all" and no persona filter is set).

**If no scenarios exist**: proceed silently. Note at the end: "No test scenarios defined yet. Create them with `/test-scenario-create`."

---

### Step 1.5: Select Agent Model

Ask the user using AskUserQuestion:

**"Which model should the persona agents use?"**

| Model | Speed | Quota | Best for |
|---|---|---|---|
| **Haiku** | Fastest | Low | Parallel runs — broad coverage, efficient |
| **Sonnet** | Balanced | Moderate | Better reasoning, more nuanced findings |
| **Opus** | Slowest | High | Deepest analysis — for critical or final runs |

- **Haiku (default)** — Recommended for parallel runs. Fast and quota-efficient. Its 200k context window is smaller than Sonnet/Opus (both 1M) — for browser-heavy runs with many snapshots, consider Sonnet.
- **Sonnet** — Better reasoning depth for more nuanced findings. Uses more quota than Haiku across 8 parallel agents.
- **Opus** — Highest quality analysis. With 8 agents running in parallel this uses substantial quota — best reserved for final pre-release testing or targeted critical reviews.

Store as `{MODEL}`:
- Haiku → `"haiku"`
- Sonnet → `"sonnet"`
- Opus → `"opus"`

### Step 2: Launch Persona Test Agents in Parallel

Launch 8 Task agents in parallel (all in a single message), one per persona. Each agent tests the live application from their persona's perspective. Use `subagent_type: "general-purpose"` and `model: "{MODEL}"` (from Step 1.5).

**Browser assignment** — each agent gets its own browser to avoid conflicts:

| Agent | Persona | Browser |
|-------|---------|---------|
| 1 | Henk Bakker | `browser-2` |
| 2 | Fatima El-Amrani | `browser-3` |
| 3 | Sem de Jong | `browser-4` |
| 4 | Noor Yilmaz | `browser-5` |
| 5 | Annemarie de Vries | `browser-7` |
| 6 | Mark Visser | `browser-1` |
| 7 | Priya Ganpat | `browser-2` (sequential after Henk) |
| 8 | Jan-Willem van der Berg | `browser-3` (sequential after Fatima) |

**Note**: With 7 browsers and 8 agents, launch the first 6 in parallel, then the remaining 2 after the first batch completes. Or launch all 8 and let 2 share browsers sequentially.

**Sub-agent prompt template**: Read the full template at [templates/sub-agent-prompt-template.md](templates/sub-agent-prompt-template.md).

Replace all `{VARIABLES}` before sending: `{PROJECT}`, `{PERSONA_NAME}`, `{PERSONA_FILE}`, `{PERSONA_SLUG}`, `{BACKEND}`, `{FRONTEND}`, `{TEST_USER}`, `{TEST_PASS}`, `{N}` (browser number), `{PERSONA_TESTING_FOCUS}` (pick the row matching the persona from the focus table inside the template), and `{INCLUDED_SCENARIOS}` (scenarios relevant to the persona, if any — otherwise omit the scenarios section).

### Step 3: Synthesize Test Results

After all agents complete, read their reports and create a synthesized Test Counsel report.

Read the synthesis report template at [templates/synthesis-report-template.md](templates/synthesis-report-template.md) and write the completed report to `{PROJECT}/test-results/test-counsel-report.md`.

### Step 4: Report to User

Display a concise summary:
- Total features tested across all personas
- Overall pass/fail rates per persona
- Top 5 critical issues
- Any spec features that are not yet implemented
- Link to the full report: `{PROJECT}/test-results/test-counsel-report.md`
- Offer to create OpenSpec changes for any gaps found

---

## Capture Learnings

After testing completes, review what happened and append any new observations to [learnings.md](learnings.md):

- **Patterns That Work** — multi-persona approaches that found meaningful cross-cutting issues
- **Mistakes to Avoid** — false consensus, persona overlap, or synthesis errors
- **Domain Knowledge** — facts about cross-persona testing patterns or Dutch government accessibility
- **Open Questions** — unresolved testing challenges

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.

---

## Returning to caller

After generating the report and summary, output a structured result line and return control:

```
COUNSEL_TEST_RESULT: PASS | FAIL  CRITICAL_COUNT: <n>  SUMMARY: <one-line summary>
```

- **PASS** = no CRITICAL issues found across all personas
- **FAIL** = any CRITICAL issues found

**If invoked from `/opsx-apply-loop`**: your work is complete after outputting the result line. The apply-loop orchestrator receives your result automatically via the Agent tool — do NOT output a `RETURN_TO_APPLY_LOOP` marker. Do NOT offer to create OpenSpec changes, do NOT ask what to do next.
