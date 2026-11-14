# Tasks: approval-position-task-form

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

## 1. Compile and validate

- [x] 1.1 `lib/Service/ApprovalChainAnnotationInstaller.php` `compilePositions()`: carry `form` (parsed by `TaskFormReader`, D-1); the template version covers it (D-3). Register `form` in the `x-openregister-approval-chains` vocabulary entry.
- [x] 1.2 Schema-save validation per D-2 with the flow step's message shape. Verify: `tests/Unit/Service/ApprovalChainAnnotationInstallerTest.php` for a valid form and a read-only, a missing and an invisible field.

## 2. Provision

- [x] 2.1 `lib/Service/Task/TaskSequenceService.php` `provision()`: `metadata.form` from the position through `TaskForm::toArray()`. Verify: `tests/Unit/Service/Task/TaskSequenceServiceTest.php` with the real `TaskService` import, and a completion refused for a missing required field.
- [x] 2.2 Open task keeps its form after the chain changes. Verify: same test file.

## 3. Close

- [x] 3.1 `docs/`: the `form` key on an approval position. Tell buildiq the change name so `logic-approval-task-form` lifts its refusal. Documented in `docs/development/approval-position-task-form.md`; the buildiq hand-back is in the PR body.
- [x] 3.2 `@spec` tags; `openspec validate approval-position-task-form --strict`. (`@spec` tags added; the openspec CLI is not available in the build environment, so the validate run is left to review.)
