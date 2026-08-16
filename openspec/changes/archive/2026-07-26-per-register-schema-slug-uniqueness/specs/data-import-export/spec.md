---
status: draft
---
# Capability: `data-import-export`

## Purpose

Configuration-import schema resolution currently decides "update an existing
schema" vs. "create a new one" too broadly (application-scoped, or fully global
without an application id), which lets an importing register silently bind to —
or be shadowed by — a same-slug schema it does not own. This delta narrows
schema-slug uniqueness to be enforced **within a single register's schema set**,
at the service layer, and removes the DB-level unique index that enforced a
coarser (organisation+application) scope.

## ADDED Requirements

### Requirement: A schema slug is unique within a single register's schema set

The system SHALL treat a schema slug as unique **within the set of schemas a
single register references**, not globally and not merely per application. Two
**distinct** schema rows MUST NOT both carry the same slug while both are
referenced by the same register's `schemas` id list. Two different registers —
whether owned by the same application or different applications — MAY each own
a distinct schema row with the same slug. A schema referenced by more than one
register (the many-to-many case) remains a single row; this requirement does
not restrict how many registers may share one schema.

This invariant is enforced at the service layer
(`ImportHandler::importSchema()`'s resolve-before-create logic), not by a
database unique index, because a schema's register membership is a
many-to-many relationship (no `register_id` column on `openregister_schemas`)
and cannot be expressed as a single-table unique constraint.

#### Scenario: Two registers each import a schema with the same slug
- **GIVEN** register A does not (yet) reference any schema with slug
  `automation`
- **AND** register B, in a separate import, also does not reference any schema
  with slug `automation`
- **WHEN** register A's configuration imports a schema with slug `automation`
- **AND** register B's configuration, separately, also imports a schema with
  slug `automation`
- **THEN** two distinct schema rows are created, one attached to register A and
  one attached to register B
- **AND** neither row is reused by, bound to, or overwritten by the other
  register's import

#### Scenario: A schema shared across multiple registers is untouched
- **GIVEN** a schema is already referenced by both register A's and register
  B's `schemas` id list (the many-to-many case)
- **WHEN** register A re-imports its configuration, which still declares that
  same schema slug
- **THEN** the existing shared schema row is updated in place (per the existing
  version-gate / content-diff logic)
- **AND** register B's reference to that same row is unaffected
- **AND** no duplicate schema row is created

### Requirement: Configuration import resolves a schema by slug within the target register's existing schema set

The system SHALL resolve, during `ImportHandler::importFromJson()`, an existing schema for each imported slug by matching only against the schema ids already attached (pre-import) to the register(s) this import's `components.registers.*.schemas` declares for that slug — via `SchemaMapper::findBySlugInIds()`.

When a match is
found, the existing schema MUST be updated in place (subject to the existing
version-gate and force-flag semantics). When no match is found within that
scope, a **new** schema MUST be created and attached to the importing
register(s), even when a schema with the same slug already exists elsewhere
(owned by a different register or application) — the importer MUST NOT bind to
or overwrite a schema outside the target register's own scope. A schema slug
with no register context in the current import (not declared by any register in
`components.registers.*.schemas`) retains the previous application-scoped (or,
without an application id, organisation-scoped) resolution as a fallback.

#### Scenario: Importing a slug owned by a different register creates the importer's own schema
- **GIVEN** a schema with slug `automation` already exists, referenced only by a
  different register's `schemas` id list
- **WHEN** the importing register's configuration imports its own schema
  definition with slug `automation`
- **THEN** a new schema is created and attached to the importing register
- **AND** the pre-existing `automation` schema (and the register that
  references it) is left completely unchanged

#### Scenario: Re-importing the same slug into the same register updates in place
- **GIVEN** a register already references a schema with slug `automation` (id
  N), created by a prior import
- **WHEN** the same register's configuration is re-imported with an updated
  definition for slug `automation`
- **THEN** schema id N is updated (not duplicated)
- **AND** the register's `schemas` id list still contains exactly one entry for
  that slug (id N)

### Requirement: No database-level uniqueness constraint scopes schema slugs

`openregister_schemas` SHALL NOT carry a database-level unique index over any
combination of `organisation`, `application`, and `slug`. The
`schemas_org_app_slug_unique` index (added by `Version1Date20260723000000`) MUST
be dropped by a migration that is idempotent (checks index existence before
dropping) and does not fail on, or modify, existing data. No replacement unique
index is added; slug uniqueness within a register's schema set is enforced
exclusively by the service-layer resolution described above. Register slug
uniqueness (`registers_org_app_slug_unique` on `openregister_registers`) is
unaffected by this requirement.

#### Scenario: Migration drops the coarse unique index idempotently
- **GIVEN** `openregister_schemas` carries the `schemas_org_app_slug_unique`
  index
- **WHEN** the migration runs
- **THEN** the index no longer exists on `openregister_schemas`
- **AND** running the migration again (index already absent) is a no-op that
  does not error
- **AND** no existing schema row is modified or deleted by the migration
