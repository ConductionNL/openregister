# consolidated-organisation Specification (delta)

---
status: proposed
---

## Purpose delta

OpenRegister's `Organisation` becomes the single record describing a party:
the tenant it already was, plus the identity and relationship facets the leaf
apps each kept a private copy of. A merged-away organisation resolves to its
survivor before its UUID is used as a tenant scope.

## ADDED Requirements

### Requirement: The organisation row carries the identity facet (REQ-ORG-101)

The organisation record MUST carry the statutory and presentational identifiers
that identify the party: `type`, `summary`, `oin`, `tooi`, `rsin`, `kvk`,
`pki` and `image`. All MUST be optional, so an organisation that is only ever a
tenant is unaffected.

`type` MUST be a discriminator over `organisation`, `government`, `vendor`,
`collaboration`, `department`, defaulting to `organisation`. It records which
facet an operator cares about so a UI can present a vendor differently from a
municipality. It MUST NOT be an authorization input: per ADR-002 Rule 1 the
UUID is the only tenant key, and a type-based check would create a second one.

#### Scenario: A publisher record needs no separate store

- **GIVEN** an organisation representing a municipality
- **WHEN** its OIN, TOOI, RSIN, KvK and logo are recorded
- **THEN** they are stored on the organisation row itself
- **AND** the same UUID identifies the party as both a tenant and a publisher.

#### Scenario: The identity facet is optional

- **GIVEN** an organisation created only as a tenant
- **WHEN** it is persisted with no statutory identifiers
- **THEN** the write succeeds and every identity field reads back as null.

### Requirement: A merged organisation resolves to its survivor (REQ-ORG-102)

The organisation record MUST carry `registration_status`
(`concept|submitted|registered|rejected|merged`), `merged_into` and
`merged_at`. `merged_into` is authorization-bearing, not a display hint: a
merge changes which UUID is authoritative, so an organisation that has been
merged away MUST stop resolving as a tenant. If it kept resolving, every query
scoped to it would read the survivor's data under a boundary that no longer
applies.

Resolution MUST follow the chain to the surviving organisation. The walk MUST
be bounded and cycle-guarded, and on a cycle, an over-long chain, or an
unresolvable link it MUST return the last UUID it actually loaded — never null
and never a UUID it did not verify. A resolver that failed open would hand the
caller an unscoped identifier, which is the failure this requirement exists to
prevent.

#### Scenario: A merge chain resolves to the survivor

- **GIVEN** organisation A merged into B, and B merged into C
- **WHEN** a caller resolves A
- **THEN** C is returned.

#### Scenario: A cycle does not hang and does not fail open

- **GIVEN** organisation A merged into B and B merged back into A
- **WHEN** a caller resolves A
- **THEN** resolution stops on a real, loadable organisation
- **AND** the cycle is logged as a data defect.

#### Scenario: An unresolvable link keeps the last verified UUID

- **GIVEN** organisation A whose `merged_into` names a UUID that does not exist
- **WHEN** a caller resolves A
- **THEN** A's own UUID is returned rather than the dangling one.

### Requirement: Declared field types are keyed by property name (REQ-ORG-103)

The entity's registered field types MUST be keyed by PROPERTY name, not column
name. `Entity::__call()` resolves a setter to `lcfirst(substr($method, 3))`,
and `Entity::fromRow()` maps a column to its property before looking the type
up; a snake_case key therefore matches nothing and the declared cast silently
never runs.

Reading an organisation row MUST hydrate every datetime column into a
`DateTime`. While the types were keyed by column name, `fromRow()` assigned the
raw string onto a `?DateTime` typed property and raised a `TypeError`, making
every read of a row with a lifecycle timestamp a 500.

#### Scenario: A database row hydrates its timestamps

- **GIVEN** a row carrying `provisioned_at`, `suspended_at`, `deprovisioned_at`
  and `merged_at` as database strings
