## Context

OpenRegister's anonymise pass redacts detected entities with placeholders of the form `[<TYPE>: <id>]`. Today `<id>` is the **global** `openregister_entities.id` (`e.id`):

- `EntityRecognitionHandler::storeDetectedEntities` calls `findOrCreateEntity(type, value)` (dedup by value) and sets the relation's `entity_id` from the catalogue row (`lib/Service/TextExtraction/EntityRecognitionHandler.php:318`).
- `EntityRelationMapper::findEntityIdsByValueForFile($fileId)` builds a `value → {id, type}` map by `innerJoin(... 'openregister_entities', 'e', r.entity_id = e.id)` and returns `e.id` (`lib/Db/EntityRelationMapper.php:267-294`).
- `DocumentProcessingHandler::anonymizeDocument(Node $node, array $entities)` loads that map into `$entityIdMap` and builds the placeholder at `lib/Service/File/DocumentProcessingHandler.php:315-321`:
  ```php
  $key = $entity['key'] ?? substr(Uuid::v4()..., 0, 8);
  $replacements[$needle] = '['.$entityType.': '.$key.']';
  if (isset($entityIdMap[$originalText]) === true) {
      $stableId = $entityIdMap[$originalText]['id'];   // ← global e.id
      $replacements[$needle] = '['.$entityType.': '.$stableId.']';
  }
  ```

Because dedup is by value, the same person carries the same `e.id` in **every file and every publication**. The emitted number is therefore a stable cross-document/cross-publication **linking key** → the output is pseudonymised, not anonymised (AVG Art. 4(5); WP29 Op. 05/2014 linkability; Recital 26; EDPB Guidelines 01/2025). This design replaces the emitted number with a **scope-local sequence number** while keeping `e.id` as the internal identity key.

**Data-model reality that constrains the design:**
- The only anonymise entry point is per-file: `POST /api/files/{fileId}/anonymize` (`appinfo/routes.php:965`) → `FileTextController::anonymizeFile` (`lib/Controller/FileTextController.php:496+`) → `FileService::anonymizeDocument(Node, entities)` (`lib/Service/FileService.php:1965`) → `DocumentProcessingHandler::anonymizeDocument(Node, entities)`. No folder/batch route exists.
- `DocumentProcessingHandler` is a ~1100-line orchestrator that dispatches by MIME type: DOCX (PhpWord), PDF (`PdfTextReplacer` via the SAPP fork), Office/ODT (ZIP walk), plain text (`str_ireplace`). The placeholder map is built once (lines 315-321) and handed to every branch.
- Entity-relation rows carry `fileId`, `objectId`, `objectUuid`, `registerId`, `schemaId` (migrations `Version1Date20251116000000`, `Version1Date20260430180000`). There is **no native dossier/folder field** and no per-folder mapping anywhere.

## Goals / Non-Goals

**Goals:**
- Emit a scope-local sequence number (`1, 2, 3 …` by order of first appearance) instead of the global `e.id`, so the disclosed number never links a person across scopes.
- Keep `e.id` as the internal "same person" key, untouched in detection/dedup/catalogue.
- Per-document scope as the default (no persistence); per-dossier scope as an explicit opt-in, consistent across all files in the folder, restarting between dossiers, never global, never cross-publication.
- Within a single document, the same entity always gets the same number (readability).
- Stable/reproducible numbering within a fixed scope (idempotent re-runs → byte-identical output).

**Non-Goals:**
- No new HTTP route. The existing per-file endpoint gains optional params; no folder/batch anonymise endpoint in this change.
- No change to entity detection, the entities/relations catalogue, dedup, or the `skipAnonymization` / `bases` decision flow.
- No frontend work. The frontend scope signal and the single-publication-per-dossier hard warning are external dependencies (cross-app note in tasks).
- No new schemas/objects (this is a numbering change; see Seed Data).

## Decisions

### Decision 1: Translate `e.id` → scope-local number at placeholder-build time

