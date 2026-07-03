## ADDED Requirements

### Requirement: Assemble and sign the export bundle
OpenRegister SHALL assemble a data-subject export bundle for a case by reusing
`DataSubjectRequestService::assembleAccessExport`, and SHALL NOT re-implement subject-data
discovery or assembly (ADR-011). The assembled bundle SHALL be signed with a PAdES-LTV signature
and SHALL carry a SHA-256 content hash so its integrity is verifiable. The assembly MUST run
through `ObjectService` under the caller's RBAC + multitenancy scope, and the bundle-generation
action MUST be recorded in the case's immutable audit trail pinned to the DSAR processing activity
(`ObjectEntity::setProcessingActivityId()`).

#### Scenario: Bundle is assembled from the existing access-export primitive
- **WHEN** a steward generates the export bundle for a case
- **THEN** the bundle contents MUST be assembled via `DataSubjectRequestService::assembleAccessExport`
- **AND** the generation MUST be recorded in the case's immutable audit trail

#### Scenario: Bundle is signed and integrity-verifiable
- **WHEN** an export bundle is generated
- **THEN** the bundle MUST carry a PAdES-LTV signature and a SHA-256 content hash
- **AND** altering the bundle bytes MUST invalidate the recorded hash

### Requirement: One-time secure download token
OpenRegister SHALL issue a single-use, time-boxed secure download token for a generated bundle. The
download endpoint MUST require the token and MUST be authenticated and case-scoped (never
`@PublicPage`). The token MUST be burned on the first successful download so it cannot be replayed;
a second attempt with the same token MUST be refused.

#### Scenario: Token permits exactly one download
- **WHEN** a valid one-time token is presented to the download endpoint
- **THEN** the signed bundle MUST be returned
- **AND** a second request with the same token MUST be refused

#### Scenario: Download requires authentication and case scope
- **WHEN** an unauthenticated caller, or a caller without access to the case, presents a token
- **THEN** the download MUST be refused

### Requirement: Regulator dossier assembly
OpenRegister SHALL assemble a regulator dossier for a case from the case's evidence sub-collection,
its redaction records, and its audit trail, so the dossier reflects what was collected, what was
redacted (with grounds), and the case history. The dossier assembly MUST read through the same
RBAC-scoped case object and MUST NOT expose data outside the caller's authorisation.

#### Scenario: Dossier reflects evidence, redactions, and history
- **WHEN** a regulator dossier is assembled for a case
- **THEN** it MUST include the case's collected evidence, its redaction records with grounds, and its audit-trail history

@e2e A steward generates a signed bundle for a case, downloads it once via the one-time token (a replay is refused), and assembles a regulator dossier that reflects the case's evidence and redactions.