- **WHEN** the row is hydrated into an organisation
- **THEN** each is a `DateTime` preserving the stored instant.

### Requirement: The tenancy columns the entity already declared exist (REQ-ORG-104)

The organisation table MUST have columns for `groups`, `storage_quota`,
`bandwidth_quota` and `request_quota`. The entity has declared all four since
before this change, but no migration ever created them: the `groups` migration
sorts before the migration that creates the table, so its `hasTable` guard is
permanently false, and the quota columns were copy-pasted from `Application`
and only ever created on `openregister_applications`.

Because `QBMapper::update()` builds its SET list from `getUpdatedFields()`, a
declared field with no column is an SQL error at write time rather than a
no-op, so tenant provisioning and every quota update were unconditionally
failing.

#### Scenario: Provisioning a tenant persists its groups

- **WHEN** a tenant is provisioned and its Nextcloud groups are set
- **THEN** the write succeeds and the groups read back.

#### Scenario: Quotas can be updated through the API

- **WHEN** `storageQuota`, `bandwidthQuota` and `requestQuota` are updated on an
  organisation
- **THEN** the write succeeds rather than returning a 500.

### Requirement: The schema change is additive (REQ-ORG-105)

The migration MUST be additive only: every column nullable or defaulted, no
existing row rewritten, and each column and index added only when absent so the
step is re-runnable. Identifiers already written into stored data are frozen —
any later backfill of the leaf apps' records MUST preserve their existing
uuid/slug rather than minting new ones.

#### Scenario: Re-running the migration changes nothing

- **GIVEN** an instance where the migration already ran
- **WHEN** it runs again
- **THEN** no column or index is added a second time and no row is modified.

### Requirement: A leaf app's organisations are adopted, not re-created (REQ-ORG-106)

Adopting a leaf app's organisation objects into the OpenRegister Organisation
MUST preserve each row's existing uuid, because references to it are stored in
places no migration can reach.

The idempotency key MUST be the uuid, never the slug and never the name. A leaf
row is free to carry no slug at all, and two rows sharing a name are routine, so
a name-derived key would skip the second row as already migrated and silently
merge two distinct legal entities.

Where the same legal entity already exists in OpenRegister under a different
uuid, the rows MUST NOT be collapsed into one. The adopted row is created and
pointed at the existing one through `mergedInto`, so both uuids keep resolving
and the merge is a fact recorded on a row rather than data thrown away. Matching
MUST be on a legal identifier, in the order OIN, RSIN, KVK, and MUST NOT be on a
name. Among several matches the lowest id is canonical, so a repeated run
chooses the same survivor.

A leaf property the Organisation entity does not declare MUST be reported before
the write. OpenRegister discards an undeclared property and answers 200 with the
object, so an adoption that loses fields is indistinguishable from one that did
not.

#### Scenario: An adopted organisation keeps its uuid

- **GIVEN** a leaf organisation object with uuid `abc`
- **WHEN** it is adopted
- **THEN** the resulting Organisation carries uuid `abc`.

#### Scenario: A second run adopts nothing

- **GIVEN** an instance where the adoption already ran
- **WHEN** it runs again
- **THEN** every row is skipped as already adopted and nothing is written.

#### Scenario: The same OIN records a merge rather than collapsing

- **GIVEN** an existing organisation carrying OIN `00000001002220647000`
- **AND** a leaf row carrying the same OIN under a different uuid
- **WHEN** the leaf row is adopted
- **THEN** it is created with its own uuid and `mergedInto` set to the existing
  organisation's uuid.

#### Scenario: Two organisations sharing only a name are not merged

- **GIVEN** two organisations with the same name and no shared legal identifier
- **WHEN** one is adopted
- **THEN** no merge is recorded.

#### Scenario: Properties with no column are named before the write

- **GIVEN** a leaf schema carrying a property the Organisation entity does not
  declare
- **WHEN** the adoption runs
- **THEN** that property is reported as one that will not be carried over.
