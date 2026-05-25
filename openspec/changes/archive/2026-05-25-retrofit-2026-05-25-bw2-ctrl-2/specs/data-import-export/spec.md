---
status: draft
---

# data-import-export

## Purpose

Extend data-import-export with the configuration-management and Git-remote
synchronisation HTTP surface. The existing "Configuration import/export MUST
support full register portability" requirement defines the portability *file
format* and the export/import handlers, but there is no requirement for the
configuration-entity CRUD or for the remote version-check / preview /
GitHub-GitLab discovery surface that drives configuration sync from the UI.
Reverse-specced from the live `ConfigurationController` and
`ConfigurationsController`.

## ADDED Requirements

### Requirement: Configuration Management and Git-Remote Sync HTTP Surface

The system MUST expose a configuration-management REST surface and a
Git-remote synchronisation surface so administrators can manage configuration
entities and import/sync configurations from GitHub or GitLab.
`ConfigurationsController` MUST provide resource CRUD
(`index`/`show`/`create`/`update`/`patch`/`destroy`) over configuration
entities, returning `404` for unknown ids and `201` on create where set
explicitly. `ConfigurationController` MUST additionally expose:

- `checkVersion` (`POST /api/configurations/{id}/check-version`) — compare the
  stored configuration against its remote source version;
- `preview` (`GET /api/configurations/{id}/preview`) — return the diff/preview
  of pending changes without applying them;
- `enrichDetails` (`GET /api/configurations/enrich`) — fetch and attach the
  actual remote file contents to configuration descriptors;
- `getGitHubRepositories` (`GET /api/configurations/github/repositories`) —
  list repositories the authenticated user can access;
- `getGitHubConfigurations` (`GET /api/configurations/github/files`) — list
  configuration files in a GitHub repository; and
- `getGitLabConfigurations` (`GET /api/configurations/gitlab/files`) — the
  GitLab equivalent.

The Git-discovery endpoints MUST resolve the caller's credentials/token from
configuration and MUST NOT leak repository contents the caller cannot access.
The configuration `export` and `import` methods on both controllers remain
governed by the existing "Configuration import/export MUST support full
register portability" requirement and are not redefined here.

#### Scenario: CRUD over configuration entities
- **GIVEN** a configuration entity with a known id
- **WHEN** `GET /api/configurations/{id}` is called
- **THEN** the response MUST return HTTP 200 with the entity's JSON serialization
- **AND** an unknown id MUST return HTTP 404 with an `{error}` body
- **AND** `PATCH /api/configurations/{id}` MUST apply a partial update via the same write path as `update`

#### Scenario: Check a configuration against its remote version
- **GIVEN** a configuration with a remote source
- **WHEN** `POST /api/configurations/{id}/check-version` is called
- **THEN** the response MUST report whether the remote version is newer, equal, or older than the stored configuration

#### Scenario: Discover GitHub configuration files
- **GIVEN** an authenticated user with a configured GitHub token
- **WHEN** `GET /api/configurations/github/files` is called for a repository
- **THEN** the response MUST list the configuration files in that repository
- **AND** repositories or files the caller cannot access MUST NOT be returned

## Non-Functional Requirements

- **i18n (ADR-007)**: These are administrator-facing configuration/Git-sync JSON
  REST endpoints. The only app-authored strings are `{error}` diagnostics on
  unknown ids and failures, which are operator copy and exempt from translation.
  Configuration payloads and remote file listings carry external/portability
  data, not localisable UI copy. (ADR-007 n/a.)
- **REST/error contract (ADR-002)**: Follows OpenRegister REST conventions —
  resource CRUD with `404` for unknown ids and `201` on explicit create. The
  Git-discovery endpoints MUST resolve the caller's credentials from
  configuration and MUST NOT leak repositories/files the caller cannot access
  (no cross-account enumeration). The portability file format itself remains
  governed by the existing register-portability requirement.

## Acceptance Criteria

- [x] `ConfigurationController` and `ConfigurationsController` carry `@spec data-import-export#...` annotations pointing at this requirement.
- [x] Configuration CRUD returns `404` for unknown ids and `201` on explicit create; `patch` applies a partial update via the `update` write path.
- [x] `checkVersion`/`preview`/`enrichDetails` operate against the configured remote source; the GitHub/GitLab discovery endpoints list only files the caller can access.
- [x] `export`/`import` continue to defer to the existing register-portability requirement (not redefined here).
- [x] `openspec validate retrofit-2026-05-25-bw2-ctrl-2 --strict` passes.
