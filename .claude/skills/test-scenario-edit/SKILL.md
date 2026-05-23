---
name: test-scenario-edit
description: Edit an existing test scenario — update title, steps, personas, tags, priority, status, or any other field
---

# Edit Test Scenario

Opens an existing test scenario for editing. Shows the current values for every field and lets you update any of them — metadata (tags, priority, personas, status, test-commands) or content (title, goal, preconditions, steps, acceptance criteria, notes).

**Input**: Optional argument after `/test-scenario-edit`:
- No argument → list available scenarios and ask which to edit
- Scenario ID → open that scenario directly (e.g., `TS-001`)
- App name + ID → open scenario from a specific app (e.g., `openregister TS-001`)

---

## Step 1: Find the Scenario

If a scenario ID was provided, locate the file:
```bash
find . -path "*/test-scenarios/{ID}-*.md" | head -1
```

If no ID was provided, scan all scenarios:
```bash
find . -path "*/test-scenarios/TS-*.md" | sort
```

Parse the frontmatter of each file (id, title, app, priority, category, status). Ask the user using AskUserQuestion:

**"Which test scenario do you want to edit?"**

Display grouped by app:
```
openregister/
  TS-001  [HIGH]  functional  active   — Create a new register
  TS-002  [MED]   api         active   — API returns paginated results
  TS-003  [HIGH]  security    draft    — Unauthenticated access is blocked
```

Store the selected scenario file path as `{SCENARIO_FILE}`.

---

## Step 2: Read Current Values

Read the scenario file in full. Extract and store all current values:

**Frontmatter:**
- `{CURRENT_ID}`, `{CURRENT_TITLE}`, `{CURRENT_APP}`
- `{CURRENT_PRIORITY}` (high / medium / low)
- `{CURRENT_CATEGORY}` (functional / api / security / accessibility / performance / ux / integration)
- `{CURRENT_PERSONAS}` (list)
- `{CURRENT_TEST_COMMANDS}` (list)
- `{CURRENT_TAGS}` (list)
- `{CURRENT_STATUS}` (active / draft / deprecated)
- `{CURRENT_SPEC_REFS}` (list)

**Body:**
- `{CURRENT_GOAL}` (the **Goal** line)
- `{CURRENT_PRECONDITIONS}`
- `{CURRENT_STEPS}` (Given/When/Then block)
- `{CURRENT_TEST_DATA}`
- `{CURRENT_ACCEPTANCE_CRITERIA}`
- `{CURRENT_NOTES}`

---

## Step 3: Show Current State & Ask What to Change

Display a summary of the current scenario:

```
Scenario: {CURRENT_ID} — {CURRENT_TITLE}
App:      {CURRENT_APP}
Status:   {CURRENT_STATUS}
Priority: {CURRENT_PRIORITY}   Category: {CURRENT_CATEGORY}
Personas: {CURRENT_PERSONAS joined by ", "}
Commands: {CURRENT_TEST_COMMANDS joined by ", "}
Tags:     {CURRENT_TAGS joined by ", "}
Spec refs: {CURRENT_SPEC_REFS joined by ", ", or "none"}
```

Ask the user using AskUserQuestion:

**"What would you like to change?"**

- **Metadata only** — tags, priority, personas, status, test-commands, spec-refs
- **Content only** — title, goal, preconditions, steps, test data, acceptance criteria, notes
- **Both** — edit everything
- **Status only** — quickly mark as active / draft / deprecated
- **Tags only** — add or remove tags

Store choice as `{EDIT_SCOPE}`.

---

## Step 4: Edit Fields

Walk through only the fields relevant to `{EDIT_SCOPE}`. For each field, show the current value and ask for the new value. Skip fields the user doesn't want to change.

### Metadata fields

**Title** (if in scope):
> Current: `{CURRENT_TITLE}`
> New title? (Enter to keep)

**Status**:
> Current: `{CURRENT_STATUS}`
> New status? active / draft / deprecated (Enter to keep)

**Priority**:
> Current: `{CURRENT_PRIORITY}`
> New priority? high / medium / low (Enter to keep)

