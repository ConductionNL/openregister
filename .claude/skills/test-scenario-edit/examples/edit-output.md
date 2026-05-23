<!-- Example output — test-scenario-edit skill for OpenRegister -->

# Example Output: test-scenario-edit

## Step 3 — Current State Display

```
Scenario: TS-001 — Create a new register
App:      openregister
Status:   draft
Priority: medium   Category: functional
Personas: mark-visser
Commands: test-app, test-scenario-run
Tags:     crud, regression
Spec refs: none
```

User selected: **Both** (metadata + content)

---

## Step 4 — Edits Applied

- **Status:** draft → active
- **Priority:** medium → high
- **Tags:** +smoke (added)
- **Personas:** +janwillem-van-der-berg (added)
- **Acceptance criteria:** updated — added negative case "When no name is entered, submit is disabled"

No filename change needed (title unchanged).

---

## Step 8 — Confirmation

```
Updated: openregister/test-scenarios/TS-001-create-a-new-register.md

Changes:
  status:   draft → active
  priority: medium → high
  tags:     + smoke
  personas: + janwillem-van-der-berg
  criteria: + "When no name is entered, submit button is disabled"
```

This scenario will now be offered by: test-app, test-counsel, test-persona-janwillem, test-scenario-run
