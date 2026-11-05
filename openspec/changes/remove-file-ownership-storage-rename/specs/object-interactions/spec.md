# object-interactions (delta)

## MODIFIED Requirements

### Requirement: File Attachments on Objects

Unchanged except for the ownership scenarios below: the scenario "Ownership repair still
runs when the current user can read the file" is removed, because the direct DB repair it
required (`FileMapper::setFileOwnership($fileId, $userId)`) renamed the file's whole
storage and no longer exists. The listing probe stays readability-only.

#### Scenario: Listing does not read file contents to probe ownership
- **GIVEN** object `abc-123` has attached files of varying sizes (including a 2 GB file)
- **WHEN** a GET request is sent to `/api/objects/{register}/{schema}/abc-123/files`
- **THEN** the access probe MUST use `$file->isReadable()` (a pure permission-bitmask check)
- **AND** the listing MUST NOT call `$file->getContent()` or otherwise read file bytes during formatting
- **AND** the listing MUST NOT acquire a shared lock on any file during formatting

#### Scenario: Listing never repairs ownership
- **GIVEN** the current user can read file 42 but is not its owner (the file belongs to the `openregister` system user)
- **WHEN** the file is encountered during listing
- **THEN** the file MUST be listed as-is
- **AND** no write to `oc_storages` or `oc_mounts` MUST be made
