# Spec: data-integrity-relations

**Status:** proposed
**Scope:** openregister
**Tier:** or-core-extensions
**Depends on:** referential-integrity (extended), reference-existence-validation (extended), linked-entity-types (referenced precedent)

## Motivation (context for the delta)

Specter's intelligence pipeline surfaced four data-integrity gaps in
OR's spec backlog (unique-constraints: 189 mentions, link-existing-columns:
117, upsert: 29, cross-project-relations: 28). Cross-checking against
the existing OR spec library:

- `referential-integrity` already covers `$ref`-deletion behaviour
  (CASCADE / SET_NULL / SET_DEFAULT / RESTRICT / NO_ACTION).
- `reference-existence-validation` already covers `validateReference`
  save-time existence checks.
- `linked-entity-types` already covers cross-app NC entity linking
  (mail / contacts / deck / etc.) — a different problem from
  cross-register OR `$ref`.

What is **not** in the existing specs:
- Unique constraints on properties or property tuples (database-level
  enforcement).
- Upsert semantics (find-by-key-or-create with deterministic
  conflict resolution).
- Cross-register `$ref` resolution — the existing `$ref` grammar
  assumes the target schema is in the same register; cross-register
  references are silently ignored or rejected depending on resolver
  path.

This spec adds those three capabilities as a `## MODIFIED Requirements`
delta on `referential-integrity` (the cross-register block) and as
`## ADDED Requirements` blocks for the other two. Existing
`referential-integrity` machinery (CASCADE / SET_NULL / etc.) is
unchanged and is reused by the new cross-register form.

## MODIFIED Requirements

### Requirement: Schema properties with $ref MUST resolve across registers when the $ref carries the cross-register grammar

The `$ref` resolver MUST recognise the cross-register grammar (`{register-slug}/{schema-slug}`) and resolve the target schema in the named register. This requirement extends `referential-integrity` Requirement 1
("Schema properties with $ref MUST support configurable onDelete
behavior"). The existing onDelete actions (CASCADE / SET_NULL /
SET_DEFAULT / RESTRICT / NO_ACTION) remain unchanged. What is
extended is the resolution grammar for `$ref`: in addition to the
existing forms (numeric ID, UUID, slug, JSON-Schema path, full URL —
all assumed to target a schema in the same register as the source),
`$ref` SHALL accept the cross-register form `"{register-slug}/{schema-slug}"`.
`SchemaReferenceResolver::resolveSchemaReference()` MUST recognise
the slash separator and resolve the target schema in the named
register; if the register or schema does not exist, the resolver
MUST return the same `null` result it returns for any unresolvable
reference today. All onDelete behaviour from the original spec
MUST apply identically to cross-register references — CASCADE
across registers is permitted; SET_NULL across registers is
permitted; RESTRICT across registers is permitted.

#### Scenario: Cross-register $ref resolves to a schema in a different register

- **GIVEN** schema `Order` in register `commerce` has property `customer`
  with `$ref: "crm/Person"` and `onDelete: SET_NULL`
- **AND** register `crm` contains schema `Person`
- **WHEN** an `Order` is saved with `customer: "<existing-person-uuid>"`
- **THEN** `SchemaReferenceResolver::resolveSchemaReference("crm/Person")`
  MUST return the `Person` schema instance from register `crm`
- **AND** the save MUST succeed
- **AND** the `_relations` metadata column on `Order` MUST record
  the cross-register reference with both register-id and schema-id

#### Scenario: Cross-register CASCADE removes dependents across registers

- **GIVEN** schema `Person` in register `crm`
- **AND** schema `Order` in register `commerce` references `Person`
  via `$ref: "crm/Person"` with `onDelete: CASCADE`
- **AND** 5 Orders reference a specific Person
- **WHEN** the Person is deleted
- **THEN** all 5 Orders MUST also be soft-deleted via the existing
  `ReferentialIntegrityService::applyCascade()` path
- **AND** the `DeletionAnalysis.cascadeTargets` MUST include each
  cascaded Order with its register-id annotated

