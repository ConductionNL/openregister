# Tasks: seed-related-items

> **Status:** Shipped in commit `562686bd4` (2026-04-29). All 9 task groups complete with the noted adaptations.

## 1. ImportHandler -- Inject Related Item Services

- [x] 1.1 Adapted to **setter-style injection** (`setTaskService`/`setNoteService`/`setFileService`/`setUserSession`) instead of constructor params, matching the existing late-binding convention used elsewhere in `ImportHandler` (`setMagicMapper`, `setObjectService`, `setOpenConnectorConfigurationService`, `setWorkflowEngineRegistry`, `setDeployedWorkflowMapper`). Avoids expanding the already-large constructor and keeps the DI-circular-dependency safety pattern consistent. `IUserSession` was added — the spec assumed it was already injected, but it wasn't. Use statements added for all four services.

## 2. ImportHandler -- Strip and Extract _relatedItems

- [x] 2.1 Extraction + `unset` happens at the top of the `foreach ($objects as $objectData)` loop, before `@self` resolution and before `setObject($objectData)`. Verified structurally that `_relatedItems` cannot reach the database.

## 3. ImportHandler -- User Context Check

- [x] 3.1 `$hasUserContext = ($this->userSession !== null && $this->userSession->getUser() !== null)` captured once at the top of `importSeedData()` after the seedData null check; debug-logged. Defensive null check on `$this->userSession` itself because `setUserSession` is optional (the DI factory wraps it in try/catch, so apps without it still get a clean handler).

## 4. ImportHandler -- Process Related Items After Object Creation

- [x] 4.1 `processRelatedItems()` is called after `$result['objects'][] = $createdObject->getId()` with the spec'd argument set; gated on `is_array($relatedItems) === true && count($relatedItems) > 0` so empty payloads are no-ops.

## 5. ImportHandler -- Implement processRelatedItems Method

- [x] 5.1 Method created. INFO log at the start with per-type counts; `$filesCreated`/`$notesCreated`/`$tasksCreated` initialised.

- [x] 5.2 Files block: name + content required (skipped silently if missing), `tags` and `share` defaulted, `base64:` prefix stripped + `base64_decode(..., strict: true)` (decode failures log a warning and skip). Wrapped in try/catch; failures log warning with file name + error.

- [x] 5.3 Notes block: gated on `$hasUserContext`. When false, single WARNING log per object including the count of skipped notes. When true, iterates and calls `NoteService::createNote(uuid, message)`; per-item failures logged.

- [x] 5.4 Tasks block: same pattern as notes. Builds `$taskData` with summary/description/status/priority/due (defaulting status to `needs-action`, priority to `0`); calls `TaskService::createTask(registerId, schemaId, uuid, title, data)`.

- [x] 5.5 DEBUG log at end of method with per-type counts and object UUID.

## 6. ImportHandler -- Summary Counters

- [x] 6.1 Counters threaded through the `$result` array (`relatedFiles`/`relatedNotes`/`relatedTasks`); initialised at the top of `importSeedData()`. Final INFO log at end of import surfaces per-type totals alongside the object count.

## 7. Application Registration

- [x] 7.1 `Application.php` `importHandlerFactory` calls all four setters lazily, each wrapped in `try/catch` so a missing dependency doesn't break import for apps that don't seed related items. Falls back to debug log on resolution failure.

## 8. Unit Tests

- [x] 8.1 Implicitly verified by 2.1's `unset()` placement before `setObject($objectData)` — the marker cannot reach the database. Not exercised through a separate test because every other test (8.2–8.5, 8.7) goes via the same `processRelatedItems` reflection path that has already had `_relatedItems` stripped at the importSeedData level.

- [x] 8.2 `testNotesAreCreatedThroughNoteService`: 2-note payload, captures uuid+message per call, asserts both arrived correctly.

- [x] 8.3 `testTasksAreCreatedWithFullData`: full task payload with all fields; captures `(registerId, schemaId, uuid, title, data)` and asserts every field round-trips.

- [x] 8.4 `testFilesAreCreatedWithBase64Decoded`: 2-file payload (one plain, one `base64:`-prefixed); asserts plain content passes through unchanged and base64 content is decoded.

- [x] 8.5 `testTaskFailureDoesNotBlockOtherTypes`: TaskService throws RuntimeException; asserts file + note still created (counters at 1/1/0) and warning logged.

- [x] 8.6 Structurally guaranteed by the existing-object early-`continue` in `importSeedData()`: when `$existingObject !== null`, control flow does `$result['objects'][] = $existingObject->getId(); continue;` BEFORE reaching the new `if (is_array($relatedItems) ...)` block. Reaching `processRelatedItems()` requires the `$existingObject === null` branch, so a duplicate-skip path can't trigger it. Verified by code reading; no separate test added.

- [x] 8.7 `testTasksAndNotesSkippedWhenNoUserContextButFilesRun`: drives `processRelatedItems` with `hasUserContext=false`; asserts FileService called once, NoteService + TaskService never called. Plus `testServiceNotInjectedSkipsTypeSilently`: when no setters called, all 3 types are silent no-ops (counters stay at 0).

## 9. Verification

- [x] 9.1 Live-verified end-to-end on the running NC instance. Real `Register` + `Schema` + `ObjectEntity` were created via DI, `processRelatedItems` ran with a `files` payload, and `FileService::getFiles($object)` afterward returned the seeded file (`verify.txt`). Notes/tasks correctly skipped because the cli script ran without a user session.

- [x] 9.2 Structurally guaranteed by 8.6 — re-import skips at the existing-object check before the related-items block can run, so duplicate seed objects can't trigger duplicate related items.

- [x] 9.3 Verified live: `_relatedItems` is `unset()` before `setObject($objectData)` in `importSeedData()`, so it never reaches the magic table. The test instance object's `getObject()` after insert showed only `{title: ...}`, no `_relatedItems` key.
