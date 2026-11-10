---
status: proposed
---

# Data Import & Export

## ADDED Requirements

### Requirement: Schema slugs are unique per application, not per organisation
The system SHALL scope schema and register slug-uniqueness by
`(organisation, application, slug)` rather than `(organisation, slug)`, so two
applications sharing one OpenRegister instance MAY each own a schema (or register)
with the same generic slug. The `Version1Date20260723000000` migration MUST replace
the `(organisation, slug)` unique indexes on `openregister_schemas` and
`openregister_registers` with `(organisation, application, slug)` indexes, and MUST
be idempotent (swap each index only when the old one is present and the new one is
absent).

#### Scenario: Two apps import a schema with the same slug
- **GIVEN** application `pipelinq` already owns a schema with slug `conversation`
- **WHEN** application `hermiq` imports its own schema with slug `conversation`
- **THEN** the database MUST accept both rows
- **AND** each schema MUST retain its own `application` (`pipelinq` and `hermiq`)
- **AND** neither schema's definition is overwritten by the other's import

### Requirement: Schema import binds only to schemas the importing app owns
When `ImportHandler::importSchema()` runs with an application context, it MUST
resolve the existing schema by `(slug, application)` — an application only ever
updates a schema it owns. If a schema with the same slug exists but is owned by a
different application, the import MUST create the importing application's own schema
(and SHOULD log the foreign owner) rather than binding to or overwriting the foreign
schema. Imports without an application context (manual/UI single imports) retain the
historical global slug behaviour.

#### Scenario: Importing a slug owned by another app creates your own schema
- **GIVEN** slug `conversation` is owned by application `pipelinq`
- **WHEN** application `hermiq` imports a schema with slug `conversation`
- **THEN** a new schema owned by `hermiq` MUST be created
- **AND** `pipelinq`'s `conversation` schema MUST be left unchanged

### Requirement: A slug resolves within its register context
When an object operation carries a register context, `ObjectService` MUST resolve a
schema slug among the schemas that register references, so a generic slug resolves to
the schema the register owns rather than an arbitrary same-slug row from another
application. When the register does not reference a schema with that slug, resolution
MUST fall back to the global lookup so register-less callers are unaffected.

#### Scenario: Saving an object resolves the app's own schema
- **GIVEN** register `hermiq` references schema `conversation` owned by `hermiq`
- **AND** another application owns a different schema with slug `conversation`
- **WHEN** a caller does `saveObject(register: 'hermiq', schema: 'conversation')`
- **THEN** the object MUST be validated and stored against `hermiq`'s `conversation`
  schema, not the other application's

### Requirement: Re-import heals a register polluted with a foreign schema
When `ImportHandler` reconciles an application's auto-created register, it MUST prune
any referenced schema id that is shadowed by a freshly-imported schema sharing the
same slug, so a register that had a foreign application's same-slug schema bound into
it before this change is cleaned up on the next import.

#### Scenario: Re-import drops a foreign shadowed schema id
- **GIVEN** register `hermiq` still lists `pipelinq`'s `conversation` schema id
- **WHEN** `hermiq` re-imports and creates its own `conversation` schema
- **THEN** the register MUST list `hermiq`'s `conversation` schema id
- **AND** the register MUST NOT list `pipelinq`'s `conversation` schema id