The map `findEntityIdsByValueForFile` still supplies `e.id` as the internal key. Immediately before building the placeholder string (`DocumentProcessingHandler.php:315-321`), translate each distinct `e.id` encountered (in order of first appearance) to a scope-local integer via a small in-memory translator, then interpolate that integer instead of `$stableId`. The translation key remains `e.id` so that all value-variants of one logical entity (which share one `e.id`) share one number.

**Rationale:** the minimal, surgical change — it preserves the entire upstream pipeline and the PDF/DOCX/ODT/text branches (each just receives a different number in the same map). *Alternative considered:* change the SQL in `findEntityIdsByValueForFile` to emit a row-number. Rejected: the mapper is shared by other read paths, has no notion of scope, and SQL row-numbering can't express per-dossier carry-over without the persistence layer below.

### Decision 2: Endpoint scope signal — explicit param, parent-folder fallback

Add two optional request-body params to `POST /api/files/{fileId}/anonymize`, read in `FileTextController::anonymizeFile` via `$this->request->getParams()`:
- `scope`: `"document"` (default) | `"dossier"`.
- `dossierKey`: a stable string (the folder id or path) — REQUIRED when `scope=dossier`; ignored otherwise.

Thread both through `FileService::anonymizeDocument` and `DocumentProcessingHandler::anonymizeDocument` (new optional params, defaulting to per-document so existing callers are unaffected).

`dossierKey` is a stable folder **id** (not a path — see Risks).

**Chosen (PM/user): explicit param + parent-folder fallback.** The frontend already knows single-doc vs folder, so it sends the explicit signal; an explicit `dossierKey` is unambiguous and authoritative. When `scope=dossier` but `dossierKey` is absent, **back-derive the dossier from the file's parent folder** (`Node::getParent()`) rather than erroring — a forgiving default that still works if the frontend omits the key. (The discarded alternative was returning HTTP 400 on a missing `dossierKey`; the fallback was preferred.)

### Decision 3: Per-dossier numbering — deterministic recomputation (no persistence)

**Chosen (PM/user, 2026-06-22): deterministic recomputation — NO new table, NO migration.** Per-document scope needs no persistence (translator computed within the run). Per-dossier scope must yield the SAME number for a person across the folder's separately-anonymised files; rather than persist a mapping, the number is recomputed as a **pure function of the dossier's already-stored entity-relation rows** on every per-file call.

**Algorithm (per-file call with `scope=dossier`):**
1. Resolve the dossier folder from `dossierKey` (a stable folder **id**); fall back to the file's parent folder (`Node::getParent()`) when `dossierKey` is absent (see Decision 2).
2. Enumerate the folder's descendant **file ids** via the Nextcloud Node API (`Folder::getDirectoryListing()` / recursive for sub-folders).
3. Load entity-relation rows for those file ids — a new read method `EntityRelationMapper::findEntityIdsByValueForFiles(array $fileIds)` (the multi-file sibling of the existing `findEntityIdsByValueForFile`), each row carrying `entity_id`, `file_id`, `position_start`.
4. Impose a **total, stable order**: sort by `(file_id ASC, position_start ASC, entity_id ASC)`.
5. Walk that order assigning `local_number` = the rank of first appearance of each distinct `entity_id` (`1, 2, 3 …`).
6. The current file's placeholder for `entity_id X` = `X`'s assigned `local_number`.

The translator (Decision 1) is seeded with this precomputed `e.id → local_number` map for the dossier (instead of the per-file first-appearance counter).

**Rationale (why recompute over a table):** the number becomes a pure, recomputable function of stored state — no schema change, no migration, no cleanup policy, nothing to keep in sync or orphan when a dossier/publication is deleted. Reuses the existing entities/relations tables for identity (ADR-011). *Alternatives rejected:* (a) a new `oc_openregister_anon_id_scope` mapping table — explicit/auditable but adds schema + migration + a row-cleanup obligation; not chosen. (b) reuse `anonymizedValue` on relation rows — it is a generic `'[REDACTED]'`-style marker per `entity-relation-grondslagen`, so the per-entity number is not recoverable from it.

