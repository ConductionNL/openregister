## ADDED Requirements

### Requirement: Pluggable evidence-source provider interface and registry
OpenRegister SHALL define an `EvidenceSourceProvider` interface and a registry through which a leaf
app registers evidence-harvest sources (e.g. OpenConnector-backed sources), so that the set of
sources is extensible without modifying OpenRegister core (ADR-019). Each provider SHALL identify
itself with a stable `sourceId` and SHALL harvest evidence items for a given data-subject-request
case. OpenRegister core MUST NOT hard-code the list of sources; a source that is not registered
MUST NOT contribute evidence.

#### Scenario: A registered provider contributes evidence
- **WHEN** a leaf app registers an `EvidenceSourceProvider` and a harvest is triggered for a case
- **THEN** the provider's harvested items MUST be considered for that case
- **AND** a source that is not registered MUST NOT contribute any evidence item

#### Scenario: Providers are discovered through the registry, not hard-coded
- **WHEN** the harvest service enumerates sources for a case
- **THEN** it MUST enumerate only the registered providers from the registry
- **AND** adding a new source MUST NOT require a change to OpenRegister core code

### Requirement: Async content-hash-deduplicated evidence collection with per-item status
OpenRegister SHALL collect evidence asynchronously from the registered providers and record each
item onto the case's declared `evidence` sub-collection through `ObjectService` (RBAC +
multitenancy), never via a custom Entity/Mapper. Each stored evidence item MUST carry its
`sourceId`, a `contentHash`, and a per-item collection `status`. Collection MUST deduplicate by
`contentHash`: an item whose `contentHash` already exists on the case MUST NOT be appended a second
time, so re-running a harvest is idempotent. Each attach MUST be recorded in the case's immutable
hash-chained audit trail (`AuditTrailMapper`).

#### Scenario: Harvested items are stored with source, hash, and status
- **WHEN** a harvest collects an item from a provider
- **THEN** the stored evidence item MUST carry its `sourceId`, its `contentHash`, and a per-item `status`
- **AND** the attach MUST be recorded in the case's immutable audit trail

#### Scenario: Re-running a harvest does not duplicate evidence
- **WHEN** a harvest produces an item whose `contentHash` already exists on the case
- **THEN** the item MUST NOT be appended again
- **AND** the case's evidence sub-collection MUST contain exactly one item for that `contentHash`

#### Scenario: A slow or failing source is visible and re-runnable
- **WHEN** a provider fails or has not yet returned during an async harvest
- **THEN** the affected evidence items MUST reflect a non-collected `status` on the case
- **AND** a subsequent harvest MUST be able to complete the collection without duplicating already-collected items

@e2e A steward triggers evidence collection on a case, sees items attached with source/hash/status, and re-triggers collection without producing duplicate evidence.
