## ADDED Requirements

### Requirement: Data-subject-request object model with status lifecycle
OpenRegister SHALL provide a generic `dataSubjectRequest` object model
(shippable as an OR register + schema) capturing a data-subject request under
GDPR / AVG. The object SHALL carry: a `subjectId` (the subject identifier value,
e.g. email or another identifier), an optional `subjectType`, a `type` drawn
from the GDPR rights taxonomy (`access` = art-15, `rectification` = art-16,
`erasure` = art-17, `restriction` = art-18, `portability` = art-20, `objection`
= art-21), a `status`, a `receivedAt`, and the deadline fields `dueAt` /
`extendedUntil`. The `status` field SHALL be governed by an
`x-openregister-lifecycle` annotation with initial state `received`, working
states `verifying` and `in-progress`, and final states `fulfilled`, `refused`,
`closed`. The model SHALL be jurisdiction-neutral: it SHALL NOT mandate any
Dutch-specific field (no BSN, no AP complaint reference, no FG/DPO naming).

#### Scenario: A new data-subject request starts in the received state
- **GIVEN** the `dataSubjectRequest` schema is installed
- **WHEN** a request is created with a `subjectId`, a `type` of `access`, and a `receivedAt`
- **THEN** its `status` MUST be `received`
- **AND** its `dueAt` MUST equal `receivedAt` plus one month

#### Scenario: The lifecycle annotation governs allowed status transitions
- **GIVEN** a `dataSubjectRequest` in status `received`
- **WHEN** the lifecycle transition `startVerifying` is applied
- **THEN** the status MUST move to `verifying`
- **AND** a transition from a final state (`fulfilled`/`refused`/`closed`) MUST be rejected by the lifecycle engine

### Requirement: EU art-12 legal-deadline computation
OpenRegister SHALL provide a pure, dependency-free deadline helper implementing
the EU GDPR art-12(3) timing: the base response deadline is one month from the
date the request was received; the deadline MAY be extended **once** by a
further two months when a reason is supplied; and a deadline is overdue when the
reference time is at or after the (possibly extended) deadline. The helper SHALL
be deterministic and unit-testable without a database or Nextcloud runtime.

#### Scenario: Base deadline is one month from receipt
- **GIVEN** a request received on a given date
- **WHEN** the due date is computed
- **THEN** the due date MUST be exactly one month after the received date

#### Scenario: A single extension adds two months
- **GIVEN** a request with a computed base due date and an extension reason
- **WHEN** the deadline is extended
- **THEN** the extended deadline MUST be exactly two months after the base due date
- **AND** a second extension attempt MUST be rejected (the deadline may be extended only once)

#### Scenario: Overdue detection
- **GIVEN** a request whose due date is in the past relative to a reference time
- **WHEN** overdue status is evaluated
- **THEN** it MUST report overdue
- **AND** a request whose due date is in the future MUST report not-overdue

### Requirement: Consumable RBAC + tenant scoped data-subject-request service
OpenRegister SHALL expose a `DataSubjectRequestService` that any app can resolve
via dependency injection to fulfil data-subject rights on behalf of an
authenticated (non-admin) handler. Unlike the admin-only `DsarService`, this
service SHALL apply RBAC and tenant (organisation) scoping to all discovery and
fulfilment so a caller only ever reaches objects it is authorised to read or
mutate. The service SHALL reuse the existing `GdprEntity` PII index
(`openregister_entities` ⋈ `openregister_entity_relations`) for cross-register
discovery and SHALL attribute its writes to the configured DSAR
processing-activity so the immutable audit trail records them.

#### Scenario: findSubjectData returns a subject's objects across registers, RBAC-scoped
- **GIVEN** a data subject whose personal data is indexed in objects across two registers
- **WHEN** `findSubjectData()` is called for that subject with RBAC scoping enabled
- **THEN** it MUST return the subject's objects from both registers that the caller is authorised to read
- **AND** objects the caller is not authorised to read MUST NOT be returned

#### Scenario: An access export assembles the subject's data into a portable bundle
- **GIVEN** a data subject with indexed objects
- **WHEN** `assembleAccessExport()` is called for that subject (art-15 / art-20)
- **THEN** it MUST return a bundle containing the subject's discovered objects in a portable, serialisable shape
- **AND** the bundle MUST record which PII attributes triggered each object's inclusion

### Requirement: Erasure honours legal hold and is mode-parameterised
The service's `erase()` operation (art-17) SHALL accept an erasure **mode**
parameter selecting between field-level pseudonymisation and whole-object
soft-delete, because consuming apps differ on the correct erasure behaviour; the
mode SHALL NOT be hard-coded. Erasure SHALL respect retention: any object under
an active legal hold (`RetentionService::hasActiveLegalHold()`) or in an
immutable archival status (`vernietigd` / `overgebracht`) SHALL NOT be erased and
SHALL be reported back to the caller as withheld (`held`) rather than silently
skipped or falsely reported as erased. Erasure SHALL support a dry-run that
reports what would be erased and what is held without mutating anything.

#### Scenario: Erasure skips an object under legal hold and reports it as held
- **GIVEN** a subject with two matching objects, one of which has an active legal hold
- **WHEN** `erase()` runs (not a dry run)
- **THEN** the object without a hold MUST be erased
- **AND** the object under legal hold MUST NOT be erased
- **AND** the held object MUST be reported in the result as `held` (withheld due to retention), not as `erased`

#### Scenario: Erase mode is selectable
- **GIVEN** a subject with a matching object
- **WHEN** `erase()` is called with mode `whole-object`
- **THEN** the object MUST be soft-deleted (its `deleted` metadata set)
- **AND WHEN** `erase()` is called with mode `pseudonymise`
- **THEN** the subject's matching field values MUST be pseudonymised in place rather than the whole object soft-deleted

### Requirement: Immutable audit of request fulfilment
OpenRegister SHALL capture all fulfilment writes performed by
`DataSubjectRequestService` (rectify, erase, restriction, objection) and all
lifecycle transitions of the `dataSubjectRequest` object in its existing
immutable, hash-chained audit trail (`AuditTrailMapper` + `AuditHashService`),
attributed to the configured DSAR processing activity, so the handling of a
data-subject request is independently verifiable after the fact.

#### Scenario: A fulfilment write produces an attributed audit row
- **GIVEN** the DSAR processing activity is configured
- **WHEN** the service rectifies or erases an object for a subject
- **THEN** the object write MUST carry the DSAR processing-activity attribution so the audit trail records it under that activity
