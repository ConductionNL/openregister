# Archival Destruction Workflow — delta

## MODIFIED Requirements

### Requirement: Archival approve route executes destruction

Approving a destruction list through `POST /api/archival/destruction-lists/{id}/approve`
MUST persist the approval, MUST queue `DestructionExecutionJob` with the argument key
that job reads (`destructionListUuid`), and MUST record the approving archivist under
the canonical `userId` key so the resulting *verklaring van vernietiging* names them.

The route previously satisfied none of these: the approval was returned but never
written back, the job was queued under a key it does not read (so it returned early on
every run and destroyed nothing), and approvals were recorded as `approvedBy` while the
certificate generator projects `array_column($approvals, 'userId')` — which would have
produced a certificate with an empty approver list even once the job ran.

#### Scenario: Approval queues an execution job the job can act on

- **WHEN** an archivist approves a destruction list with uuid `list-uuid-42`
- **THEN** `DestructionExecutionJob` MUST be queued with `['destructionListUuid' => 'list-uuid-42']`
- **AND** the list MUST be persisted with status `approved`

#### Scenario: The destruction certificate names the approving archivist

- **WHEN** archivist `archivaris-1` approves a list of 3 objects and the execution job generates the certificate
- **THEN** the certificate's `approvedBy` MUST be `['archivaris-1']` — never empty
- **AND** `totalDestroyed` MUST be `3`
- **AND** `groupedBySchema` MUST count the destroyed objects per schema + selectielijst classificatie
- **AND** `selectielijstBron` MUST carry the selectielijst source references
- **AND** `complianceStatement` MUST cite the Archiefwet 1995

## REMOVED Requirements

### Requirement: ArchivalService generates destruction lists

**Reason:** Superseded. `ArchivalService` had zero references anywhere in `lib/` — no DI
registration, controller, route, or job. Destruction lists are produced in production by
`RetentionService::createDestructionList()`, invoked from the registered
`DestructionCheckJob` cron. The class is deleted; keeping it meant the codebase
advertised the Archiefwet destruction-list capability twice and implemented it once.

**Migration:** None. The live path is unchanged.

### Requirement: DestructionService generates the destruction certificate

**Reason:** Superseded. `Archival\DestructionService::generateCertificate()` and its only
would-be caller `executeDestruction()` had zero callers. The real executor,
`DestructionExecutionJob`, does not use `DestructionService` — it generates and persists
the certificate via `RetentionService::generateDestructionCertificate()`. Both methods
are deleted.

**Migration:** None. Certificates continue to be produced by `DestructionExecutionJob`.