#### Scenario: Cross-register $ref to an unresolvable target falls back to NO_ACTION

- **GIVEN** schema `Order` has property `customer` with `$ref: "deleted-register/Person"`
- **WHEN** `resolveSchemaReference()` is called
- **THEN** the resolver MUST return `null` (target register removed)
- **AND** the property MUST behave as if `onDelete: NO_ACTION` was
  declared (the broken reference is the caller's responsibility,
  per existing eventual-consistency semantics)
- **AND** a warning MUST be logged once per request via
  `LoggerInterface::warning()` naming the unresolvable target

#### Scenario: Same-register $ref grammar continues to work unchanged

- **GIVEN** schema `Order` has property `assignee` with `$ref: "person"`
  (slug form, same register, no slash)
- **WHEN** the resolver is called
- **THEN** the existing single-register resolution path MUST be used
- **AND** the result MUST be identical to the behaviour before this
  delta lands (backwards compatibility)

### Requirement: validateReference MUST honour the cross-register $ref grammar

The `validateReference: true` flag MUST honour the cross-register `$ref` grammar. This requirement extends `reference-existence-validation`
Requirement 1 ("Schema properties MUST support a validateReference
configuration"). The `validateReference: true` flag, when applied
to a property whose `$ref` carries the cross-register form
(`{register-slug}/{schema-slug}`), MUST perform the existence check
against the cross-register target via the same
`MagicMapper::find()` path used today (with `_rbac: false` and
`_multitenancy: false`). The error message format defined in the
original spec ("Referenced object 'X' not found in schema 'Y' for
property 'Z'") MUST extend to include the register slug: "Referenced
object 'X' not found in schema 'Y' of register 'R' for property 'Z'."

#### Scenario: Cross-register validateReference catches nonexistent target

- **GIVEN** schema `Order` has property `customer` with
  `$ref: "crm/Person"` and `validateReference: true`
- **WHEN** an `Order` is saved with `customer: "nonexistent-uuid"`
- **AND** no `Person` with that UUID exists in register `crm`
- **THEN** the save MUST fail with HTTP 422
- **AND** the error message MUST be exactly:
  `Referenced object 'nonexistent-uuid' not found in schema 'Person' of register 'crm' for property 'customer'`

#### Scenario: Cross-register validateReference with existing target succeeds

- **GIVEN** same schema as above
- **AND** a `Person` with UUID `<existing-uuid>` exists in register `crm`
- **WHEN** an `Order` is saved with `customer: "<existing-uuid>"`
- **THEN** the save MUST succeed

## ADDED Requirements

### Requirement: REQ-DIR-001 — Schemas SHALL declare unique constraints on individual properties via a `unique: true` annotation

Schema properties MUST accept a `unique: true` boolean. When set,
`MagicMapper::buildTableColumnsFromSchema()` MUST emit a
`UNIQUE INDEX` clause on the corresponding magic-table column at
schema-import time. Enforcement happens at write time via the
database's native unique-constraint mechanism — a duplicate write
MUST be rejected by the database, not by a pre-write PHP check
(per design.md Decision D3, race-safe + queryable). The error
surface MUST translate the database constraint violation to HTTP
409 Conflict with a message naming the property and the conflicting
value.

#### Scenario: Property declared unique enforces uniqueness at the storage layer

- **GIVEN** schema `Organisation` has property `kvkNumber` with
  `unique: true`
- **WHEN** an `Organisation` is saved with `kvkNumber: "12345678"`
- **AND** another `Organisation` already exists with the same value
- **THEN** the database MUST reject the write via the
  `UNIQUE INDEX` constraint
- **AND** the API MUST return HTTP 409 Conflict with the message
  `Duplicate value '12345678' for unique property 'kvkNumber'`

#### Scenario: Schema-import builds the UNIQUE INDEX on first registration

- **GIVEN** a schema with `kvkNumber: { type: "string", unique: true }`
- **WHEN** `ConfigurationService::importFromApp()` registers the schema
- **THEN** `MagicMapper::buildTableColumnsFromSchema()` MUST emit
  DDL containing `UNIQUE INDEX ix_<table>_kvkNumber (kvkNumber)`
- **AND** the index MUST be visible via
  `SHOW INDEXES FROM <magic-table>`

#### Scenario: Removing the unique annotation drops the index on next migration

- **GIVEN** a schema with `kvkNumber: { unique: true }` and an existing
  `UNIQUE INDEX` in place
- **WHEN** the schema is updated to remove the `unique` annotation
- **AND** the repair step re-runs
- **THEN** `MagicMapper` MUST drop the index via `ALTER TABLE … DROP INDEX`
- **AND** subsequent duplicate writes MUST succeed

#### Scenario: Existing data is checked before applying the constraint

- **GIVEN** an existing magic-table with rows that violate a newly
  declared unique constraint
- **WHEN** the repair step attempts to add the `UNIQUE INDEX`
- **THEN** the DDL MUST fail at the storage layer
- **AND** the repair step MUST surface the conflict via a structured
  log entry naming the column and the duplicate values, and abort
  the schema-import for that schema (other schemas in the same import
  MUST proceed)

### Requirement: REQ-DIR-002 — Schemas SHALL declare composite unique constraints via a `uniqueIndex: [<property-list>]` schema-level annotation

The system MUST treat composite unique constraints with the same race-safe / queryable enforcement as REQ-DIR-001 single-property uniques. A schema MAY declare composite unique constraints by adding a `uniqueIndex` array at the schema root. Each entry is itself an
array of property names; together those properties form a
composite unique key. Multiple `uniqueIndex` entries MAY be
declared. Enforcement is identical to `REQ-DIR-001`: the database
emits the `UNIQUE INDEX`, the database rejects duplicates, the API
surfaces HTTP 409.

#### Scenario: Composite unique constraint rejects duplicate pairs

- **GIVEN** schema `Member` has
  `uniqueIndex: [["organisationId", "email"]]`
- **WHEN** a `Member` is saved with
  `organisationId: "org-1", email: "jan@example.nl"`
- **AND** another `Member` already exists with the same pair
- **THEN** the database MUST reject the write
- **AND** the API MUST return HTTP 409 with the message
  `Duplicate composite value for unique index (organisationId, email)`

#### Scenario: Same email under different organisation succeeds

- **GIVEN** same schema as above
- **AND** a `Member` exists with
  `organisationId: "org-1", email: "jan@example.nl"`
- **WHEN** a `Member` is saved with
  `organisationId: "org-2", email: "jan@example.nl"`
- **THEN** the save MUST succeed (composite key differs)

#### Scenario: Multiple composite indices coexist

- **GIVEN** schema `Member` has
  `uniqueIndex: [["organisationId", "email"], ["organisationId", "personalCode"]]`
- **WHEN** the schema is imported
- **THEN** both `UNIQUE INDEX` clauses MUST be created
- **AND** a duplicate on either pair MUST be rejected independently

### Requirement: REQ-DIR-003 — The system SHALL support upsert via a `?upsert=true` query parameter on object create

The system MUST expose upsert semantics on object create. `POST /api/objects/{register}/{schema}?upsert=true` with a JSON body
MUST perform a find-or-create operation keyed on the schema's
declared upsert key (per REQ-DIR-004). If a matching object exists,
its properties MUST be merged with the incoming body (per
REQ-DIR-005). If no matching object exists, a new object MUST be
created. The response MUST include an `_upserted` metadata field
with value `"created"` or `"updated"` so the caller can distinguish.

#### Scenario: Upsert creates a new object when no match exists

- **GIVEN** schema `Organisation` with `upsertKey: ["kvkNumber"]`
- **AND** no `Organisation` exists with `kvkNumber: "99999999"`
- **WHEN** `POST /api/objects/commerce/organisation?upsert=true` is
  called with body `{ "kvkNumber": "99999999", "name": "ACME BV" }`
- **THEN** a new `Organisation` MUST be created with both fields
- **AND** the response MUST include `_upserted: "created"`

#### Scenario: Upsert updates an existing object when match exists

- **GIVEN** same schema as above
- **AND** an `Organisation` exists with `kvkNumber: "12345678"` and
  `name: "Old Name"`
- **WHEN** `POST /api/objects/commerce/organisation?upsert=true` is
  called with body `{ "kvkNumber": "12345678", "name": "New Name" }`
- **THEN** the existing object's `name` MUST be updated to `"New Name"`
- **AND** the response MUST include `_upserted: "updated"`
- **AND** the response MUST return the same UUID as the existing object

#### Scenario: Upsert without an upsertKey declaration is rejected

- **GIVEN** schema `Note` has no `upsertKey` declared
- **WHEN** `POST /api/objects/commerce/note?upsert=true` is called
- **THEN** the response MUST be HTTP 400 with the message
  `Upsert requires the schema to declare 'upsertKey'`

### Requirement: REQ-DIR-004 — Schemas SHALL declare upsert keys via an `upsertKey: [<property-list>]` schema-level annotation

The schema MUST allow declaring an upsert key. The `upsertKey` field is an array of property names that together
form a key used to look up an existing object before deciding to
create or update. The key SHOULD be a subset of properties also
declared in a `uniqueIndex` (typically the same set), so the upsert
lookup is index-backed and race-safe. The lookup MUST use
`MagicMapper::find()` with `_rbac: false` (to avoid hiding the
matching object from the upsert) and within the calling user's
tenant scope (per existing multi-tenancy rules).

#### Scenario: upsertKey matches an existing uniqueIndex

- **GIVEN** schema `Organisation` has
  `uniqueIndex: [["kvkNumber"]]` and `upsertKey: ["kvkNumber"]`
- **WHEN** an upsert is performed with `kvkNumber: "12345678"`
- **THEN** the lookup MUST consult the `UNIQUE INDEX ix_*_kvkNumber`
- **AND** the lookup MUST complete in O(log N) time (index scan, not
  table scan)

#### Scenario: upsertKey without backing uniqueIndex is allowed but flagged

- **GIVEN** schema `Member` has `upsertKey: ["email"]` but no
  `uniqueIndex` covering `email`
- **WHEN** the schema is imported
- **THEN** the import MUST succeed (no blocking error)
- **AND** a structured WARNING MUST be logged:
  `upsertKey ["email"] on schema Member has no backing uniqueIndex; upsert may race`

### Requirement: REQ-DIR-005 — Upsert SHALL merge incoming properties into the matching object by default; replacement mode is opt-in

Upsert MUST default to merge semantics. When the upsert lookup finds a matching object, the default conflict
resolution MUST be **merge** — incoming properties overwrite
existing values for the named fields only; properties not present
in the body MUST remain unchanged on the stored object. The caller
MAY opt into **replace** semantics by adding `?upsert=true&upsertMode=replace`
— in replace mode, the matching object's properties not present in
the incoming body MUST be cleared (set to schema-declared defaults
or removed). The replace mode MUST NOT affect metadata columns
(`_uuid`, `_created`, `_owner`, `_relations`, etc.).

#### Scenario: Merge preserves untouched fields

- **GIVEN** an existing `Organisation` with
  `kvkNumber: "12345678", name: "Old", address: "Main St 1"`
- **WHEN** upsert is called with `{ kvkNumber: "12345678", name: "New" }`
  (no `upsertMode`)
- **THEN** the resulting object MUST have
  `kvkNumber: "12345678", name: "New", address: "Main St 1"`
  (address preserved)

#### Scenario: Replace clears untouched fields

- **GIVEN** same starting state as above
- **WHEN** upsert is called with `{ kvkNumber: "12345678", name: "New" }`
  and query string `?upsert=true&upsertMode=replace`
- **THEN** the resulting object MUST have `kvkNumber: "12345678", name: "New"`
  and `address` MUST be unset / cleared

#### Scenario: Metadata columns are never affected by replace

- **GIVEN** an existing object with `_owner: "alice"` and
  `_created: "2026-01-01T00:00:00Z"`
- **WHEN** upsert in replace mode is performed by user `bob`
- **THEN** the resulting object MUST retain `_owner: "alice"` and
  the original `_created` timestamp
- **AND** `_updated` MUST advance to the upsert time
- **AND** `_modifiedBy` MUST record `bob`

### Requirement: REQ-DIR-006 — Cross-register $ref SHALL be traversable in queries via the existing `_extend` parameter

Cross-register `$ref` enrichment MUST flow through the existing `_extend` enrichment path. This requirement consumes the cross-register `$ref`
resolution above and ties it to the existing `_extend` enrichment
path (per `linked-entity-types`). When a property carries a
cross-register `$ref` and the request includes
`_extend[<property>]=1`, the response MUST inline the referenced
object from the target register. Batching MUST flow through the
existing `RelationHandler` DataLoader so a list of N objects
referencing the same target register MUST produce ≤1 round-trip
to that register, not N.

#### Scenario: _extend enriches cross-register references

- **GIVEN** schema `Order` has property `customer` with
  `$ref: "crm/Person"`
- **AND** an `Order` exists with `customer: "<person-uuid>"`
- **WHEN** `GET /api/objects/commerce/order/<order-uuid>?_extend[customer]=1`
  is called
- **THEN** the response's `customer` field MUST be the full enriched
  `Person` object from register `crm` (not just the UUID)

#### Scenario: List enrichment batches cross-register lookups

- **GIVEN** 100 `Order` objects, each referencing one of 5 distinct
  `Person` UUIDs in register `crm`
- **WHEN** `GET /api/objects/commerce/order?_extend[customer]=1` is
  called
- **THEN** `RelationHandler` MUST issue exactly one batched lookup
  to register `crm` resolving all 5 UUIDs
- **AND** the response MUST inline each Order's enriched customer

### Requirement: REQ-DIR-007 — Cross-register $ref SHALL appear in the `_relations` metadata column with register attribution

Cross-register references MUST be recorded with register attribution. `MagicMapper`'s existing relation-extraction path MUST record
cross-register references in the `_relations` JSON column with the
extended shape `{registerId, schemaId, objectUuid, property}` — the
addition is `registerId` (existing intra-register relations are
implicitly current-register). This allows reverse lookups via the
existing `LinkedEntityController` (per `linked-entity-types`) to
return cross-register dependents.

#### Scenario: _relations records the source register of a cross-register ref

- **GIVEN** an `Order` in register `commerce` references
  `customer: "<person-uuid>"` via `$ref: "crm/Person"`
- **WHEN** the save completes
- **THEN** the `_relations` column on the `Order` row MUST contain
  `{ "registerId": <crm-id>, "schemaId": <person-schema-id>, "objectUuid": "<person-uuid>", "property": "customer" }`
- **AND** existing intra-register relations on other properties MUST
  retain their current shape (omitting `registerId` is permitted for
  backwards compat; consumers SHOULD treat absent `registerId` as
  "same register")

### Requirement: REQ-DIR-008 — Cross-register cascade and SET_NULL SHALL respect the deleter's RBAC scope on the target register

Cross-register cascade/SET_NULL MUST respect the deleter's RBAC scope. When an onDelete CASCADE or SET_NULL fires across registers, the
deletion / update of objects in the target register MUST be performed
with the same user context as the originating delete. If the user
lacks write permission on the target register, the cascade MUST
fail with HTTP 403, the originating delete MUST roll back per
`referential-integrity` Requirement 2 (atomic transactions), and
the error message MUST name both registers.

#### Scenario: Cross-register cascade respects user RBAC

- **GIVEN** user `alice` has delete permission on `crm.Person` but
  NOT write permission on `commerce.Order`
- **AND** an `Order` references a `Person` with CASCADE
- **WHEN** `alice` deletes the `Person`
- **THEN** the CASCADE MUST attempt to delete the `Order`
- **AND** the RBAC check MUST fail with HTTP 403
- **AND** the original `Person` deletion MUST roll back
- **AND** the error message MUST read
  `Cascade from 'crm.Person' to 'commerce.Order' blocked by RBAC: missing 'delete' on commerce.Order`
