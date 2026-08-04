# Entity-Relation Grondslagen — Decision-metadata PATCH contract

Reviewers of an anonymised document need a way to record their
operator decision *per entity occurrence*: which legal grondslagen
justify the redaction (or non-redaction), and whether a specific
occurrence should be left untouched. The
`entity-relation-grondslagen` change adds two fields to the
`oc_openregister_entity_relations` row and one PATCH endpoint to
write them, alongside a defensive read-side filter so a row marked
`skip_anonymization = true` is never redacted regardless of caller
behaviour.

## Row-shape additions

The `EntityRelation` entity now carries two operator-decision fields:

| Column                | Type              | Default | Semantics                                                                 |
|-----------------------|-------------------|---------|---------------------------------------------------------------------------|
| `bases`               | JSON (nullable)   | `NULL`  | Optional array of UUIDs referencing legal grondslagen.                    |
| `skip_anonymization`  | BOOLEAN           | `false` | Operator decision NOT to redact this occurrence.                          |

Both columns are added by the idempotent migration
`Version1Date20260512120000.php` (guarded with `hasColumn` so a re-run
is a no-op). Existing rows read back as `bases = null` and
`skipAnonymization = false`.

`bases` is multiset-equal on diff (order-insensitive, duplicates
collapsed) so cosmetic reorderings do not produce spurious audit
entries. The distinction between `null` and `[]` is preserved.

## PATCH endpoint

```
PATCH /api/entity-relations/{id}
Content-Type: application/json
Authorization: <NC session>

{
  "bases": ["<grondslag-uuid>", ...],   // optional
  "skipAnonymization": true | false      // optional
}
```

Behaviour:

- **Whitelist enforcement.** Body keys MUST be a strict subset of
  `{bases, skipAnonymization}`. Any other key (e.g. `anonymized`,
  `anonymizedValue`) returns HTTP 400 with body
  `{ "error": "field_not_allowed", "field": "<name>" }`. The
  post-hoc system fields `anonymized` / `anonymizedValue` are
  intentionally NOT writable here — those record what the redaction
  code path actually did and remain owned by `markAsAnonymized`.
- **Shape validation.** `bases` MUST be `null` or `array<string>`;
  `skipAnonymization` MUST be a `bool`. Anything else → HTTP 400.
- **Authorisation.** Resolution order:
  1. If the row has `fileId`: caller MUST be able to write the file
     (the same implicit check `markAsAnonymized` inherits today).
  2. Else if the row has `objectId` (with `registerId` + `schemaId`):
     caller MUST be able to update the object.
  3. Else if the row has `emailId`: caller MUST be able to access
     the email.
  4. Otherwise deny.

  Unauthenticated session → HTTP 401 (the NC framework rejects
  before the controller runs; `@NoAdminRequired` does not bypass
  session auth).
- **Semantic no-op.** A PATCH whose values match the current state,
  or an empty body, returns HTTP 200 with the unchanged row and
  writes NO audit entry.
- **Diff-aware audit.** A non-empty diff persists the change AND
  emits exactly one immutable audit-trail entry (ADR-022). The
  entry payload captures:
  - `actor`: the acting user UID (per ADR-005 — the UID, NOT the
    display name).
  - `subjectType`: `openregister_entity_relations`.
  - `subjectId`: the row id.
  - `timestamp`: ISO-8601.
  - `changedFields`: object whose keys are ONLY the fields that
    actually changed; each value `{ previous: <old>, new: <new> }`.
- **Transactional integrity.** The row UPDATE and the audit-trail
  INSERT run inside a single DB transaction. An audit-INSERT
  failure rolls back the UPDATE and surfaces as HTTP 500 — clients
  never observe a persisted decision-metadata change without a
  matching audit entry.
- **Post-commit event.** After commit, the mapper dispatches one
  `EntityRelationDecisionUpdatedEvent` (Symfony event, isolated in
  its own try/catch so a listener failure does not mask the
  persisted state change). Listeners can subscribe to the
  `isSkipAnonymizationActivated()` convenience for the common
  `false → true` trigger.

Successful response shape (200 OK):

```json
{ "...row fields...", "bases": [...], "skipAnonymization": true }
```

Validation-error response shape (400):

```json
{ "error": "<error-code>", "field": "<offending-field>" }
```

## Skip-aware anonymise flow

Three independent enforcement layers ensure that a row with
`skip_anonymization = true` is never redacted regardless of
caller behaviour:

1. **`EntityRelationMapper::markAsAnonymized($fileId, $value)`** —
   added `AND skip_anonymization = 0` predicate. Skipped rows
   retain `anonymized = false` after the file's anonymise pass.
2. **`FileTextController::anonymizeFile`** — now reads relations
   through the new mapper method `findEntitiesForAnonymization`
   which filters `skip_anonymization = 0` at the SQL layer. The
   replacements list never contains skipped occurrences.
3. **`DocumentProcessingHandler::anonymizeDocument`** —
   defensively filters the caller-supplied entity array against
   `findSkippedEntityValuesForFile` before delegating to the
   text-replace pipeline. Even a caller that includes a skipped
   row in its payload will see it filtered out at OR.

The `skip_anonymization` flag is forward-looking: flipping it to
`true` on an already-anonymised row does NOT retroactively
un-redact the file. Re-running the anonymise pass on the same
file with the updated flag is safe and idempotent — already-redacted
occurrences stay redacted; newly-skipped occurrences are left
untouched in any future pass.

## Retry-by-omission pattern

A reviewer who PATCHes only `{bases: [...]}` leaves
`skipAnonymization` unchanged. Omitted fields retain their stored
value. Two consecutive PATCHes setting `bases` to the same array
produce one audit entry (the first); the second is a semantic
no-op.

## Cross-references

- `entity-relation-grondslagen` openspec change (this contract).
- ADR-005 — PII-free logging / actor UID (not display name) in
  audit entries.
- ADR-022 — immutable audit-trail conventions.
- `lib/Db/EntityRelationMapper.php` — `updateDecisionMetadata`,
  `markAsAnonymized`, `findEntitiesForAnonymization`,
  `findSkippedEntityValuesForFile`.
- `lib/Controller/EntityRelationsController.php` — PATCH handler.
- `lib/Event/EntityRelationDecisionUpdatedEvent.php` — post-commit
  Symfony event.
- `tests/Unit/Db/EntityRelationMapperUpdateDecisionMetadataTest.php`
  — diff-aware audit, whitelist, no-op semantics (19 tests).
- `tests/Unit/Controller/EntityRelationsControllerTest.php` —
  200 / 400 / 401 / 403 / 404 / 500 paths (11 tests).
