## ADDED Requirements

### Requirement: Field-level redaction write path with before/after and ground
OpenRegister SHALL provide a write path that applies a field-level redaction to a data-subject
request case: given a target `field`, it SHALL record a `redactions` entry on the case carrying the
redacted `field`, its `before` value, its `after` value, and the redaction `ground`, writing
through `ObjectService` under RBAC + multitenancy. The redaction MUST be recorded in the case's
immutable hash-chained audit trail (`AuditTrailMapper`) pinned to the DSAR processing activity.

#### Scenario: A redaction records before/after and ground
- **WHEN** a handler applies a redaction to a case field
- **THEN** the case MUST carry a `redactions` entry with the `field`, its `before` value, its `after` value, and the `ground`
- **AND** the redaction MUST be recorded in the case's immutable audit trail

### Requirement: Redaction is distinct from erase-time pseudonymise
The redaction write path SHALL be distinct from the erase-time pseudonymise already performed by
`DataSubjectRequestService::erase(mode=pseudonymise)`. Applying a redaction MUST NOT invoke the
erase pseudonymise path, and a redaction entry MUST be distinguishable from an erase pseudonymise
record. Redaction is a pre-bundle field-level action recording its own before/after; it does not
replace or trigger statutory erasure.

#### Scenario: Redaction does not trigger erase pseudonymise
- **WHEN** a handler applies a field-level redaction
- **THEN** the erase-time pseudonymise path MUST NOT be invoked
- **AND** the resulting redaction entry MUST be distinguishable from an erase pseudonymise record

@e2e A steward redacts a field on a case, and the case shows a redactions entry with before/after and ground in the audit trail, without any statutory erasure being triggered.
