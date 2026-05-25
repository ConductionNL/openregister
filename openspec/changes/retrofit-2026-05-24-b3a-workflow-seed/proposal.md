# Retrofit: Bucket 3a investigation — workflow/seed (5 REQs)

## Why

The coverage scanner flagged 5 requirements as "specified but with no annotated
implementation" — for each, the only evidence the scanner matched came from a
removed-lines cache (deleted diff hunks), not from live, annotated code. This
change records the investigation of those 5 REQs and adds the missing `@spec`
annotations where the behaviour was found to be implemented.

**Root cause (all 5 REQs):** the behaviour is fully implemented and the code
already carried `@spec` tags — but those tags pointed at *other* requirements
(or at archived change paths whose `tasks.md` the scanner could no longer
resolve), so the specific REQ under investigation had no annotation matching it.
None of the 5 REQs is missing. No new behaviour was implemented; only
annotations were added.

## Classification

| REQ | Title | Classification | Implementation | Evidence |
|-----|-------|----------------|----------------|----------|
| `approval-workflow#REQ-003` | List and filter approval steps | **IMPLEMENTED** | `ApprovalController::steps()` + `ApprovalStepMapper::findAllFiltered()` | Reads `status`/`role`/`chainId`/`objectUuid` query params, applies each only when present, delegates to `findAllFiltered($filters)`. Was annotated against an *archived* change path (`retrofit-2026-05-01-approval-workflow/tasks.md#task-3`), which the scanner could not resolve. |
| `archival-destruction-workflow#REQ-005` | Destruction Certificate Generation | **IMPLEMENTED** | `RetentionService::generateDestructionCertificate()` + `DestructionExecutionJob::run()` | Produces a `verklaring_van_vernietiging` with destruction date, approving archivist(s), counts grouped by schema/classificatie, selectielijst bron, `Archiefwet 1995` compliance statement, and `immutable: true`. The job persists it as a register object via `saveObject(...)` and tracks `skippedHolds`/`skippedErrors` for the partial-completion scenario. Existing tags pointed at REQ-009-era archived tasks, not REQ-005. |
| `seed-related-items#REQ-03` | Process Related Items After Object Creation | **IMPLEMENTED** | `ImportHandler::importSeedData()` → `processRelatedItems()` | `processRelatedItems(...)` is invoked only inside the per-object `try` block *after* `$createdObject` is saved; if creation throws, the `catch` skips related items for that object. Method carried tags only for REQ-01/REQ-06. |
| `seed-related-items#REQ-04` | Note Seeding | **IMPLEMENTED** | `ImportHandler::processRelatedItems()` | Iterates `_relatedItems.notes`, requires `message`, calls `NoteService::createNote($objectUuid, $message)` per note under the active user context. |
| `seed-related-items#REQ-10` | Logging | **IMPLEMENTED** | `ImportHandler::processRelatedItems()` + `importSeedData()` | INFO at start of per-object processing (per-type counts); DEBUG after processing (per-type created counts); WARNING on each failed item (type + error); INFO summary at end of seed import (`related_files`/`related_notes`/`related_tasks`). |

## What Changes

Annotation-only. No spec deltas (the specs already exist). `@spec` tags added:

- `lib/Controller/ApprovalController.php` — `steps()` → REQ-003
- `lib/Db/ApprovalStepMapper.php` — `findAllFiltered()` → REQ-003
- `lib/Service/RetentionService.php` — `generateDestructionCertificate()` → REQ-005
- `lib/BackgroundJob/DestructionExecutionJob.php` — `run()` → REQ-005 (cert persistence)
- `lib/Service/Configuration/ImportHandler.php` — `processRelatedItems()` → REQ-03, REQ-04, REQ-10; `importSeedData()` → REQ-03, REQ-10

## Issues to File

None. All 5 REQs are implemented; no missing/removed behaviour was found.
