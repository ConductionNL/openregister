## Summary

Redaction placeholders stop being a cross-document linking key, and the Woo amendment adds a mask form per data type and prints the exception ground in the delivered file where it applies.

- Rows: 4.18 and 4.28 (not statutory), added by the Woo amendment below; the change's own scope is the placeholder id scope under AVG Art. 4(5).
- Wave 1. An amend of an open change (25 of 29 tasks done before the amendment); nothing already done is rewritten.
- Dependencies: none new. The grounds are written onto the relation by `opencatalogi/woo-review-surface` and dossiq; this change only prints them.
- Decision: D3 (the list of refusal grounds is dossiq's; OpenRegister prints the identifier stored on the relation).
- Build rules: openspec/woo-build-rules.md

## Why

OpenRegister's anonymise pass emits redaction placeholders as `[<TYPE>: <id>]` using the **global** `openregister_entities.id` (`e.id`). Because `findOrCreateEntity(type, value)` deduplicates by value, the same person receives the SAME `e.id` in every file and every publication (`EntityRecognitionHandler::storeDetectedEntities` sets the relation's `entity_id` at `lib/Service/TextExtraction/EntityRecognitionHandler.php:318`; `EntityRelationMapper::findEntityIdsByValueForFile` joins `openregister_entities e` and returns `e.id` as the placeholder id; `DocumentProcessingHandler::anonymizeDocument` interpolates it at `lib/Service/File/DocumentProcessingHandler.php:315-321`).

A stable cross-document, cross-publication token is a persistent **linking key**. Under AVG Art. 4(5), WP29 Opinion 05/2014 ("pseudonymisation is not a method of anonymisation"; the **linkability** criterion), AVG Recital 26 ("reasonably likely … all means likely to be used"), and EDPB Guidelines 01/2025, an output whose tokens let a reader correlate `[PERSON: 7]` across separate disclosures is **pseudonymised, not anonymised** — it enables mosaic / jigsaw re-identification. The disclosed number must not be a re-identification handle that outlives its disclosure unit.

Separately, the placeholder's **TYPE label** is always emitted in English (`PERSON`, `ORGANIZATION`, …) regardless of the configured language. For a Dutch-configured instance the redacted output should read in Dutch (`PERSOON`, `ORGANISATIE`, …). Both concerns are properties of the same emitted placeholder string built at the same site, so this change addresses them together.

## What Changes

- **Scope-local placeholder numbering.** The emitted placeholder uses a **scope-local sequence number** (1, 2, 3 … assigned by order of first appearance), not the global `e.id`. The exposed number never links a person across scopes.
- **Internal identity key retained.** `e.id` stays the internal "same person" key (how we know Jan Jansen in file A = file B); it is **translated** to the scope-local number only when building the emitted placeholder. No change to detection, dedup, or the entities/relations catalogue.
- **Scope = the upload unit.**
  - **Per-document (DEFAULT):** single-document upload. The counter restarts per file / per anonymise run; no persistence needed.
  - **Per-dossier (OPT-IN):** a folder upload IS the dossier. The counter is consistent across ALL files in that folder and restarts between dossiers. Requires a frontend scope signal and a cross-file numbering store.
  - Within a single document the numbering is ALWAYS consistent (same person → same number throughout) — required for readability and adds no linkability the un-redacted document didn't already have.
  - **HARD rule:** the counter is never global, never carried across dossiers, never carried across separate publications.
