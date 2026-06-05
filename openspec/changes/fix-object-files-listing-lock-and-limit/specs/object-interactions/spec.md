## MODIFIED Requirements

### Requirement: File Attachments on Objects

The system SHALL provide file attachment operations as sub-resource endpoints under objects. Files MUST be stored in Nextcloud's filesystem via `OCP\Files\IRootFolder` and linked to OpenRegister objects. The system MUST support upload, download, listing, deletion, and publish/depublish operations. The listing operation MUST honour any positive user-supplied `_limit` parameter without imposing an upper cap (default 30, floor 1), MUST NOT fail when individual files are held under a Nextcloud file lock, and MUST include `locked` and optional `lock` metadata on every entry when the caller is authenticated. For unauthenticated callers the listing MUST NOT include `locked` or `lock` in any file entry.

#### Scenario: Upload a file to an object
- **GIVEN** an OpenRegister object with UUID `abc-123`
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/files` with a file payload
- **THEN** the file MUST be stored in the Nextcloud filesystem
- **AND** the file MUST be linked to the object
- **AND** the response MUST return HTTP 201 with the file metadata

#### Scenario: List files for an object (authenticated caller)
- **GIVEN** an authenticated user and object `abc-123` with 3 attached files
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files`
- **THEN** the response MUST return all 3 files with metadata including `fileId`, `name`, `mimeType`, `size`, and `locked`

#### Scenario: List files for an object (anonymous caller)
- **GIVEN** no authenticated session (`IUserSession::getUser()` returns `null`) and a publicly accessible object `abc-123` with 3 attached files
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files`
- **THEN** the response MUST return all 3 files with metadata including `fileId`, `name`, `mimeType`, `size`
- **AND** NO file entry MUST include a `locked` field
- **AND** NO file entry MUST include a `lock` sub-object
- **AND** no call to `ILockManager::getLocks()` MUST be attempted for this request

#### Scenario: List files honours user-supplied `_limit` without a ceiling
- **GIVEN** object `abc-123` has 500 attached files
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files?_limit=500&_page=1`
- **THEN** the response MUST return 500 file entries (not silently clamped to 100)
- **AND** the pagination envelope MUST report `limit: 500`

#### Scenario: Large `_limit` values are honoured as-is
- **GIVEN** object `abc-123` has 2500 attached files
- **WHEN** a GET request is sent with `?_limit=5000`
- **THEN** the response MUST return all 2500 entries
- **AND** the pagination envelope MUST report `limit: 5000`
- **AND** the system MUST NOT apply any upper ceiling to the `_limit` value

#### Scenario: `_limit=0` or negative is clamped to 1
- **WHEN** a GET request is sent with `?_limit=0` or `?_limit=-10`
- **THEN** the response MUST return at least 1 entry (or 0 if no files exist) and the pagination envelope MUST report `limit: 1`

#### Scenario: Missing `_limit` uses the default
- **WHEN** a GET request is sent with no `_limit` query parameter
- **THEN** the pagination envelope MUST report `limit: 30`

#### Scenario: Listing is resilient to Nextcloud-locked files (authenticated caller)
- **GIVEN** an authenticated user and object `abc-123` with 5 attached files, one of which is held under a Nextcloud file lock by another user
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files`
- **THEN** the response MUST return HTTP 200 with all 5 file entries
- **AND** the locked file's entry MUST have `locked: true` and a `lock` sub-object with `type`, `scope`, `owner`, `createdAt`, and optional `expiresAt`
- **AND** each unlocked file entry MUST have `locked: false` and no `lock` sub-object
- **AND** one structured log line at `info` level MUST be emitted per locked file, including `fileId`, `name`, and lock owner

#### Scenario: Listing is resilient to Nextcloud-locked files (anonymous caller)
- **GIVEN** no authenticated session and a publicly accessible object `abc-123` with 5 attached files, one of which is held under a Nextcloud file lock
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files`
- **THEN** the response MUST return HTTP 200 with all 5 file entries
- **AND** NO file entry MUST include `locked` or `lock`
- **AND** the locked file's entry MUST still be present in the result set (not silently elided)
- **AND** one structured log line at `info` level MUST still be emitted server-side per locked file, so operators retain traceability even when the field is hidden from the caller

#### Scenario: `lock.type` maps Nextcloud lock constants to string aliases
- **GIVEN** a file is locked with `ILock::TYPE_USER`
- **WHEN** the file is formatted in a listing response
- **THEN** `lock.type` MUST equal `"user"`
- **AND** `TYPE_APP` maps to `"app"`, `TYPE_TOKEN` maps to `"token"`
- **AND** `lock.scope` MUST be `"exclusive"` for `ILock::LOCK_EXCLUSIVE` and `"shared"` for `ILock::LOCK_SHARED`

#### Scenario: `lock.expiresAt` is null when the lock has no timeout
- **GIVEN** a lock with `getTimeout()` returning `0` (no timeout)
- **WHEN** the file is formatted in a listing response
- **THEN** `lock.expiresAt` MUST be `null`
- **AND** `lock.createdAt` MUST still be an ISO 8601 string

#### Scenario: Lock provider is not available
- **GIVEN** the `files_lock` app is disabled, `ILockManager::isLockProviderAvailable()` returns `false`, and the caller is authenticated
- **WHEN** any file is formatted in a listing response
- **THEN** each file entry MUST have `locked: false`
- **AND** no file entry MUST include a `lock` sub-object
- **AND** no call to `ILockManager::getLocks()` MUST be attempted

#### Scenario: Listing does not read file contents to probe ownership
- **GIVEN** object `abc-123` has attached files of varying sizes (including a 2 GB file)
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files`
- **THEN** the ownership probe MUST use `$file->isReadable()` (a pure permission-bitmask check)
- **AND** the listing MUST NOT call `$file->getContent()` or otherwise read file bytes during formatting
- **AND** the listing MUST NOT acquire a shared lock on any file during formatting

#### Scenario: Ownership repair still runs when the current user can read the file
- **GIVEN** the current user can read file 42 (bitmask allows `PERMISSION_READ`) but is not recorded as its OpenRegister owner
- **WHEN** the file is encountered during listing
- **THEN** the ownership record MUST be repaired via `FileMapper::setFileOwnership($fileId, $userId)` (a direct DB write)
- **AND** no file bytes MUST be read as part of the repair

#### Scenario: Download all files as archive
- **GIVEN** object `abc-123` has multiple attached files
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files/download`
- **THEN** all files MUST be returned as a downloadable archive

#### Scenario: Publish a file for public access
- **GIVEN** a file with ID 42 is attached to object `abc-123`
- **WHEN** a POST request is sent to `/api/objects/{register}/{schema}/abc-123/files/42/publish`
- **THEN** the file MUST be made publicly accessible via a share link

#### Scenario: Delete a file from an object
- **GIVEN** a file with ID 42 is attached to object `abc-123`
- **WHEN** a DELETE request is sent to `/api/objects/{register}/{schema}/abc-123/files/42`
- **THEN** the file MUST be removed from the object and the filesystem
