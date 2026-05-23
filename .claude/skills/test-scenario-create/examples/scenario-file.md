<!-- Example output — the scenario file created by test-scenario-create for OpenRegister -->

# Example: Completed Scenario File

The file below is written to `openregister/test-scenarios/TS-001-create-a-new-register.md`
after running `/test-scenario-create` and completing all prompts.

---

```markdown
---
id: TS-001
title: "Create a new register and verify it appears in the list"
app: openregister
priority: high
category: functional
personas:
  - mark-visser
  - janwillem-van-der-berg
test-commands:
  - test-app
  - test-counsel
  - test-persona-mark
  - test-persona-janwillem
  - test-scenario-run
tags:
  - smoke
  - regression
  - crud
status: active
created: 2026-04-10
spec-refs:
  - openspec/specs/registers/spec.md
---

# TS-001: Create a new register and verify it appears in the list

**Goal**: The user can create a new register by providing a name and description, and the register immediately appears in the register list after saving.

## Preconditions

- User is logged in as a non-admin user with `registers.write` permission
- App `openregister` is installed and enabled
- No register named "Testregister" exists yet

## Scenario

**Given** the user is on the OpenRegister overview page  
**When** they click "Add register"  
**And** fill in the name "Testregister" and description "A register for automated testing"  
**And** click "Save"  
**Then** a success notification is shown  
**And** the register "Testregister" appears in the register list  
**And** the register detail page is accessible

## Test Data

| Field       | Value                             |
|-------------|-----------------------------------|
| Name        | Testregister                      |
| Description | A register for automated testing  |
| UUID        | auto-generated                    |

## Acceptance Criteria

- [ ] "Add register" button is visible and clickable
- [ ] Form accepts name and description input
- [ ] Save action completes without error
- [ ] Success notification appears after save
- [ ] Register appears in list with the correct name
- [ ] Register detail page is accessible via list click
- [ ] When no name is entered, the save button is disabled

## Notes

This is the primary smoke test for OpenRegister. If this scenario fails, the app is
considered non-functional for basic use. Run this first when verifying any deployment.

## Persona Notes

- **Mark Visser** (MKB software vendor): Evaluates whether the CRUD workflow is fast and
  predictable. Expects the form to behave like standard business software — no surprises.
- **Jan-Willem van der Berg** (small business owner): Checks whether labels are in plain Dutch
  and the workflow is self-explanatory without needing documentation.
```
