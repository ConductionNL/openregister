---
retrofit_extensions: [REQ-017]
---

### REQ-017: The system MUST support listing and restoring file versions via Nextcloud files_versions

When Nextcloud's `files_versions` app is enabled, the system MUST expose version history and restore operations for files attached to register objects. The `FileVersioningHandler` MUST wrap `IVersionManager` to list all historical snapshots with metadata (versionId, timestamp, size, author, isCurrent flag) and MUST support restoring a specific version by its timestamp-based identifier. When `files_versions` is disabled, listing MUST degrade gracefully by returning an empty version array with a warning, while restoring MUST throw an Exception.

#### Scenario: List versions when files_versions is enabled
- **GIVEN** the `files_versions` Nextcloud app is enabled
- **WHEN** `FileVersioningHandler::listVersions($file)` is called
- **THEN** the response MUST include a `versions` array where the first entry represents the current file version (`isCurrent: true`) with fields `versionId`, `timestamp`, `size`, `author`, `authorDisplayName`, `label`, and `isCurrent`
- **AND** each historical version MUST have `versionId` in the format `v-{unix_timestamp}` and `isCurrent: false`

#### Scenario: Graceful degradation when files_versions is disabled
- **GIVEN** the `files_versions` Nextcloud app is NOT enabled
- **WHEN** `FileVersioningHandler::listVersions($file)` is called
- **THEN** the response MUST return `{versions: [], warning: "File versioning is not enabled on this instance"}`
- **AND** `FileVersioningHandler::restoreVersion($file, $versionId)` MUST throw an Exception with the same message

#### Scenario: Restore a specific file version
- **GIVEN** the `files_versions` app is enabled and file `/user/files/report.pdf` has a historical version with `versionId: "v-1710892800"`
- **WHEN** `FileVersioningHandler::restoreVersion($file, "v-1710892800")` is called
- **THEN** the system MUST locate the version whose timestamp matches `1710892800`
- **AND** call `IVersionManager::rollback($version)` to restore the file content
- **AND** return `true` on success
- **AND** throw an Exception with message `"Version not found"` if no matching timestamp exists

#### Notes
- The `IVersionManager` is resolved via `\OCP\Server::get()` with a class_exists guard since `OCA\Files_Versions\Versions\IVersionManager` is not always available (depends on enabled apps). This is an observed runtime resolution pattern, not dependency injection.
- The `getCurrentUserId()` private method falls back to the literal string `'system'` when no authenticated session exists (background job context).
