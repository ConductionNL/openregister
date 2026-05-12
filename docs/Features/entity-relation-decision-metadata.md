# Entity-Relation Decision Metadata

OpenRegister exposes an audited `PATCH /api/entity-relations/{id}` endpoint (and a parallel DI mapper method `EntityRelationMapper::updateDecisionMetadata`) for setting operator decisions on detected-entity occurrences. The two decision fields are:

- **`bases`** — `?array<string>` — UUIDs referencing legal grondslagen (Woo Art. 5 / equivalent) that justify redacting the occurrence. OpenRegister persists the UUIDs verbatim and does not validate that they resolve — the vocabulary is owned by the consumer app (DocuDesk's `dossier` register is the first consumer).
- **`skipAnonymization`** — `bool` (default `false`) — when `true`, the anonymise pass excludes this occurrence: text-replacement skips it, and `EntityRelationMapper::markAsAnonymized` leaves `anonymized = false` on the row.

These are **decision-only** fields. The post-hoc system fields `anonymized` and `anonymizedValue` (which record what the redaction code path actually did) are intentionally NOT in the PATCH whitelist; only `EntityRelationMapper::markAsAnonymized` writes them.

## Endpoint contract

```
PATCH /api/entity-relations/{id}
Content-Type: application/json
Body: { "bases"?: null | string[], "skipAnonymization"?: boolean }
```

- **200**: returned on success, body is the updated `EntityRelation` (`jsonSerialize` shape).
- **400**: shape or whitelist violation. Body: `{"error": "<message>", "details": {"field": "<name>", "reason"?: "<code>"}}`. Triggered by:
  - Any non-whitelisted top-level key (e.g. `anonymized`, `entityId`).
  - `bases` not `null` or `array<string>`.
  - `skipAnonymization` not boolean.
- **401**: no authenticated session.
- **403**: acting user lacks write-access to the relation's parent file (or object/email). For file-bound relations the check resolves the file through the user-folder and requires `isUpdateable()` to be `true`. Object- and email-bound relations are accepted with a warning log in v1; tightening tracked as a follow-up.
- **404**: `{id}` does not resolve to an existing relation.
- **500**: unexpected failure during the write.

The endpoint is `@NoAdminRequired` — non-admins can PATCH relations they have write-access to.

## Semantics

- **Single audited write path.** Both the HTTP controller and in-process DI callers go through `EntityRelationMapper::updateDecisionMetadata`. There is no parallel write path that bypasses validation or the audit trail.
- **Diff-aware.** Only fields whose new value differs from the current row state contribute to the update and the audit entry. A PATCH where every supplied value matches the current state, or an empty body `{}`, returns 200 with the unchanged row and writes NO audit entry.
- **Three-way `bases` semantics.** Field absent → unchanged; `"bases": null` → cleared; `"bases": []` → set to empty array (distinct from null per the spec); `"bases": ["..."]` → set to the array.
- **Audit-trail entry** (per successful change):
  ```
  action       = "entity_relation_decision_updated"
  user         = acting user UID (ADR-005 — NEVER the display name in the structured changed-fields payload)
  created      = now (UTC)
  changed.subjectType = "openregister_entity_relations"
  changed.subjectId   = <relation id>
  changed.fields      = { "<field>": { "previous": <old>, "new": <new> } } — only fields that actually changed
  ```
  Reads of `EntityRelation` rows produce no audit entries.

## How callers use it

**HTTP** (DocuDesk frontend, batch tools, scripts):

```http
PATCH /api/entity-relations/123
{ "bases": ["b8a3-..."], "skipAnonymization": false }
```

**PHP DI** (DocuDesk's `AnonymizationService`, OpenConnector pipelines, anywhere in OR's process):

```php
$mapper = $this->getOpenRegisterService('OCA\OpenRegister\Db\EntityRelationMapper');
$mapper->updateDecisionMetadata(
    id: 123,
    fields: ['bases' => ['b8a3-...'], 'skipAnonymization' => false],
    actingUser: $this->userSession->getUser()
);
```

DocuDesk specifically uses the DI path for its prohibition-override flow: when an operator acknowledges an override, DocuDesk writes its own audit entry (capturing the operator's reason) and then PATCHes the relation with `skipAnonymization=true` via this DI method — so OR's anonymise pass automatically excludes the released entity. See [DocuDesk `anonymisation-grondslagen-and-prohibition-gate`](https://github.com/ConductionNL/docudesk/pull/135).

## Anonymise-flow interaction

The new field changes the behaviour of two existing code paths:

1. **`POST /api/files/:fileId/anonymize` (HTTP)** — `FileTextController::anonymizeFile` reads relations through `EntityRelationMapper::findEntitiesForAnonymization`, which adds `AND skip_anonymization = 0` to the existing `findEntitiesForFile` query. Skipped relations are not in the replacements list and are not flipped by `markAsAnonymized`.
2. **`FileService::anonymizeDocument(Node, entities[])` (DI)** — the underlying `DocumentProcessingHandler::anonymizeDocument` defensively filters the caller-supplied `entities[]` against `EntityRelationMapper::findSkippedEntityValuesForFile($fileId)`. Even if the caller includes skipped occurrences in the array, OR drops them server-side before text-replacement. Contract: "skipped relations are never redacted, full stop."

After the anonymise call:

- Non-skipped relations: `anonymized = true`, `anonymizedValue = <placeholder>` (existing behaviour).
- Skipped relations: `anonymized = false`, the operator's `skipAnonymization = true` flag is preserved.

`skipAnonymization` is **forward-looking**: flipping it to `true` on an already-anonymised row does not retroactively un-redact the file. The redaction has already happened in the file content; only future re-runs honour the flag.

## Spec references

- Capability: [`openspec/changes/entity-relation-grondslagen/specs/entity-relation-grondslagen/spec.md`](../../openspec/changes/entity-relation-grondslagen/specs/entity-relation-grondslagen/spec.md)
- Design (anonymise flow, audit, authz, two-column migration): [`openspec/changes/entity-relation-grondslagen/design.md`](../../openspec/changes/entity-relation-grondslagen/design.md)
- ADR-022 (audit-trail for OR-owned mutations).
- ADR-005 (no PII in logs; UID not display name in audit payloads).
- ADR-023 (action-level authorization — opt-in; not introduced here).
