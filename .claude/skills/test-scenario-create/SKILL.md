---
name: test-scenario-create
description: Create a reusable test scenario for a Nextcloud app — structured Gherkin-style, linked to personas and test commands
---

# Create Test Scenario

Guides the developer through creating a well-structured, reusable test scenario for a Nextcloud app. Scenarios are stored in `{APP}/test-scenarios/` and automatically picked up by `/test-app`, `/test-counsel`, and `/test-persona-*` commands.

> **What is a test scenario?**
> A test scenario is a high-level, user-centered description of one specific behaviour or flow that should be tested. It is broader than a test case (no exact click-by-click steps) but more concrete than a spec requirement — it answers "what journey should we verify, for whom, and under what conditions?" Each scenario can generate multiple test cases when executed.

**Scenario files** live at: `{APP}/test-scenarios/TS-NNN-slug.md`

---

## Step 1: Select App

If no app name was provided as argument, ask using AskUserQuestion:

**"Which app is this test scenario for?"**

List the apps found in the workspace (directories under `apps-extra/` that have an `openspec/` folder or `appinfo/` directory).

Store as `{APP}`.

---

## Step 2: Determine the Next Scenario ID

Scan `{APP}/test-scenarios/` for existing files matching `TS-NNN-*.md`. Find the highest number and increment by 1. If no scenarios exist yet, start at `TS-001`.

Store as `{SCENARIO_ID}`.

If the directory does not yet exist, note it will be created when the file is saved.

---

## Step 3: Title and Goal

Ask using AskUserQuestion:

**"Describe the scenario in one sentence — what user journey or behaviour should be tested?"**

Examples:
- "User creates a new register"
- "Admin invites a user to an organisation and the user can log in"
- "API returns paginated results with correct NLGov headers"

Store as `{SCENARIO_TITLE}`.

Then ask:

**"What is the user's goal in this scenario? (What are they trying to accomplish?)"**

Store as `{USER_GOAL}`.

---

## Step 4: Category

Ask using AskUserQuestion:

**"What category best describes this scenario?"**
- **functional** — Core feature works (CRUD, navigation, workflows)
- **api** — API endpoints, response format, error handling
- **security** — Permissions, auth boundaries, data isolation, RBAC
- **accessibility** — Keyboard navigation, contrast, screen reader, WCAG AA
- **performance** — Load times, pagination, large datasets
- **ux** — Usability, language clarity, empty states, feedback messages
- **integration** — Cross-app interaction, external API, webhook

Store as `{CATEGORY}`.

---

## Step 5: Priority

Ask using AskUserQuestion:

**"What is the priority of this scenario?"**
- **high** — Core flow; failure blocks the app's primary function (smoke test)
- **medium** — Important feature; regression risk on changes
- **low** — Edge case or nice-to-have

Store as `{PRIORITY}`.

---

## Step 6: Link to Personas

Show the available personas from `hydra/personas/` and their focus areas:

| Persona | File | Focus |
|---------|------|-------|
| Henk Bakker | `henk-bakker.md` | Elderly citizen — readability, Dutch UX |
| Fatima El-Amrani | `fatima-el-amrani.md` | Low-literate migrant — visual clarity, mobile |
| Sem de Jong | `sem-de-jong.md` | Young digital native — performance, keyboard, dark mode |
| Noor Yilmaz | `noor-yilmaz.md` | Municipal CISO — security, RBAC, audit trails |
| Annemarie de Vries | `annemarie-de-vries.md` | VNG architect — API standards, GEMMA, NLGov |
| Mark Visser | `mark-visser.md` | MKB vendor — business workflows, CRUD efficiency |
| Priya Ganpat | `priya-ganpat.md` | ZZP developer — API quality, DX, integration |
| Jan-Willem van der Berg | `janwillem-van-der-berg.md` | Small business owner — plain language, findability |

Suggest relevant personas based on the category:
- functional → Mark Visser, Sem de Jong
- api → Priya Ganpat, Annemarie de Vries
- security → Noor Yilmaz
- accessibility → Henk Bakker, Fatima El-Amrani
- ux → Henk Bakker, Jan-Willem van der Berg, Mark Visser
- performance → Sem de Jong, Priya Ganpat
- integration → Priya Ganpat, Annemarie de Vries

Ask using AskUserQuestion:

**"Which personas is this scenario relevant for? (Select all that apply, or 'all')"**

List the suggested ones first, marked with `(suggested)`. Allow the user to add others or accept the suggestions.

Store as `{PERSONAS}` (list of persona file slugs, e.g. `mark-visser`, `priya-ganpat`).

---

## Step 7: Link to Test Commands

Based on the category and personas, suggest which test commands should use this scenario:

| Command | When to suggest |
|---------|----------------|
| `/test-app` | Always (functional, ux, performance, api) |
| `/test-counsel` | When personas are selected |
| `/test-persona-{slug}` | For each selected persona |
| `/test-scenario-run` | Always — direct execution |

Ask using AskUserQuestion:

**"Which test commands should automatically include this scenario? (confirm or adjust)**"

Show the suggested list. Explain: "These commands will ask if you want to run this scenario when they are invoked for this app."

Store as `{TEST_COMMANDS}` (list).

---

## Step 8: Spec References

Check if `{APP}/openspec/specs/` exists and has spec files. If so, ask:

**"Are there any spec files this scenario validates? (Optional — press Enter to skip)"**

Examples: `openspec/specs/registers/spec.md`, `openspec/specs/api-patterns.md`

Store as `{SPEC_REFS}` (list, may be empty).

---

## Step 9: Tags

Suggest tags based on category and priority:

| Tag | When to suggest |
|-----|----------------|
| `smoke` | priority = high |
| `regression` | priority = high or medium |
| `crud` | category = functional |
| `nlgov` | category = api + Annemarie persona |
| `accessibility` | category = accessibility |
| `security` | category = security |
| `performance` | category = performance |
| `mobile` | Fatima persona |

Ask using AskUserQuestion:

**"Any additional tags? (suggested tags are pre-filled — press Enter to accept or modify)**"

Show the auto-suggested tags. Store confirmed tags as `{TAGS}`.

---

## Step 10: Write the Scenario

Now guide the user through writing the Gherkin-style scenario steps.

### 10a: Preconditions

Ask using AskUserQuestion:

**"What must be true BEFORE the scenario starts? (e.g., 'User is logged in', 'App is installed', 'At least one record exists')"**

Store as `{PRECONDITIONS}`.

### 10b: Given-When-Then Steps

Explain:
> Gherkin format: **Given** sets the context, **When** describes the action, **Then** describes the expected outcome. Use **And** to chain.

Ask using AskUserQuestion:

**"Describe the scenario steps:**
- GIVEN (context/starting state)
- WHEN (the action taken)
- THEN (the expected result)"**

Allow multi-line input. If the user provides free text, reformat it into clean Given/When/And/Then lines.

Store as `{SCENARIO_STEPS}`.

### 10c: Test Data

Ask using AskUserQuestion:

**"What test data is needed? (e.g., specific field values, file names, user roles — or press Enter to skip)**"

Store as `{TEST_DATA}`.

### 10d: Acceptance Criteria

Based on the THEN clauses, automatically generate an acceptance criteria checklist. Show it to the user and ask:

**"Review the acceptance criteria — anything to add or change?"**

Store as `{ACCEPTANCE_CRITERIA}`.

### 10e: Notes

Ask using AskUserQuestion:

**"Any additional notes? (edge cases, known quirks, related issues — or press Enter to skip)**"

Store as `{NOTES}`.

---

## Step 11: Generate Persona Notes

For each persona in `{PERSONAS}`, read their persona card from `hydra/personas/{slug}.md` and generate a one-line note describing why this scenario is relevant to them and what they would specifically look for.

Store as `{PERSONA_NOTES}`.

---

## Step 12: Save the Scenario File

Create the directory if it doesn't exist:
```bash
mkdir -p {APP}/test-scenarios
```

Generate a URL-safe slug from the title (lowercase, hyphens, no special chars). Store as `{SLUG}`.

Write the scenario to `{APP}/test-scenarios/{SCENARIO_ID}-{SLUG}.md`:

```markdown
---
id: {SCENARIO_ID}
title: "{SCENARIO_TITLE}"
app: {APP}
priority: {PRIORITY}
category: {CATEGORY}
personas:
{PERSONAS as YAML list}
test-commands:
{TEST_COMMANDS as YAML list}
tags:
{TAGS as YAML list}
status: active
created: {TODAY'S DATE}
spec-refs:
{SPEC_REFS as YAML list, or empty list []}
---

# {SCENARIO_ID}: {SCENARIO_TITLE}

**Goal**: {USER_GOAL}

## Preconditions

{PRECONDITIONS as bullet list}

## Scenario

{SCENARIO_STEPS — formatted as Given/When/And/Then block}

## Test Data

{TEST_DATA as table, or _(no specific test data required)_ if empty}

## Acceptance Criteria

{ACCEPTANCE_CRITERIA as checklist}

## Notes

{NOTES, or _(none)_ if empty}

## Persona Notes

{PERSONA_NOTES — one entry per persona as bullet list:
- **{Persona Name}** ({persona role}): {one-line relevance note}}
```

---

## Step 13: Confirm & Report

After saving, display:

```
✅ Test scenario saved: {APP}/test-scenarios/{SCENARIO_ID}-{SLUG}.md

Scenario: {SCENARIO_TITLE}
App:      {APP}
Priority: {PRIORITY}  |  Category: {CATEGORY}
Personas: {comma-separated persona names}

This scenario will be offered when running:
{TEST_COMMANDS — one per line}

Run it directly with: /test-scenario-run {SCENARIO_ID}
```

If this is the first scenario for the app, also say:
> "Test scenarios folder created at `{APP}/test-scenarios/`. Future `/test-app` and `/test-counsel` runs for this app will automatically discover scenarios here."
