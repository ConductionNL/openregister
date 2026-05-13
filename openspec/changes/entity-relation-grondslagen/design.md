## Context

`EntityRelation` (`lib/Db/EntityRelation.php`) is the join row between a detected entity and its location — chunk, file, object, or email — plus position offsets, confidence, detection method, and the anonymisation outcome (`anonymized`, `anonymizedValue`). It is OpenRegister's authoritative record of "which entity was found where, at what confidence, and what was done about it."

What it does NOT carry is *why* a redaction was applied. Consumer apps — DocuDesk first — record per-anonymisation legal bases (Woo Art. 5 grondslagen and similar) for compliance reporting and audit. Today this metadata has no home: stuffing it into consumer-app schemas decouples it from the actual EntityRelation row that drove the redaction, and leaves the audit trail incomplete.

The minimal correct surface is one optional field on `EntityRelation`: a JSON array of UUIDs that reference whatever vocabulary the consumer app uses (DocuDesk's `base` schema, in the in-flight `add-dossier-schema` change). OpenRegister stores the array verbatim. It does not validate the UUIDs because the vocabulary lives in another register owned by another app — cross-app referential integrity at this layer would be a coupling we don't want.

The anonymise endpoint (`FileService::anonymizeDocument` and the controller routes that call it) needs to learn three things:

1. Accept `bases` per entity in the request payload.
2. Persist them on the matching `EntityRelation` row alongside `anonymized` / `anonymizedValue`.
3. **Strip** `bases` from each entry before forwarding the entity list to OpenAnonymiser. Bases are decision metadata, not redaction input — OpenAnonymiser doesn't need them and the contract there should remain unchanged.

## Goals / Non-Goals

**Goals:**

- Add an optional `bases` JSON column on the `oc_openregister_entity_relations` table.
- Extend `EntityRelation` with a getter/setter for `bases` following the existing Nextcloud `Entity` base-class pattern.
- Extend the anonymise endpoint to accept, persist, and strip per-entity `bases` as described.
- Make the change additive and non-breaking: callers that don't pass `bases` see identical behaviour to today; OpenAnonymiser sees an unchanged payload.
- Provide a forward-only, zero-downtime migration.

**Non-Goals:**

- Validate that base UUIDs resolve. OpenRegister does not own the vocabulary; cross-app validation is the consumer app's responsibility.
- Spec the broader entity-recognition / anonymisation pipeline. `FileService::anonymizeDocument`, `EntityRelation`, `EntityRecognitionHandler` are implemented but not currently covered by an OpenSpec capability. Retrofitting a full capability spec for that surface is out of scope here; this change only specs the new bases-related behaviour.
- Provide a separate "set bases on EntityRelation" API. Bases are set as part of the anonymise call. If a future use case needs to attach bases without anonymising, it can be added as a follow-up.
- Provide a prohibition gate. Validating that the right entities were selected for anonymisation is a consumer-app concern (DocuDesk implements this in the paired change `anonymisation-grondslagen-and-prohibition-gate`).

## Decisions

### D1. `bases` is a JSON column, not a join table

The column is a JSON array of strings (UUIDs). No separate `entity_relation_base` join table.

**Rationale:**

- **Nextcloud convention.** Nextcloud's storage layer treats moderate-cardinality multi-valued attributes as JSON columns by default; join tables are reserved for relationships requiring foreign-key integrity, indexed reverse-lookup, or cross-record constraints. None of those apply here — bases are a per-row tag list.
- **Parity with `dossier.bases[]`.** DocuDesk's `dossier` schema (in-flight) uses an `array of $ref` to `base` objects. Mirroring that shape on the EntityRelation row keeps the mental model uniform across the system.
- **Zero foreign-key coupling.** OpenRegister does not own the `base` vocabulary; storing UUIDs as JSON strings sidesteps the question of whether to enforce FK constraints across registers.

**Alternative considered:** A `entity_relation_bases` join table with `(entity_relation_id, base_uuid)`. Rejected: introduces a second migration, two write points to keep in sync, and reverse-lookup queries that aren't part of any current use case. Can be added later if compliance reporting demands it.

### D2. No validation that base UUIDs resolve

The mapper accepts any string array. Whether the UUIDs point to actual `base` objects in DocuDesk's register (or any register) is the consumer app's problem.

**Rationale:**

- The `base` vocabulary lives in DocuDesk's `dossier` register. OpenRegister doesn't own it and shouldn't reach into another app's data to validate.
- The consumer app already validates the picker output before sending. Double-validation at the OpenRegister layer adds latency without catching new cases.
- Cross-register referential integrity is a known harder problem in OpenRegister; deferring it here is consistent with how other cross-app references work.

**Mitigation for the dangling-reference risk:** the consumer app should mark its canonical `base` seed objects as immutable (DocuDesk already does this per ADR-016 + the `add-dossier-schema` design). Tenant-created bases are deletable but rare; if a delete leaves dangling references on EntityRelation rows, the references read as "unknown grondslag" — degraded but not broken.

### D3. Strip happens after persistence, before the OpenAnonymiser call

Order in `FileService::anonymizeDocument`:

```
   1. for each entry in payload.entities:
        find or upsert the EntityRelation row, write anonymized/anonymizedValue + bases
   2. clone the entity list with `bases` removed from each entry
   3. forward the cloned list to OpenAnonymiser
```

**Rationale:** The persistence step is the source of truth for "what was decided". The OpenAnonymiser call is downstream, with a contract that does not include bases. Stripping after persistence ensures the audit record is complete even if the OpenAnonymiser call fails (failure is reported back; bases are still recorded with the relation in `anonymized: false` state, which is recoverable on retry).

**Alternative considered:** Strip first, persist after the OpenAnonymiser response. Rejected: if OpenAnonymiser succeeds and the persist fails, the redaction happened but the grondslag is lost — a worse outcome than the reverse.

### D4. Bases payload is set per entity, not per request

Each entry in `payload.entities[]` carries its own `bases`. There is no top-level `bases` field that applies to all entries.

**Rationale:** Per-entity grondslagen are the legal reality — different entities in the same document can be anonymised under different bases (a name = `persoonsgegevens`, a competitor's pricing offer = `bedrijfs-fabricagegegevens`). A top-level field would force consumers to either degrade to a "common denominator" or duplicate the logic.

The consumer app is responsible for any "apply to all" UX (e.g. a checkbox that fans the dossier's bases out to every entity client-side); OpenRegister sees only the per-entity result.

### D5. Empty / null `bases` is allowed

A row with `bases: null` or `bases: []` represents "anonymisation happened, no grondslag was attached". This stays valid because:

- Existing rows (pre-migration) have `bases: null`. We don't backfill.
- Some consumer flows may not record grondslagen yet (or ever — generic file-sanitisation flows have no grondslag concept).

The column is nullable; the JSON array, when present, has no `minItems` constraint.

### D6. Migration is forward-only and idempotent

The migration class adds the column with `notnull => false` and `default => null`. No data migration. Re-running the migration is a no-op (the column-add primitive is idempotent in Nextcloud's schema migration framework).

**Rollback:** drop the column. Existing callers that started passing `bases` would silently lose them on writes after rollback; this is acceptable for an emergency-only path.

## Risks / Trade-offs

- **[Dangling base UUIDs after consumer-side delete]** → Mitigation per D2: consumer app marks canonical seeds as immutable; tenant-created bases are rare to delete; readers tolerate "unknown grondslag" gracefully.
- **[Partial anonymise call: persist succeeds, OpenAnonymiser fails]** → Acceptable. The EntityRelation row reflects "intended" state with `anonymized: false`; retry re-issues the anonymise call. This was already the failure mode pre-change for `anonymized` / `anonymizedValue`; bases inherit the same semantics.
- **[Consumer app that wraps the call differently]** → If a consumer ever calls `FileService::anonymizeDocument` directly with a stale payload shape (no `bases` field), the call still works and bases default to null. Backwards-compatible.
- **[JSON column query performance]** → Negligible. Reverse lookups ("show me all relations under grondslag X") are not a current use case; if they emerge, a generated index on the JSON path or a materialised view is a follow-up.

## Migration Plan

1. Land `EntityRelation` field + `EntityRelationMapper` persistence + new migration class in one PR.
2. Apply migration on dev / staging — column appears as nullable JSON. Existing rows have `null`. Smoke-test: run an anonymise call without `bases` (existing behaviour) and with `bases` (new behaviour).
3. Update `FileService::anonymizeDocument` to accept, persist, and strip. Smoke-test the OpenAnonymiser leg — the request body should be unchanged from today's shape.
4. Release. Consumer apps (DocuDesk first) start sending `bases`.

**Rollback:** drop the column via a reverse migration. Bases sent by callers after rollback are silently ignored. Consumer apps must be redeployed to stop sending the field if rollback is permanent; in practice rollback is for emergencies and consumer apps tolerate the field being silently dropped.

## Seed Data

Not applicable — this change adds a column to an existing DB table, not new schemas or registers. The `base` vocabulary that bases reference lives in DocuDesk and is seeded there per the in-flight `add-dossier-schema` change.

## Open Questions

- Whether to add a generated index on the JSON column for compliance-reporting queries. **Resolution:** defer until a real query workload appears. JSON column queries on small per-row arrays are inexpensive at expected volumes.
- Whether the strip step should also happen if a future caller passes other DocuDesk-specific decoration fields (notes, audit hints). **Resolution:** out of scope. The strip is targeted at `bases` specifically. If other decoration fields appear, this design is the precedent for handling them — strip in the same place, with the same rationale.
