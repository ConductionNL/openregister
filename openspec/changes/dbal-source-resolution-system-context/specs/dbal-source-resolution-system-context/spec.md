# Capability: dbal-source-resolution-system-context

## Purpose

Ensure that resolving the `Source` a `dbal-source` schema is configured
against is treated as a system capability lookup gated by the schema's own
read RBAC, not as a tenant-owned read subject to the caller's active
organisation — so a schema whose backing Source is configured in a different
organisation stays servable, including under `saasMode: true` where admin
override is unconditionally disabled.

## ADDED Requirements

### Requirement: Resolving a schema's configured Source MUST NOT be filtered by the caller's active organisation (REQ-DSRSC-001)

`DbalObjectSourceProvider::resolveSource()` MUST load the `Source` named by a
schema's `x-openregister-object-source.config.sourceId` without applying the
querying user's active-organisation filter to the `Source` row. This MUST hold
regardless of `saasMode` or `adminOverride` configuration. Ordinary
`SourceMapper` callers (Sources admin CRUD, listing) MUST continue to apply the
organisation filter unchanged — only the schema-referenced system lookup is
exempt.

#### Scenario: A cross-organisation Source still resolves under SaaS mode

- **GIVEN** a `dbal-source` schema whose config names a Source belonging to
  organisation A
- **AND** the querying admin's active organisation is B
- **AND** multitenancy config is `{"saasMode": true, "adminOverride": true}`
  (admin override disabled by SaaS mode)
- **WHEN** the schema's objects are listed
- **THEN** `resolveSource()` MUST return the Source and the provider MUST
  return the external rows, not an empty result
- **@e2e** exclude requires a live external database + multi-org fixture;
  asserted by `DbalObjectSourceProviderTest` with a `SourceMapper` stub whose
  `findForSystem()` returns a cross-org Source

#### Scenario: Non-system Source reads remain organisation-filtered

- **GIVEN** an admin user with active organisation B
- **WHEN** they list Sources through the ordinary `SourceMapper::findAll()` /
  `find()` path (e.g. the Sources admin UI)
- **THEN** a Source belonging to organisation A MUST NOT be returned, exactly
  as before this change
- **@e2e** exclude covered by existing `SourceMapper` / `MultiTenancyTrait`
  tenant-isolation tests, unmodified

### Requirement: The system lookup MUST remain gated by the schema's own read RBAC (REQ-DSRSC-002)

Removing the organisation filter from Source resolution MUST NOT weaken access
control: a caller who is denied read access to the schema itself MUST still be
denied before `resolveSource()` (and the external database) is ever consulted.
`ObjectService::paginateObjectSource()` MUST continue to enforce
`checkPermission(schema, 'read', …)` before invoking the provider.

#### Scenario: A user without schema read access is still denied

- **GIVEN** a user lacking read authorization on a `dbal-source` schema
- **WHEN** they request the schema's objects
- **THEN** the request MUST fail with the same `NotAuthorizedException` a
  native schema raises, and the external database MUST NOT be queried
- **@e2e** exclude covered by existing `paginateObjectSource` / read-access-parity
  tests, unmodified by this change
