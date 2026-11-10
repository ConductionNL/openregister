# Data Import and Export (retrofit delta)

## ADDED Requirements

### Requirement: ConfigurationService MUST provide the public facade over the configuration import/export handlers

`ConfigurationService` MUST expose the public entry points for register
configuration portability, delegating to the dedicated handlers (which own the
detailed import/export contract): `exportConfig()` → `Configuration/ExportHandler`,
`getUploadedJson()` → `Configuration/UploadHandler`, `importFromFilePath()` /
`importFromApp()` / `importFromJson()` → `Configuration/ImportHandler`,
`fetchRemoteConfiguration()` → `Configuration/FetchHandler`,
`previewConfigurationChanges()` / `importConfigurationWithSelection()` →
`Configuration/PreviewHandler`. The facade MUST pass through the handler results
unchanged and MUST be the single service consuming apps inject for
configuration import/export.

#### Scenario: App imports its bundled configuration through the facade
- **GIVEN** a consuming app calls `ConfigurationService::importFromApp('opencatalogi', $data, $version)`
- **WHEN** the facade runs
- **THEN** it MUST delegate to `Configuration/ImportHandler::importFromApp()` with the same arguments
- **AND** the returned summary MUST carry the handler's `registers`, `schemas`, `objects`, `endpoints`, `sources`, `mappings`, `jobs`, `synchronizations`, and `rules` keys unchanged

#### Scenario: Export configuration through the facade
- **GIVEN** a `Configuration` entity
- **WHEN** `ConfigurationService::exportConfig($config, includeObjects: true)` is called
- **THEN** the facade MUST delegate to `Configuration/ExportHandler::exportConfig()`, supplying the OpenConnector configuration service only when OpenConnector is installed (`hasOpenConnector()` true)
- **AND** return the OpenAPI 3.0.0 export array unchanged

#### Scenario: Upload resolution accepts file, URL, or inline JSON
- **GIVEN** a request whose body carries one of an uploaded file, a `url`, or an inline `json` dump
- **WHEN** `ConfigurationService::getUploadedJson($data, $uploadedFiles)` is called
- **THEN** it MUST delegate to `Configuration/UploadHandler::getUploadedJson()` which resolves the payload in that precedence order
- **AND** return either the parsed array or a `JSONResponse` error

### Requirement: ConfigurationService MUST track and compare imported-configuration versions

The system MUST support remote-version awareness for imported configurations.
`checkRemoteVersion()` MUST fetch a remote-sourced configuration, extract its
`version` (or `info.version`), and persist `remoteVersion` + `lastChecked` on the
`Configuration` entity; it MUST be a no-op returning `null` for non-remote
configurations or configurations without a source URL. `compareVersions()` MUST
use `version_compare()` to report `hasUpdate` with a human-readable message.
`getConfiguredAppVersion()` / `setConfiguredAppVersion()` MUST read/write the
last-imported version per app in appconfig.

#### Scenario: Remote version check persists the discovered version
- **GIVEN** a `Configuration` that `isRemoteSource()` with a valid source URL serving `{"version": "1.4.0"}`
- **WHEN** `checkRemoteVersion()` runs
- **THEN** the configuration's `remoteVersion` MUST be set to `1.4.0` and `lastChecked` MUST be updated
- **AND** the method MUST return `1.4.0`

#### Scenario: Version check is a no-op for non-remote configurations
- **GIVEN** a `Configuration` whose `isRemoteSource()` is false
- **WHEN** `checkRemoteVersion()` runs
- **THEN** the method MUST return `null` without performing an HTTP fetch

#### Scenario: Version comparison reports an available update
- **GIVEN** a configuration with `localVersion = 1.2.0` and `remoteVersion = 1.3.0`
- **WHEN** `compareVersions()` runs
- **THEN** the result MUST report `hasUpdate: true`
- **AND** the message MUST read `Update available: 1.2.0 → 1.3.0`

#### Scenario: Missing version information is reported, not assumed
- **GIVEN** a configuration with a `localVersion` but no `remoteVersion`
- **WHEN** `compareVersions()` runs
- **THEN** the result MUST report `hasUpdate: false`
- **AND** the message MUST indicate the remote version is unknown and prompt checking it first
