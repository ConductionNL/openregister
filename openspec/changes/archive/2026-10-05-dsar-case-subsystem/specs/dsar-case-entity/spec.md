## ADDED Requirements

### Requirement: Persisted data-subject-request case entity
OpenRegister SHALL persist a data-subject request as a stateful **case** object of the
`dataSubjectRequest` schema in the `data-subject-requests` register, extending the existing
fulfilment-only schema with case-management fields. The case SHALL carry, in addition to the
existing `subjectId`/`subjectType`/`type`/`status`/`receivedAt`/`dueAt`/`extendedUntil` fields: a
`handler` (the assigned handler identifier), a `closedAt` timestamp, a `dpiaRequired` boolean
complexity flag, retention stamps (`retentionWindow`, `retainUntil`, `purgedAt`), denial fields
(`denialGround`, `regulatorReference`), and evidence + redaction sub-collections. Every added
property MUST declare a human-friendly English `title` and a `description` (ADR-011). All added
properties MUST be optional so the extension is non-breaking. The case MUST be read and written
through `ObjectService` under RBAC and multitenancy scoping; it MUST NOT introduce a custom
Entity/Mapper (ADR-001).

#### Scenario: Case carries handler assignment and case timestamps
- **WHEN** a data-subject-request case is created and assigned to a handler
- **THEN** the persisted object MUST hold the `handler`, `receivedAt`, `dueAt`, and (once closed) `closedAt` values
- **AND** the object MUST be retrievable through `ObjectService` scoped to the caller's RBAC and tenant

#### Scenario: DPIA flag is recorded on the case
- **WHEN** a case is flagged as requiring a DPIA
- **THEN** the persisted object MUST carry `dpiaRequired = true`

#### Scenario: Added properties are optional and non-breaking
- **WHEN** the extended schema is imported over the existing `dataSubjectRequest` schema
- **THEN** no existing required property MUST be removed or renamed
- **AND** every added case-management property MUST be optional so pre-existing objects remain valid

#### Scenario: Every added property is human-labelled
- **WHEN** the extended schema is rendered in a create/edit form or a list
- **THEN** each added property MUST show its `title` as the field label and its `description` as help text (no raw camelCase key leaks)

### Requirement: Denial-workflow fields
The case SHALL carry a config-driven `denialGround` enum property and a `regulatorReference`
string property that together record a denial. `denialGround` SHALL be an enumeration of generic
ground keys (jurisdiction-specific wording is Phase-2 policy-pack data, not embedded here). The
`regulatorReference` SHALL be recordable only as part of the denial workflow. These fields carry
no behaviour on their own; the mandatory-gate behaviour is specified in the case-lifecycle
capability.

#### Scenario: A denied case records ground and regulator reference
- **WHEN** a case is refused under a documented ground
- **THEN** the persisted object MUST carry a `denialGround` value drawn from the configured enum and a `regulatorReference` value

#### Scenario: Denial ground is a config-driven enum, not hard-coded wording
- **WHEN** the `denialGround` property is defined in the schema
- **THEN** its allowed values MUST be generic ground keys declared as schema config
- **AND** no jurisdiction-specific statutory wording MUST be baked into the schema

### Requirement: Evidence and redaction sub-collections declared on the case
The case SHALL declare an `evidence` sub-collection and a `redactions` sub-collection as schema
properties. Each evidence item SHALL carry at least a `sourceId`, a `contentHash` (used downstream
for deduplication), and a per-item `status`. Each redaction entry SHALL carry the redacted
`field`, a `before` value, an `after` value, and the `ground`. This change declares only the
**shape** of these sub-collections; the imperative evidence-harvest and redaction-write behaviour
is delivered by the successor `dsar-case-engine` change. The redaction entry SHALL be distinct
from the erase-time pseudonymise already performed by `DataSubjectRequestService`.

#### Scenario: Evidence item shape supports content-hash dedup
- **WHEN** an evidence item is stored on a case
- **THEN** the item MUST carry a `sourceId`, a `contentHash`, and a `status`

#### Scenario: Redaction entry records before/after and ground
- **WHEN** a redaction entry is stored on a case
- **THEN** the entry MUST carry the `field`, its `before` value, its `after` value, and the `ground`
- **AND** the redaction entry MUST be distinct from an erase-time pseudonymise record
