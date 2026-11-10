# AVG Verwerkingsregister (retrofit delta)

These requirements document `DsarService`, the partial implementation of the
data-subject rights flows the base spec lists as NOT-implemented
(`DataSubjectSearchService`, `ErasureRequestHandler`). It composes the
`GdprEntity` index, the `openregister_entity_relations` join, and `MagicMapper`
object lookup into the find / erase / rectify flows with processing-activity
audit attribution.

## ADDED Requirements

### Requirement: The system MUST locate and erase a data subject's objects across PII-indexed entities

`DsarService` MUST find every object that references a data subject by matching
the subject value against the `GdprEntity` index (`openregister_entities`) joined
to `openregister_entity_relations`, deduplicating by object uuid (preferred) or
legacy int id, and returning an inzage envelope of `{object, gdprEntities}` per
matched object (Art 15). It MUST support an erase flow (Art 17 vergetelheid) that
soft-deletes each matched object — recording `deletedBy`, `deletedAt`, a
`reason` of `avg-vergetelheid`, and the subject — with a `dryRun` mode that
returns the match set without erasing. The subject value MUST be escaped for SQL
`LIKE` wildcards before matching, so an admin cannot pass `%` or `_` to match (and
in the erase path, delete) every PII row.

#### Scenario: Find returns one envelope per matched object
- **GIVEN** a subject email `jan@example.nl` referenced by GdprEntity rows on two objects
- **WHEN** `findObjectsForSubject('jan@example.nl')` is called
- **THEN** the result MUST contain two envelopes, each with the object's `jsonSerialize()` payload and the matching `gdprEntities`
- **AND** entity hits for the same object MUST be grouped into a single envelope

#### Scenario: LIKE wildcards in the subject are escaped
- **GIVEN** an admin calls the erase flow with subject `%@%`
- **WHEN** `eraseObjectsForSubject('%@%')` matches entities
- **THEN** the `%` characters MUST be escaped before the `LIKE` comparison so they match literally, not as wildcards
- **AND** the call MUST NOT erase every PII-referencing object

#### Scenario: Dry-run erase returns matches without writing
- **GIVEN** three objects reference a subject
- **WHEN** `eraseObjectsForSubject($subject, dryRun: true)` is called
- **THEN** the summary MUST report `matchedCount: 3` and an empty `erased` list
- **AND** no object MUST be soft-deleted

#### Scenario: Erase soft-deletes with vergetelheid metadata
- **GIVEN** an object referencing the subject and a non-dry-run erase
- **WHEN** `eraseObjectsForSubject($subject)` runs
- **THEN** each erased object MUST have its `deleted` metadata set with `reason: avg-vergetelheid` and the subject recorded
- **AND** the erased entry MUST report the object's uuid, register, and schema

### Requirement: DSAR write operations MUST attribute the audit trail to the configured processing activity

`DsarService` MUST tag every DSAR write (erasure and rectificatie) with the
operator-configured DSAR processing-activity uuid by calling
`ObjectEntity::setProcessingActivityId()` before persisting, so the existing
audit-trail hook records the legal basis. The activity reference MUST be read
from app-config key `dsar_processing_activity`, falling back to the
`dsar` activity code, and resolved through
`VerwerkingsactiviteitMapper::resolveReference()`; when neither resolves the
write MUST still proceed, falling back to the schema/register annotation.
`rectifyObjectForSubject()` MUST merge the requested field changes into the
object payload and persist them under this attribution (Art 16 rectificatie).

#### Scenario: Rectification merges changes and attributes the activity
- **GIVEN** the app-config key resolves to a DSAR verwerkingsactiviteit uuid
- **WHEN** `rectifyObjectForSubject($objectId, ['adres' => 'Nieuwstraat 1'])` is called
- **THEN** the object payload MUST be merged with the new `adres` value (other fields preserved)
- **AND** `setProcessingActivityId()` MUST be called with the resolved DSAR activity uuid before `update()`

#### Scenario: Unresolved activity does not block the write
- **GIVEN** no `dsar_processing_activity` is configured and no `dsar`-coded activity exists
- **WHEN** a DSAR erase or rectification runs
- **THEN** `getDsarProcessingActivityUuid()` MUST return `null`
- **AND** the write MUST still proceed (audit falls back to the schema/register annotation)

#### Scenario: A missing object yields a null result, not an error
- **GIVEN** an object id that does not exist
- **WHEN** `rectifyObjectForSubject($missingId, $changes)` is called
- **THEN** the method MUST return `null` rather than throwing

## Notes — authorization gaps observed (not yet requirements)

- **No in-service authz gate on the erase path.** `eraseObjectsForSubject()` and
  `matchEntities()` rely on the caller (controller) being admin-gated; the LIKE
  escaping is the only in-service guard. There is no per-call permission check
  that the actor is a privacy officer / FG. The base spec's purpose-binding
  middleware is the intended enforcement point — surfacing here so the provider
  of the DSAR controller endpoints wires the authz before this service.
- **find/erase load objects with `_rbac: false, _multitenancy: false`.**
  `loadObjectByEntry()` and `rectifyObjectForSubject()` deliberately bypass RBAC
  and tenant isolation to see every matching object. This is correct for a
  privacy-officer DSAR sweep but means a multi-tenant deployment MUST gate the
  endpoint at the organisation level (see base-spec "Data subject request scoped
  to organisation"); the service itself does not scope by organisation.
- **Rectification has no factual-record protection.** The base spec requires the
  ability to reject rectification of professional-judgment records; `DsarService`
  performs an unconditional merge. A future change should add the rejection +
  objection-attachment workflow.
