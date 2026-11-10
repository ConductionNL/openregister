---
kind: code
depends_on: []
---
# Proposal: approval-position-task-form

## Summary

An approver who opens an approval task should fill in what the step asks for: the approved amount, a condition, a reference. A schema's approval chain (`x-openregister-approval-chains`) compiles each `approvers` entry into a position of a task sequence, and every position becomes a flow task. A flow task can already carry a field form (`flow-task-forms`, `metadata.form`). The chain cannot give it one: the compiler copies only `role`, `min`, `minAmount`, `statusOnApprove` and `statusOnReject` into a position, and the sequence service creates the task without a form. This change lets a position declare a form and carries it into the task it opens.

## Halves this closes

buildiq's `logic-approval-task-form` (merged in buildiq #1046) writes `form: {kind: "fields", fields: [...]}` on the chain's position and copies it to the task through OpenRegister (its design D-3). Until OpenRegister carries it, buildiq's compiler refuses a non-empty form with "Approval forms need OpenRegister to carry a form into the task". No row in OpenRegister's matrix: this is the platform half of a buildiq capability.

## What is there

- `lib/Service/ApprovalChainAnnotationInstaller.php:213-240` `compilePositions()`: one position per `approvers` entry, carrying `order`, `role` and four optional keys. The template id and version are a pure function of the schema (`:56`, `:258`).
- `lib/Service/Task/TaskSequenceService.php:150-185` `provision()`: one `TaskService::import()` per position with `templateSnapshot` set to the position and no `metadata`.
- `TaskService::import()` (`lib/Service/Task/TaskService.php:274-277`) already runs `refuseUnrenderableForm()` on a task carrying `metadata.form`; `TaskForm::toArray()` (`lib/Service/Task/TaskForm.php:125`) is the record shape; `TaskFormCompletion` completes a task through it.

## What changes

- An `approvers` entry MAY carry `form` in the `flow-task-forms` declaration shape (`kind: "fields"` with `fields`, or `kind: "external"` with `formId`). The compiler copies it onto the position.
- The schema save refuses a chain whose position form names a field that cannot be rendered (not a property, read-only or not visible), with the same rule and message as a flow step (`flow-task-forms`, "A field that cannot be rendered is refused when the step is saved").
- `provision()` puts the position's form into each created task's `metadata.form`, normalised through `TaskForm`. A run-less approval task then presents it through the existing form path.
- A changed form changes the template version, so open tasks keep the form they were opened with.

## ADRs

- ADR-098: one task store; an approval step is a task and gets the task's form.
- ADR-022: buildiq declares, OpenRegister runs.

## Impact

- Extends `flow-approval-consolidation`.
- Affected code: `lib/Service/ApprovalChainAnnotationInstaller.php`, `lib/Service/Task/TaskSequenceService.php`, the annotation vocabulary entry for `x-openregister-approval-chains`.
- Backwards compatible: a position without `form` creates the same task as today.
- Size: S.