**Determinism caveat (accepted):** the numbering is stable only for a **fixed dossier file+content set**. If a file is added or re-extracted after some files were already anonymised, a re-run re-ranks and numbers may shift across the dossier. This is acceptable under the unit-of-publication model (the frontend treats the folder as ONE publication and warns against splitting it), but it means **per-dossier numbering is final only once all dossier files are extracted**. Documented as a risk; no backfill and no forced re-anonymisation.

### Decision 4: Within-scope consistency and idempotency

- **Per-document:** the translator assigns numbers deterministically by order of first appearance over the (deterministic) needle iteration for that file. Same file + same content → same numbers → byte-identical output. No persistence.
- **Per-dossier:** the recomputation (Decision 3) is a pure function of the dossier's stored entity-relation rows under a fixed total order, so every per-file call within the dossier derives the SAME `e.id → number` map and the same person is the same number across all the dossier's files. Re-running a file with the dossier's file+content set unchanged reproduces identical numbers (idempotent / byte-identical). The numbering only shifts if the dossier's file set or extracted entities change (see Decision 3 caveat).

### Decision 5: ADR-005 — no value↔number mapping with the value in logs

The scope-local number itself is not PII. But the translator and the per-dossier recompute MUST NOT log the entity *value* alongside its number. Diagnostics may log counts and `(dossierKey, e.id, number)` (ids/counters only), never `(value → number)`.

### Decision 6: Localize the placeholder TYPE label to the acting user's language

The `<TYPE>` segment is translated at the same placeholder-build site (`DocumentProcessingHandler.php:315-321`) via an injected `IL10N` (acting-user language; `IL10N` is not currently a dependency of the handler — add it). The label source is the enumerated entity-type set (the `EntityRecognitionHandler::ENTITY_TYPE_*` constants); each is registered as a translatable string in `l10n/` with Dutch translations. So the emitted placeholder is `[<localizedType>: <scopedNumber>]` (e.g. `[PERSOON: 1]` on a Dutch instance). A type NOT in the enumerated set falls back to its raw string (no translation, no error).

**Acting-user language (not instance/configured)** was chosen (PM/user): simplest, and on a Dutch-configured instance operators are Dutch so output is Dutch. Trade-off — the same dossier anonymised by operators in different languages yields different labels; this is folded into the idempotency caveat (Decision 4 now reads "stable within a fixed scope **and** output language"). *Parser safety:* the OR-side placeholder parsers are type-agnostic (`DocumentProcessingHandler` residual regex `[^:\]]+`; `PdfTextReplacer::collapseAdjacentDuplicatePlaceholders` backreference), so localized labels do not break residual detection or duplicate collapse. *Cross-app:* DocuDesk's grondslagen-summary renders/keys off the TYPE and MUST use the same localized label + parse localized labels — flagged as an external dependency (tasks 7.3).

## Risks / Trade-offs

- **[BREAKING: placeholder numbers change]** → Existing anonymised outputs used `e.id`; re-anonymising now yields scope-local numbers. Documented as BREAKING in the proposal and spec; the byte-identical invariant is re-stated as stable-within-a-fixed-scope. No automatic re-anonymisation is triggered.
- **[Per-dossier numbering shifts if the dossier changes between runs]** → because numbers are recomputed from the dossier's current entity-relation set, adding/removing/re-extracting a file re-ranks the whole dossier on the next run. Mitigation: per-dossier numbering is final only once all dossier files are extracted; the unit-of-publication model (one folder → one publication) makes this acceptable. Documented in Decision 3.
- **[Recompute cost scales with dossier size]** → each per-file call enumerates the folder's files and loads their entity-relation rows. Mitigation: dossiers are small (a case file's documents); one batched query (`findEntityIdsByValueForFiles`) not N queries; acceptable for v1. A future server-side batch endpoint (Open Questions) would compute once per dossier instead of per file.
- **[`dossierKey` instability — folder renamed/moved]** → resolved: `dossierKey` is a stable folder **id**, not a path, so renames don't change the key; the frontend supplies the id.
- **[Split-publication leak]** → if an operator splits a per-dossier result into separate publications, the carried-over numbers link people across those publications, re-introducing the linkability problem. Mitigation is OUT OF SCOPE here (backend): the frontend MUST add a hard warning that a dossier result is published as ONE publication/dossier. Noted as an external dependency.
- **[Concurrent per-file calls within one dossier]** → no shared mutable state (no table, no counter row), so there is no write race; concurrent calls each independently recompute the same deterministic map. Worst case is redundant computation, not divergent numbers.

