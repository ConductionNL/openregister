---
kind: code
depends_on: []
---

# Proposal: object-folder-is-bookkeeping

## Why

Listing an object's files could save the whole object. The first listing on an object without a files folder makes that folder in `FolderManagementHandler::createObjectFolderById()`. The handler then stored the folder id with `MagicMapper::update()`. That is the save path: it dispatches `ObjectUpdatingEvent` and `ObjectUpdatedEvent`.

So a plain GET ran every save-time listener on the object, as whoever was reading. Calculations, quality scoring, retention and other apps' handlers all ran. No audit row was written and no version was bumped, so nothing showed it had happened.

The visible damage was in dossiq. Portaliq listed a case's documents for a citizen through `FileService::getFiles()`. The calculation listener ran without the caller's references and nulled the case's calculated fields. openregister#4263 made calculations safe for that caller. The full save on a read remained, and any other listener can still be woken by a GET.

The register side had the same shape and was fixed by `register-folder-on-first-upload`: its folder id is recorded by `RegisterFolderRecorder`, one column, no event. Objects did not get the same treatment.

## What changes

- The folder id of an object is recorded as bookkeeping, not as an edit of the object. `MagicMapper::recordFolder()` writes the `_folder` column of the one row and nothing else.
- The write dispatches no object lifecycle event, bumps no version and changes no other field.
- It only lands while the stored value is still empty or what the handler read. A folder id another request recorded first is never overwritten.
- `createObjectFolderById()` records through it instead of `MagicMapper::update()`. This is the only place where a folder was stored on an object outside a save.
- No audit row is written for the folder id. This follows `register-folder-on-first-upload` (REQ-RFFU-002) and what the object path already did: `MagicMapper::update()` never wrote one either, since audit rows come from the save service.

## Capabilities

### New capabilities
- None.

### Modified capabilities
- `file-actions`: reading an object's files never runs save-time logic on the object, and recording its folder id is bookkeeping.

## Impact

- `lib/Db/MagicMapper.php`: new `recordFolder(entity, expected, folderId): bool`.
- `lib/Service/File/FolderManagementHandler.php`: `createObjectFolderById()` records through `recordFolder()`.
- Tests: `tests/Unit/Service/File/FolderManagementHandlerObjectFolderBookkeepingTest.php` and `tests/Unit/Db/MagicMapper/MagicMapperRecordFolderTest.php`.
- Not changed: `SaveObject` still sets the folder inside a real save, and `RegisterService::ensureRegisterFolderExists()` runs inside a register create or update.
- No route, schema, migration or dependency change.
