# Tasks: rbac-default-authenticated

> Flip the absent-authorization default from public to authenticated
> (ADR-032 `kind: code`). Checkbox budget: 4 tasks × 2 = 8 unindented
> `- [ ]` lines (cap 20).
>
> ORDER IS LOAD-BEARING. Task 1 audits, Task 2 marks the intended-public
> schemas, and only then does Task 3 flip the default. Flipping first turns
> fifteen apps' public surfaces blank and calls it a security fix.

## Implementation Tasks

### Task 1: Audit every unmarked schema, by name
- **spec_ref**: `openspec/changes/rbac-default-authenticated/specs/rbac-default-authenticated/spec.md#requirement-the-fleets-unmarked-schemas-must-be-audited-before-the-default-flips`
- **files**: `docs/rbac-unmarked-schema-audit.md`
- **acceptance_criteria**:
  - Every schema with no authorization is LISTED BY NAME with an intent: public, authenticated, or restricted. 504 of them at the 2026-08-15 measurement across 15 apps — scholiq 118, shillinq 114, procest 85, openconnector 39, decidesk 34, hermiq 28, pipelinq 26, docudesk 20, openregister 16, larpingapp 9, portaliq 9, and four more
  - The survey is re-run at review time rather than quoted — the number moves as apps ship schemas, and a stale denominator makes the audit read as complete when it is not
  - Each app's own maintainers state the intent for their schemas; this task collects and records, it does not decide on their behalf
- [ ] Implement
- [ ] Test

### Task 2: Give the intended-public schemas an explicit rule
- **spec_ref**: `openspec/changes/rbac-default-authenticated/specs/rbac-default-authenticated/spec.md#requirement-an-unmarked-schema-must-require-an-authenticated-caller`
- **files**: per-app `lib/Settings/*_register.json`, per-app register tests
- **acceptance_criteria**:
  - Every schema the audit marked intended-public carries an explicit `read` rule for group `public`, merged BEFORE the default flips
  - Each app asserts its public set per schema by NAME — a count passes while the wrong schemas are marked, and the wrong ones here are somebody's payslips
  - No schema gains an anonymous WRITE rule as a side effect; read-only is asserted
- [ ] Implement
- [ ] Test

### Task 3: Flip the default to authenticated
- **spec_ref**: `openspec/changes/rbac-default-authenticated/specs/rbac-default-authenticated/spec.md#requirement-an-unmarked-schema-must-require-an-authenticated-caller`
- **files**: `lib/Service/PropertyRbacHandler.php`, plus the object-level RBAC path, `tests/Unit/Service/PropertyRbacHandlerTest.php`
- **acceptance_criteria**:
  - Absent authorization resolves to authenticated; the schema → register → default order is otherwise unchanged, with a test pinning that a register rule still wins over the default
  - The anonymous/authenticated PAIR is asserted on one unmarked schema: anonymous gets nothing, authenticated gets rows. A change that returned nothing to everybody passes the first assertion alone
  - An app-shaped test drives a `#[PublicPage]` controller reading an unmarked schema through `ObjectService` — the HTTP object API already refuses anonymous callers, so an HTTP-only test passes before the change and proves nothing
  - The three explicit shapes — `public`, `authenticated`, named group — are asserted byte-identical to before
- [ ] Implement
- [ ] Test

### Task 4: Make a default refusal observable, once per schema
- **spec_ref**: `openspec/changes/rbac-default-authenticated/specs/rbac-default-authenticated/spec.md#requirement-a-refusal-on-the-default-must-be-observable`
- **files**: `lib/Service/PropertyRbacHandler.php`, `tests/Unit/Service/PropertyRbacHandlerTest.php`
- **acceptance_criteria**:
  - A refusal that came from the ABSENT default logs the schema name and says so; a refusal from a declared rule does not — conflating them makes the audit useless exactly when it is needed
  - Emitted once per schema per request, not once per row: an unthrottled line on a list endpoint is a log flood, and a flood is what gets logging switched off
  - The message names the remedy (declare authorization, or add the `public` group) so the operator does not have to find this spec to act on it
- [ ] Implement
- [ ] Test
