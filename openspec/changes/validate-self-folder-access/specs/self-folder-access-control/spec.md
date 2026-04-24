## ADDED Requirements

### Requirement: `@self.folder` writes require read access to the target folder

When an object is saved with a non-empty, numeric value for `@self.folder` (or the equivalent post-hydration `ObjectEntity::getFolder()`), the system SHALL verify that the acting user can read the Nextcloud folder identified by that node ID before the object is associated with the folder. "Can read" is defined as: the folder is reachable via the acting user's user-folder mount AND `Folder::isReadable()` returns `true`. If either check fails, the system SHALL abort the save with `FolderAccessDeniedException`; it SHALL NOT fall back to creating a new folder and SHALL NOT bind the object to the requested folder.

#### Scenario: Binding to a folder the user can read succeeds
- **GIVEN** user `alice` has read access to folder node `42` (owned by her, or shared with her with read permissions)
- **WHEN** `alice` POSTs an object with `@self.folder: "42"`
- **THEN** the save succeeds, the stored object's `folder` equals `"42"`, and `createObjectFolderById()` returns the existing folder without creating a new one

#### Scenario: Binding to a folder the user cannot read fails with 403
- **GIVEN** user `alice` has no access to folder node `99` (owned by `bob`, not shared with `alice`)
- **WHEN** `alice` POSTs an object with `@self.folder: "99"`
- **THEN** the HTTP response is 403, the response body identifies the error as a folder-access denial, and no object is persisted

#### Scenario: Binding to a non-existent folder ID fails with 403
- **GIVEN** node ID `999999` does not exist in the Nextcloud filesystem
- **WHEN** any user POSTs an object with `@self.folder: "999999"`
- **THEN** the HTTP response is 403, no folder is created, and no object is persisted

#### Scenario: Binding to a file (not a folder) fails with 403
- **GIVEN** node `51` exists but is a file, not a folder
- **WHEN** any user POSTs an object with `@self.folder: "51"`
- **THEN** the HTTP response is 403 and no object is persisted

#### Scenario: Binding to a trashed / deleted folder fails with 403
- **GIVEN** folder `77` was readable yesterday but has since been moved to the user's trash
- **WHEN** the user POSTs an object with `@self.folder: "77"`
- **THEN** the save fails with `FolderAccessDeniedException` mapped to HTTP 403

### Requirement: Empty and legacy non-numeric folder values preserve auto-create behaviour

When `ObjectEntity::getFolder()` is empty OR is a non-numeric string (legacy path-style value), the system SHALL NOT run the access check and SHALL continue through the existing auto-create path — creating a fresh folder under the register folder. This preserves compatibility with legacy installations and with the default flow where no `@self.folder` is supplied.

#### Scenario: Save without @self.folder auto-creates a folder
- **WHEN** a user POSTs an object without setting `@self.folder`
- **THEN** the system creates a new folder under the register folder, stores its node ID on the entity, and the save succeeds

#### Scenario: Legacy non-numeric folder string falls through to auto-create
- **GIVEN** an entity with `folder = "legacy/path/string"` (non-numeric)
- **WHEN** the save pipeline invokes `createObjectFolderById()`
- **THEN** no access check runs, a new folder is auto-created, the legacy string is replaced with the new numeric node ID, and no exception is thrown

### Requirement: `FolderAccessDeniedException` is the canonical denial signal

The system SHALL define a new exception class `FolderAccessDeniedException` in `lib/Exception/` that extends `\Exception`. Every denial path defined in the preceding requirement — unreadable folder, non-existent node, file instead of folder, trashed folder — SHALL throw this exception and no other. Controllers and services calling the save pipeline SHALL catch `FolderAccessDeniedException` specifically (not generic `\Exception`) and map it to HTTP 403 with a structured error body containing at minimum `{ "error": "folder_access_denied", "folder": "<requested-id>" }`.

#### Scenario: Exception class exists and extends `\Exception`
- **WHEN** the codebase is analysed
- **THEN** `OCA\OpenRegister\Exception\FolderAccessDeniedException` exists, extends `\Exception`, and is the parent class (no deeper hierarchy)

#### Scenario: Controller returns HTTP 403 with structured body
- **GIVEN** any save path throws `FolderAccessDeniedException`
- **WHEN** the HTTP controller returns its response
- **THEN** the status is 403 and the body contains `error: "folder_access_denied"` and the requested folder ID

### Requirement: Denial produces an audit-trail entry

On every `FolderAccessDeniedException` thrown from `createObjectFolderById()`, the system SHALL write an audit-trail entry with `action: "folder_access_denied"`, the actor user ID (or `"system"` when no session user is present), the requested folder ID, and the timestamp — BEFORE propagating the exception. If the audit-trail write itself fails, the failure SHALL be logged at warning level and the original exception SHALL still be thrown (audit is best-effort; denial is authoritative).

#### Scenario: Denial creates audit-trail entry
- **WHEN** `alice` triggers a denial by requesting cross-tenant folder `99`
- **THEN** `AuditTrailMapper::findByAction("folder_access_denied")` returns at least one entry with actor `alice`, folder `99`, and a timestamp within the last few seconds of the attempt

#### Scenario: Audit-write failure does not swallow denial
- **GIVEN** the audit-trail mapper is temporarily failing
- **WHEN** a denial occurs
- **THEN** `FolderAccessDeniedException` is still thrown, a warning is logged, and no object is persisted

### Requirement: System/cron contexts without a session user are unaffected when they do not set `@self.folder`

The access check SHALL only execute when `ObjectEntity::getFolder()` is non-empty and numeric. Code paths that do not set `@self.folder` (including all existing cron jobs and the `RegistersLoader` seed path, which resolves placeholder slugs to freshly-created folders before calling the save pipeline) SHALL continue to execute the existing auto-create behaviour with no new exception surface.

#### Scenario: Cron job without @self.folder is unaffected
- **GIVEN** `TransferCheckJob` creates an object through the save pipeline with no `@self.folder` set
- **WHEN** the job runs
- **THEN** auto-create runs, no access check is invoked, and no `FolderAccessDeniedException` is thrown

#### Scenario: System context explicitly passing IUser can bind
- **GIVEN** a service-layer caller invokes `createObjectFolderById($objectEntity, $currentUser)` with `$currentUser` being a user who has read access to the requested folder
- **WHEN** the method runs
- **THEN** the access check uses the passed-in `$currentUser` rather than the session user, and the bind succeeds

### Requirement: `getNodeById()` retains root-folder fallback for non-binding callers

The change SHALL NOT modify `FolderManagementHandler::getNodeById()`'s existing user-folder → root-folder fallback behaviour for callers other than `createObjectFolderById()`. Anonymous / public file-retrieval paths continue to rely on the root-fallback. The binding path gains its own restrictive lookup helper `assertFolderIsAccessible()` (or equivalent private method) that deliberately does not use the root fallback.

#### Scenario: Non-binding file read still resolves via root fallback
- **GIVEN** an anonymous public download path calls `getNodeById($fileId)` for a file owned by another user
- **WHEN** the user-folder lookup returns empty
- **THEN** the root-folder fallback resolves the node and the download continues (unchanged pre-existing behaviour)

#### Scenario: Binding path does not use root fallback
- **GIVEN** `alice` POSTs with `@self.folder: "<bob's-folder-id>"` where the folder is not mounted in `alice`'s user folder
- **WHEN** `createObjectFolderById()` attempts to resolve the ID
- **THEN** the lookup fails (user-folder miss, no fallback), and `FolderAccessDeniedException` is thrown
