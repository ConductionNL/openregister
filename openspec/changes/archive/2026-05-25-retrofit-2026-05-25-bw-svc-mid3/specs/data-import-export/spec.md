---
retrofit_extensions:
  - REQ-CFG-TRACK-001
---

# Data Import/Export Specification (delta)

**Status**: implemented (retrofit — code already exists)
**Scope**: openregister

## ADDED Requirements

### Requirement: Configuration imports MUST be tracked in a per-app Configuration entity for idempotent re-import

`ImportHandler::createOrUpdateConfiguration(array $data, string $appId, string $version, array $result, ?string $owner = null): Configuration` MUST find-or-create a single `Configuration` entity per `$appId` (looked up via `ConfigurationMapper::findByApp($appId)`, taking the first match) so repeated imports of the same app reconcile into one tracking record rather than accumulating duplicates.

Metadata MUST be extracted via a fallback chain: title and description MUST be read from `data.info.*` (OAS) first, then `data.x-openregister.*`, then top-level `data.*`, falling back to `"Configuration for {appId}"` / `"Imported configuration for application {appId}"`; `type` MUST be read from `x-openregister.type` → `data.type` → `'imported'`. Imported entity IDs MUST be collected from `$result['registers']`, `$result['schemas']`, and `$result['objects']`, taking only `Register` / `Schema` / `ObjectEntity` instances.

On an **existing** configuration the handler MUST update title/description/type/version and MUST merge the freshly imported register/schema/object IDs with the previously tracked IDs using `array_unique(array_merge(existing, new))` — so re-importing never loses previously tracked entities — then persist via `ConfigurationMapper::update()`.

On a **new** configuration the handler MUST set title/description/type/app/version and the fresh ID lists, MUST mark it `isLocal = true`, `syncEnabled = false`, `syncStatus = 'never'`, MUST fold optional `x-openregister` source/version metadata (`openregister`, `sourceType`, `sourceUrl`), MUST accept GitHub coordinates in either the new nested `x-openregister.github.{repo,branch,path}` shape or the legacy flat `x-openregister.github{Repo,Branch,Path}` shape, MUST set `owner` when the `$owner` argument is provided, then persist via `ConfigurationMapper::insert()`.

Any failure MUST be logged and re-thrown wrapped as `Failed to create or update configuration: {message}`.

#### Scenario: Re-import merges entity IDs into the existing configuration
- **GIVEN** an existing `Configuration` for app `myapp` tracking registers `[1]`, schemas `[10]`, objects `[100]`
- **WHEN** `createOrUpdateConfiguration` runs with a result importing registers `[1, 2]`, schemas `[11]`, objects `[100, 101]`
- **THEN** the existing record MUST be updated (not a new one created)
- **AND** its registers MUST become `[1, 2]`, schemas `[10, 11]`, objects `[100, 101]` (union, de-duplicated)
- **AND** title/description/type/version MUST be refreshed from the new import data

#### Scenario: First import creates a local, sync-disabled tracking record
- **GIVEN** no existing `Configuration` for app `freshapp`
- **WHEN** `createOrUpdateConfiguration` runs
- **THEN** a new `Configuration` MUST be inserted with `app = 'freshapp'`, `isLocal = true`, `syncEnabled = false`, `syncStatus = 'never'`
- **AND** the register/schema/object ID lists MUST equal the freshly imported IDs

#### Scenario: Metadata falls back through OAS → x-openregister → default
- **GIVEN** import data with no `info.title` and no `x-openregister.title` and no top-level `title`, for app `acme`
- **WHEN** `createOrUpdateConfiguration` runs
- **THEN** the configuration title MUST default to `"Configuration for acme"`
- **AND** when `info.title` IS present it MUST take precedence over both `x-openregister.title` and the default

#### Scenario: GitHub coordinates accepted in nested or legacy-flat shape
- **GIVEN** a new configuration import whose `x-openregister` carries `github: {repo, branch, path}` (nested)
- **WHEN** the new-configuration branch runs
- **THEN** `githubRepo`/`githubBranch`/`githubPath` MUST be populated from the nested keys
- **AND** an import using the legacy flat `githubRepo`/`githubBranch`/`githubPath` keys MUST populate the same fields

## Non-Functional

- **i18n (ADR-007):** This delta tracks import bookkeeping with no user-facing
  copy of its own. The default title/description strings
  (`"Configuration for {appId}"`) are administrative fallbacks for untitled
  imports, not localised end-user labels; when the import data carries
  `info.title`/`description` those caller-supplied (already-localised) values
  take precedence. The wrapped failure message
  (`Failed to create or update configuration: {message}`) is an
  operator/log diagnostic and is exempt from translation.
- **Idempotency:** re-importing the same app MUST reconcile into a single
  tracking record and MUST NOT lose previously tracked entity IDs
  (`array_unique(array_merge(...))`).

## Acceptance Criteria

- One `Configuration` per app: re-import updates the existing record (union,
  de-duplicated IDs); first import inserts a `isLocal=true`, `syncEnabled=false`,
  `syncStatus='never'` record.
- Metadata resolves via the OAS `info` → `x-openregister` → top-level → default
  fallback chain, `info.title` winning when present.
- GitHub coordinates populate identically from the nested
  `x-openregister.github.{repo,branch,path}` and the legacy flat shape.
- Any failure is logged and re-thrown wrapped as
  `Failed to create or update configuration: {message}`.
