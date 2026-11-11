---
kind: code
depends_on: []
---

# Proposal: register-folder-on-first-upload

## Why

On a fresh instance the first file uploaded into a register fails, because the register's folder does not exist yet and making it needs a register update the uploader may not perform. Portaliq hits this on every cold start: its portal requests have no Nextcloud session by design, so `POST /portal/api/collections/{register}/{schema}/{id}/files` answers 502 `upload_failed` until something else has created the folder (portaliq#29, and the duplicate portaliq#31). The E2E gate in portaliq#28 excludes one test for exactly this reason. The OpenRegister side is tracked as openregister#2515, and the open change `consolidate-permission-handling` (proposal point 4, task 4) already fixes where the answer must live: in folder initialisation, without widening system trust on a public-facing write path.

The chain is in the issue: `FileService::addFile()` reaches `FolderManagementHandler::createRegisterFolderById()`, which creates the folder and then stores its id with `RegisterMapper::update()`. That call checks `update` permission on registers and then that the register belongs to the caller's active organisation. An anonymous portal request fails the first check. Wrapping the call in `SystemOperationContext::run()` (the issue's option 2) passes the first check but not the second, because `verifyOrganisationAccess()` has no system bypass: a portal request always acts in the default organisation, so a register owned by any other organisation would still refuse. It would also keep firing the register-updated event (activity entry, webhook, notification) for what is only bookkeeping.

## What Changes

- The folder id of a register is recorded as bookkeeping, not as an edit of the register: a single-column write of `folder`, scoped to the register the upload resolved, that only lands while the stored value is still empty or what the handler read. It changes no other field, bumps no version and dispatches no register-updated event.
- Creating the folder is idempotent. A second upload reuses the recorded folder. When two first uploads race, the one whose folder creation is refused because the other already made it takes that folder instead of failing, and a folder id another request recorded first is never overwritten.
- No trust is widened: no new bypass in `MultiTenancyTrait`, no `SystemOperationContext` scope on the upload path, and no change to who may update a register. The recorder is not a permission decision; it is a fixed write whose value the system produced.
- The folder still lives where it does today (`Open Registers/<title> Register` in the OpenRegister system user's files for a request without a session), and the uploader's own permissions still decide whether the upload itself is allowed. Nothing about who may upload changes.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `file-actions`: a requirement is added: a register's folder is created on its first upload, by whoever uploads, and its id is recorded as bookkeeping.

## Impact

- `lib/Db/RegisterFolderRecorder.php` (new): the conditional single-column write. It is its own class because `RegisterMapper` sits 18 lines under phpmd's class-length cap.
- `lib/Service/File/FolderManagementHandler.php`: `createRegisterFolderById()` records through the recorder instead of `RegisterMapper::update()`; `createFolderPath()` takes a folder a concurrent request just created instead of failing. The constructor takes the recorder.
- `lib/AppInfo/Application.php`: the manual registration of `FolderManagementHandler` passes the recorder.
- Tests: a first-upload test with a fake root that has no folder yet and a session-less request, a recorder test, a wiring test for the registration closure, and the three existing handler tests that expected `update()`.
- `docs/api/objects.md`: one paragraph under File Storage on who makes the register folder.
- No route, schema, migration or dependency change.
- Consumer: portaliq can drop its E2E exclusion (`GREP_INVERT` in `tests/e2e/playwright.config.ts`) once this lands; that is portaliq#29's gate-side definition of done.