## Test Impact

- **Unit — numbering scope:** per-document run numbers distinct `e.id`s `1..n` by first appearance; same entity → same number throughout a document; two separate per-document runs of the same person get independent numbers.
- **Unit — per-dossier consistency:** across two files of one dossier the same `e.id` gets the same number (recompute under the fixed total order); a different `dossierKey` restarts at 1; re-running a file with the dossier unchanged reproduces identical numbers (idempotent / byte-identical); adding a file re-ranks deterministically.
- **Unit — recompute ordering:** numbers follow `(file_id, position_start, entity_id)` first-appearance rank; the function is pure (same stored rows → same map) and independent of which file triggered the call.
- **Unit — translator purity:** translation keyed on `e.id` (variants of one entity share one number); no log line carries `value → number`.
- **Unit — localized labels:** `IL10N=nl` → `[PERSOON: 1]`; `en`/untranslated → `[PERSON: 1]`; unknown type → raw label fallback; same type → same label across the run; residual regex + duplicate-collapse still match localized placeholders.
- **Existing tests:** `DocumentProcessingHandler` tests asserting `[<TYPE>: <e.id>]` placeholders MUST be updated to expect scope-local numbers. `PdfTextReplacer` tests assert the replacer faithfully emits the id from the substitution map (it does not re-number); examples updated from `[PERSON: 7]` to scope-local `[PERSON: 1]` where they assert specific numbers.
- **Mapper read method:** `findEntityIdsByValueForFiles(array $fileIds)` returns the union for multiple files, deterministically ordered (no new table to migrate).

## Seed Data

This change introduces **NO new schemas, seed objects, tables, or migrations** — it changes how the placeholder number is computed (deterministic recomputation from existing entity-relation rows, Decision 3), not the data model. There is no `register.json`/`schema.json` to add, no seed-object generation step, and no new table. The only persistence touched is the existing `entity_relations` / `openregister_entities` tables, read-only for the numbering.

## Cherry-pick / Project-branch Port

This lands on `development` first and must be cherry-pickable into `test/anonimiseren-bij-de-bron-or`. `DocumentProcessingHandler` — where the placeholder is built (lines 315-321) — **DIVERGES** between `development` and the project branch. The port to the project branch is therefore a **SEMANTIC port** (re-apply the translation step, the recompute helper, and the param threading at the project branch's equivalent placeholder-build site), not a clean cherry-pick — the same caveat that applied to the recent PDF-replacer backport. With deterministic recompute there is **no migration to port** (Decision 3): the new `EntityRelationMapper::findEntityIdsByValueForFiles` read method and the recompute helper are additive and should cherry-pick cleanly; only the handler edit + param threading need hand-porting.

## Open Questions

- **Resolved:** `dossierKey` is the folder **id** (stable across rename); the frontend supplies it. Scope signal = explicit param + parent-folder fallback. Per-dossier persistence = deterministic recomputation (no table). Endpoint stays per-file (no batch route this change).
- Should a folder/batch anonymise endpoint be added later so per-dossier numbering can be driven server-side in one call (computing the recompute once per dossier instead of per file)? Out of scope here; flagged for a follow-up.
- When is a dossier considered "fully extracted" so per-dossier numbers are final? Currently implicit (the operator anonymises after extraction). A future explicit "dossier ready" signal could harden the determinism caveat — follow-up.