**Category**:
> Current: `{CURRENT_CATEGORY}`
> New category? functional / api / security / accessibility / performance / ux / integration (Enter to keep)

**Personas** — show current list, then show all available personas from `hydra/personas/`:

| Slug | Name | Focus |
|------|------|-------|
| `henk-bakker` | Henk Bakker | Elderly citizen — readability, Dutch UX |
| `fatima-el-amrani` | Fatima El-Amrani | Low-literate migrant — visual clarity, mobile |
| `sem-de-jong` | Sem de Jong | Young digital native — performance, keyboard |
| `noor-yilmaz` | Noor Yilmaz | Municipal CISO — security, RBAC |
| `annemarie-de-vries` | Annemarie de Vries | VNG architect — API standards, NLGov |
| `mark-visser` | Mark Visser | MKB vendor — business workflows |
| `priya-ganpat` | Priya Ganpat | ZZP developer — API quality, DX |
| `janwillem-van-der-berg` | Jan-Willem van der Berg | Small business owner — plain language |

> Current: `{CURRENT_PERSONAS}`
> New personas? (comma-separated slugs, or `+slug` to add, `-slug` to remove — Enter to keep)

Handle `+`/`-` syntax: add or remove individual personas without replacing the whole list.

**Test commands** — show current list:
> Current: `{CURRENT_TEST_COMMANDS}`
> New test-commands? (comma-separated, Enter to keep)

Valid values: `test-app`, `test-counsel`, `test-scenario-run`, `test-persona-{slug}`

**Tags** — show current list:
> Current: `{CURRENT_TAGS}`
> New tags? (comma-separated, or `+tag` to add, `-tag` to remove — Enter to keep)

Common tags: `smoke`, `regression`, `crud`, `nlgov`, `accessibility`, `security`, `performance`, `mobile`, `api`

**Spec refs**:
> Current: `{CURRENT_SPEC_REFS}`
> Spec refs? (comma-separated file paths, Enter to keep)

### Content fields

**Goal**:
> Current: `{CURRENT_GOAL}`
> New goal? (Enter to keep)

**Preconditions**:
> Current:
> {CURRENT_PRECONDITIONS}
> New preconditions? (Enter to keep — you can paste multi-line)

**Scenario steps** (Given/When/Then):
> Current:
> {CURRENT_STEPS}
> New steps? (Enter to keep — paste the full Given/When/Then block)

**Test data**:
> Current: `{CURRENT_TEST_DATA}`
> New test data? (Enter to keep)

**Acceptance criteria**:
> Current:
> {CURRENT_ACCEPTANCE_CRITERIA}
> New criteria? (Enter to keep — one per line, will be formatted as a checklist)

**Notes**:
> Current: `{CURRENT_NOTES}`
> New notes? (Enter to keep)

---

## Step 5: Regenerate Persona Notes

If the `personas` list changed, re-read each new persona card from `hydra/personas/{slug}.md` and regenerate the Persona Notes section in the body.

If personas didn't change, keep the existing Persona Notes as-is.

---

## Step 6: Check for Filename Change

If the title changed, ask:

**"The title changed — rename the file to match the new slug? (`{SCENARIO_ID}-{new-slug}.md`)"**
- **Yes** — rename the file (keeping the same ID prefix)
- **No** — keep the existing filename

---

## Step 7: Write the Updated File

Reconstruct the full scenario file with all updated values, preserving the structure and any fields that were not changed.

Write back to `{SCENARIO_FILE}` (or the renamed path if applicable).

---

## Step 8: Confirm

Display a diff-style summary of what changed:

```
Updated: {SCENARIO_FILE}

Changes:
  status:   draft → active
  priority: low → high
  tags:     + smoke, + regression
  personas: + noor-yilmaz
```

If the `test-commands` list changed, note:
> "This scenario will now be offered by: {new test-commands list}"

If `status` was set to `deprecated`, note:
> "This scenario will no longer appear in test runs. To restore it, set status back to `active`."
