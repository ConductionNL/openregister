## Why

`EntityRelation` records the link between a detected entity and the chunk/file/object/email it was found in, plus its anonymisation outcome (`anonymized`, `anonymizedValue`). What it does NOT record is the **legal basis** (grondslag) under which the anonymisation was performed.

Consuming apps — primarily DocuDesk — need to associate per-entity legal bases (Woo Art. 5 grondslagen and equivalents) with each anonymisation event for audit, compliance reporting (Wet open overheid), and downstream rendering (e.g. attaching a "grondslagenpagina" to the anonymised document). Today this metadata has nowhere to live. The choices were either to scatter it across consumer-app schemas (loses the link to the actual EntityRelation row that drove the redaction) or to never persist it (loses the audit trail entirely).

This change adds a single optional `bases` JSON column to `EntityRelation` and extends the anonymise endpoint to accept and persist these bases per entity. The bases MUST be stripped from the payload before forwarding to OpenAnonymiser — they are metadata about the decision, not input to the redaction tool.

## What Changes

- **NEW:** Optional `bases` JSON column on `EntityRelation` — array of UUIDs referencing `base` schema objects (the grondslag vocabulary owned by the consuming app, e.g. DocuDesk's `dossier` register). OpenRegister stores the array verbatim; it does NOT validate that the UUIDs resolve, since the vocabulary lives outside OpenRegister's own schemas.
- **MODIFIED:** The anonymise endpoint (`FileService::anonymizeDocument` and the controller routes that call it) accepts an optional `bases` array per entity in the request payload. When present, the bases are persisted on the matching `EntityRelation` row alongside the existing `anonymized` / `anonymizedValue` fields.
- **MODIFIED:** Before forwarding the entity list to OpenAnonymiser, the service MUST strip the `bases` field from each entry. OpenAnonymiser's contract is unchanged.
- **NEW:** Database migration adds the `bases` column to the `oc_openregister_entity_relations` table as a nullable JSON column. Existing rows are unaffected (column defaults to NULL).
- **NO trigger-side changes.** No new endpoints, no breaking changes to existing API. Callers that don't pass `bases` see identical behaviour to today.
- **NO prohibition gate.** Validating that "the right entities were selected for anonymisation" is the consumer app's responsibility (DocuDesk implements this against its own `publicationProhibition` schema in a paired change). OpenRegister stays a generic anonymise primitive.

## Capabilities

### New Capabilities

- `entity-relation-grondslagen`: optional bases-link on `EntityRelation`; the anonymise endpoint's contract for accepting, persisting, and stripping per-entity bases.

### Modified Capabilities

(none — the broader entity-recognition / anonymisation pipeline is implemented but currently uncovered by an OpenSpec capability; this change does not retroactively spec it. See `Out of scope` in design.md.)

## Impact

- **Code (openregister):**
  - `lib/Db/EntityRelation.php` — new `bases` field (JSON, nullable, default null); getter/setter via Nextcloud's Entity base class pattern.
  - `lib/Db/EntityRelationMapper.php` — persistence handles the new column.
  - Migration class under `lib/Migration/` — adds the column. Idempotent; safe on existing installs.
  - `lib/Service/FileService.php` (or the equivalent path that today calls OpenAnonymiser) — accept `bases` per entity, persist on EntityRelation, strip before forwarding.
- **API contract:** Anonymise endpoint payload gains an optional `bases` field per entity entry. Additive, non-breaking. Existing callers (and OpenAnonymiser) see no change.
- **Cross-app:**
  - **DocuDesk** is the immediate consumer; the paired change `anonymisation-grondslagen-and-prohibition-gate` in DocuDesk relies on this work landing.
  - **opencatalogi** and **softwarecatalog** do not call the anonymise endpoint; unaffected.
  - The `base` vocabulary itself lives in DocuDesk (per the in-flight `add-dossier-schema` change). OpenRegister does not own or validate that vocabulary.
- **Database:** One column added to one table. Migration is forward-only and zero-downtime.
- **Tests:** Mapper unit tests for the new column. Service-level tests for the persist + strip path.
