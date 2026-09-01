## ADDED Requirements

### Requirement: Successful file-version restore MUST dispatch a typed FileVersionRestoredEvent for integration listeners

When a file version is restored via `FilesController::restoreVersion()` (after `FileVersioningHandler::restoreVersion()` returns successfully and the audit-trail entry has been written), the controller MUST construct and dispatch a typed `FileVersionRestoredEvent` via `IEventDispatcher::dispatchTyped()`. The event DTO carries the parent object UUID, the restored file ID, and an arbitrary `data` array. The event is the integration hook that n8n webhook triggers, registered Nextcloud event listeners, and downstream activity-stream providers consume; without it, file-version restores are observable only via the audit trail (best-effort) and not via the event bus.

This requirement (tracked as REQ-018) documents the observed event-dispatch contract — REQ-017 specifies the operation in `FileVersioningHandler::restoreVersion()` but stops at "return true on success" without mentioning the controller-level event side effect.

#### Scenario: Event is dispatched after successful restore

- **GIVEN** an authenticated user invokes `POST /api/objects/{register}/{schema}/{id}/files/{fileId}/versions/{versionId}/restore`
- **AND** the parent object resolves, the file resolves, and `FileVersioningHandler::restoreVersion()` returns `true`
- **WHEN** the controller continues past the audit-trail call
- **THEN** the controller MUST call `IEventDispatcher::dispatchTyped()` with a `FileVersionRestoredEvent` instance
- **AND** the event MUST carry `objectUuid = $object->getUuid()`, `fileId = <the restored file id>`, and `data = ["versionId" => <the restored version id>]`

#### Scenario: Event carries the version identifier so listeners can correlate with audit-trail entries

- **GIVEN** the controller has just dispatched `FileVersionRestoredEvent` with `data = ["versionId" => "v-1710892800"]`
- **WHEN** a registered listener receives the event and calls `$event->getData()`
- **THEN** the returned array MUST equal `["versionId" => "v-1710892800"]`
- **AND** `$event->getObjectUuid()` MUST return the parent object UUID exactly as it appears on the audit-trail entry's `object` column for the same restore operation
- **AND** `$event->getFileId()` MUST return the restored file ID as an `int`

#### Scenario: Event is NOT dispatched when the underlying restore fails

- **GIVEN** `FileVersioningHandler::restoreVersion()` throws `Exception("Version not found")` (per REQ-017 graceful-degradation contract)
- **WHEN** the controller's try/catch block handles the exception and returns a 4xx `JSONResponse`
- **THEN** no `FileVersionRestoredEvent` MUST be dispatched
- **AND** no audit-trail `file.version_restored` entry MUST be written for this request

#### Notes

- `FileVersionRestoredEvent` extends `OCP\EventDispatcher\Event`. Its constructor declares the three carried fields as `private readonly` promoted properties, exposed via `getObjectUuid()`, `getFileId()`, and `getData()`. Listeners MUST NOT mutate the event payload — there is no setter API.
- The `data` array is intentionally untyped: REQ-017 currently only writes `versionId` into it, but the open-ended shape lets future restore-related metadata (e.g. `restoredBy`, `previousVersionId`) be added without breaking the event signature.
- This requirement is paired with the audit-trail `file.version_restored` entry and REQ-017 (the `FileVersioningHandler::restoreVersion()` operation itself). All three fire on the same successful restore; only this requirement governs the typed-event side effect.