- **Endpoint scope signal.** `POST /api/files/{fileId}/anonymize` gains optional request params: `scope` (`"document"` default | `"dossier"`) and `dossierKey` (stable folder id; falls back to the file's parent folder when `scope=dossier` and `dossierKey` is omitted). Threaded through `FileService::anonymizeDocument` → `DocumentProcessingHandler::anonymizeDocument`.
- **Per-dossier numbering.** The same local number for a given person across the dossier's files, derived by **deterministic recomputation** over the dossier's stored entities under a fixed order — NO new table/store. Separate per-file anonymise calls within a dossier therefore all yield the same number.
- **Localized TYPE label.** The placeholder TYPE is translated to the **acting user's UI language** (via `IL10N`) from the enumerated entity-type set (`PERSON`, `ORGANIZATION`, `LOCATION`, `EMAIL_ADDRESS`, `PHONE_NUMBER`, `DATE_TIME`, `IBAN_CODE`, …). On a Dutch instance the operator sees `[PERSOON: 1]`. Unknown/free-form types fall back to the raw label. Labels are registered as translatable strings in `l10n/`.
- **BREAKING:** emitted placeholder ids change from the global `e.id` to scope-local numbers, AND the TYPE label changes with the UI language. Re-anonymising a previously anonymised file/dossier produces different placeholders than before this change. Stability/idempotency now holds within a fixed scope **and a fixed output language**.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `entity-relation-grondslagen`: this capability is the canonical home of the placeholder-format requirement ("The DI anonymise path MUST substitute each entity using the stable `[<TYPE>: <entity_id>]` placeholder format", referencing `findEntityIdsByValueForFile` → `e.id`). That requirement is modified on TWO axes: (1) the `<id>` becomes a **scope-local sequence number** (per-document default, per-dossier opt-in), not the global entity id; (2) the `<TYPE>` becomes a **localized label** in the acting user's language. The byte-identical-re-run invariant is re-stated as stable-within-a-fixed-scope-and-output-language.
- `pdf-anonymisation`: this capability restates the `[<TYPE>: <id>]` placeholder convention (Requirement "Replacement output MUST use identifiable placeholders, not pure redaction", with `[PERSON: 7]` examples). It is modified to clarify that BOTH `<TYPE>` (localized) and `<id>` (scope-local number) are supplied by the caller's substitution map — the PDF replacer remains agnostic to how either is computed and MUST emit them verbatim; only the upstream map changes.

## Impact

- **Code path (entity-id → placeholder):** `DocumentProcessingHandler::anonymizeDocument` (`lib/Service/File/DocumentProcessingHandler.php:315-321`) — where `$entityType` + `$stableId`/`$key` are interpolated — gains (a) the global `e.id` → scope-local number translation and (b) the `$entityType` → localized-label translation. The map source `EntityRelationMapper::findEntityIdsByValueForFile` (`lib/Db/EntityRelationMapper.php:267-294`) still supplies `e.id` as the internal key; both translations happen after the map is built.
- **i18n:** inject `IL10N` (acting-user language) into `DocumentProcessingHandler` (not present today). Register the enumerated entity-type labels as translatable strings in `l10n/` (en + nl at minimum: `PERSON`→`PERSOON`, `ORGANIZATION`→`ORGANISATIE`, `LOCATION`→`LOCATIE`, `EMAIL_ADDRESS`→`E-MAILADRES`, `PHONE_NUMBER`→`TELEFOONNUMMER`, `DATE_TIME`→`DATUM`, `IBAN_CODE`→`IBAN`, …). Unknown types fall back to the raw label. The OR-side placeholder parsers (`DocumentProcessingHandler` residual regex `[^:\]]+`, `PdfTextReplacer::collapseAdjacentDuplicatePlaceholders`) are type-agnostic, so localized labels do not break them.
- **Cross-app dependency (DocuDesk grondslagen-summary):** the summary renders/keys off the placeholder TYPE; it MUST display the same localized label and parse localized labels in the document. Flag as a cross-app follow-up so the report legend matches the redacted document.
- **Endpoint:** `FileTextController::anonymizeFile` (`lib/Controller/FileTextController.php:496+`) reads the new `scope` / `dossierKey` params from the request body; `appinfo/routes.php:965` route is unchanged (no new route — same URL, verb, auth). Threaded through `FileService::anonymizeDocument` (`lib/Service/FileService.php:1965`) → `DocumentProcessingHandler::anonymizeDocument` signature.
- **Per-dossier numbering (no persistence):** there is NO native dossier field on entity-relation rows today (they carry `fileId`/`objectId`/`objectUuid`/`registerId`/`schemaId`; see migrations `Version1Date20251116000000` and `Version1Date20260430180000`). Rather than add a store, the per-dossier number is **recomputed deterministically** from the dossier's stored entities: enumerate the folder's files, load their entity rows (new read `EntityRelationMapper::findEntityIdsByValueForFiles`), order by `(file_id, position_start, entity_id)`, and rank distinct `e.id`s by first appearance. No new table, no migration; numbers are final once all dossier files are extracted.
- **External dependency (frontend, out of scope for this backend change):** the frontend already knows single-document vs folder and MUST (a) pass the `scope`/`dossierKey` signal, and (b) add a HARD WARNING that a dossier (folder) result is published as ONE publication/dossier — files MUST NOT be split into separate publications. Keeping the dossier the disclosure unit is what makes per-dossier carry-over legally defensible.
- **Cherry-pick caveat:** this lands on `development` first but must be cherry-pickable into `test/anonimiseren-bij-de-bron-or`. `DocumentProcessingHandler` (where the placeholder is built) DIVERGES between `development` and the project branch, so the project-branch port is a SEMANTIC port (same caveat as the recent PDF-replacer backport), not a clean cherry-pick.
- **No new schemas/objects/tables/migrations** are introduced — this changes how the placeholder number is computed (deterministic recomputation from existing rows), not the data model. The only persistence touched is the existing `entity_relations` / `openregister_entities` tables, read-only for the numbering.

## Woo capability programme amendment (2026-10-05)

The Woo capability programme (round 1 build plan, wave 1) amends this change with two rows. Re-read on `development` at 1dc6a4667 before writing: the change is open at 25 of 29 tasks, and the open tasks are frontend and cross-app notes (7.1 to 7.3) and a cherry-pick (8.1). Nothing already done is rewritten.

| row | capability | ours today (the round 1 baseline) |
|---|---|---|
| 4.18 | A detection is masked in part rather than removed whole, and the form of the mask is set per data type | partial: `PdfTextReplacer` substitutes the whole value with `[<TYPE>: <id>]`; `AnonymisationProfile`'s `generalise` treats record properties on the archival path, not document text |
| 4.28 | The exception ground is printed on the delivered file at the place it applies | no: the ground sits on `EntityRelation::$bases` and in opencatalogi's inventory, never in the file |

What the amendment adds:

- A mask form per entity type, as an allowed alternative to the placeholder: `first` and `last` (keep n characters), `email-local` (mask the local part, keep the domain), and `generalise` (a date to its year, a postcode to its four digits). The form is set per entity type in the file settings key `anonymisation.maskForms`; `redaction-policy-as-data` (wave 2) later lets a named profile carry the same key. A mask that would reveal the whole value falls back to the placeholder.
- The exception ground printed in the placeholder: when an occurrence's `EntityRelation::$bases` holds grounds, the emitted text is `[<TYPE>: <n>; <ground>, <ground>]`, for example `[PERSOON: 1; 5.1.2e]`. A masked value is followed by the ground in brackets. The ground is the identifier stored on the relation; per decision D3 the list of grounds is dossiq's, and OpenRegister prints what the relation holds without resolving it.
- Both are computed upstream in `DocumentProcessingHandler`, so `PdfTextReplacer` and the office replacers stay agnostic and emit the map's value verbatim, as this change already requires.

Dependencies: none new. opencatalogi's `woo-review-surface` and dossiq's refusal grounds list write the grounds onto the relation; this amendment only prints them. Closes 4.18 and 4.28 together with this change's existing scope.
