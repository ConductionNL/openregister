## 1. Scope-local numbering translator (per-document default)

- [x] 1.1 Add a small in-memory translator (e.g. a private helper / value object) that maps a global `e.id` to a scope-local sequence number by order of first appearance, keyed on `e.id` so all value-variants of one entity share one number.
- [x] 1.2 In `lib/Service/File/DocumentProcessingHandler.php` (placeholder build at ~lines 315-321), replace the interpolation of the global `$stableId` (`e.id`) with the translated scope-local number. Keep `findEntityIdsByValueForFile`'s `e.id` as the internal lookup key.
- [x] 1.3 Ensure within-document consistency: every occurrence of the same `e.id` (any variant) resolves to the same number throughout the run.
- [x] 1.4 Verify per-document scope reads/writes NO persistence (numbering computed entirely within the single run); confirm the DOCX/PDF/ODT/text branches all consume the same translated map unchanged.

## 1b. Localized TYPE label (acting user's language)

- [x] 1b.1 Inject `IL10N` (acting-user language) into `lib/Service/File/DocumentProcessingHandler.php` constructor (not present today); thread to the placeholder-build site.
- [x] 1b.2 At the placeholder build (~lines 315-321), translate `$entityType` to a localized label via `$l->t(...)` from the enumerated entity-type set; interpolate the localized label as `<TYPE>`. An `entityType` NOT in the enumerated set falls back to the raw string unchanged (no error).
- [x] 1b.3 Register the enumerated entity-type labels as translatable strings in `l10n/` with Dutch translations (`PERSON`→`PERSOON`, `ORGANIZATION`→`ORGANISATIE`, `LOCATION`→`LOCATIE`, `EMAIL`→`E-MAILADRES`, `PHONE`→`TELEFOONNUMMER`, `ADDRESS`→`ADRES`, `DATE`→`DATUM`, `IBAN`→`IBAN`, `SSN`→`BSN`, `IP_ADDRESS`→`IP-ADRES`). Source list = the canonical type constants (`EntityRecognitionHandler::ENTITY_TYPE_*`).
- [x] 1b.4 Ensure within-run label consistency (same type → same localized label throughout) and confirm the OR-side placeholder parsers remain type-agnostic (`DocumentProcessingHandler` residual regex `[^:\]]+`; `PdfTextReplacer::collapseAdjacentDuplicatePlaceholders`) so localized labels do not break residual detection or duplicate-collapse.

## 2. Endpoint scope signal

- [x] 2.1 In `lib/Controller/FileTextController.php::anonymizeFile`, read optional request-body params `scope` (`"document"` default | `"dossier"`) and `dossierKey` (stable folder **id**) via `$this->request->getParams()`. When `scope=dossier` and `dossierKey` is absent, **fall back** to the file's parent folder (`Node::getParent()`) as the dossier key (no HTTP 400 — forgiving default per Decision 2).
- [x] 2.2 Confirm `appinfo/routes.php` route (`/api/files/{fileId}/anonymize`) is unchanged — no new route.
- [x] 2.3 Thread `scope` + `dossierKey` through `lib/Service/FileService.php::anonymizeDocument` (new optional params, default per-document).
- [x] 2.4 Thread `scope` + `dossierKey` into `DocumentProcessingHandler::anonymizeDocument` signature (new optional params, default per-document so existing callers are unaffected).

## 3. Per-dossier numbering (deterministic recomputation — Decision 3, no table)

- [x] 3.1 Add `EntityRelationMapper::findEntityIdsByValueForFiles(array $fileIds)` — the multi-file sibling of `findEntityIdsByValueForFile`, returning rows with `entity_id`, `file_id`, `position_start` for all given files in one query.
- [x] 3.2 Add a recompute helper that, given a dossier folder, (a) enumerates the folder's descendant file ids via the Nextcloud Node API (`Folder::getDirectoryListing()`, recursive), (b) loads their entity rows via 3.1, (c) imposes the total stable order `(file_id ASC, position_start ASC, entity_id ASC)`, and (d) assigns `local_number` = rank of first appearance of each distinct `entity_id`. Returns an `e.id → local_number` map. NO new table, NO migration.
- [x] 3.3 In `DocumentProcessingHandler::anonymizeDocument`, when `scope=dossier`, seed the translator with the recomputed dossier map (3.2) instead of the per-file first-appearance counter; resolve the dossier folder from `dossierKey` (folder id) or the `Node::getParent()` fallback.
- [x] 3.4 Guarantee per-dossier consistency across files and counter restart between dossiers; guarantee idempotent re-runs reproduce identical numbers for a fixed dossier file+content set (byte-identical output). Document that adding/re-extracting a file re-ranks the dossier (Decision 3 caveat).

## 4. ADR-005 logging hygiene

- [x] 4.1 Ensure neither the translator nor the per-dossier recompute logs the entity value alongside its number; diagnostics may carry counts and `(dossierKey, e.id, number)` only — never `(value → number)`.

## 5. Unit tests

- [x] 5.1 Per-document numbering: distinct `e.id`s numbered `1..n` by first appearance; same entity → same number throughout one document; two separate runs of the same person get independent numbers.
- [x] 5.2 Per-dossier numbering: across two files of one dossier the same `e.id` gets the same number; a different `dossierKey` restarts at 1; re-running a file with the dossier unchanged reproduces identical numbers (idempotent / byte-identical); adding a file re-ranks deterministically.
- [x] 5.3 Recompute purity & ordering: numbers follow `(file_id, position_start, entity_id)` first-appearance rank; the function is pure (same stored rows → same map) regardless of which file triggered it; translation keyed on `e.id` (variants share one number).
- [x] 5.3b Localized labels: with `IL10N` set to `nl`, `PERSON` → `[PERSOON: 1]`; with `en`/no translation → `[PERSON: 1]`; an unknown type falls back to its raw label; same type → same label throughout a run.
- [x] 5.4 Update existing `DocumentProcessingHandler` tests asserting `[<TYPE>: <e.id>]` to expect scope-local numbers (none asserted e.id-based numbers directly); update `PdfTextReplacer` tests so they assert the replacer faithfully emits the id from the substitution map (no re-numbering), adjusting fixed-number examples (`[PERSON: 7]` → `[PERSON: 1]`, `[PERSON: 8]` → `[PERSON: 2]`).
- [x] 5.5 `findEntityIdsByValueForFiles` test: returns the deterministically-ordered union for multiple file ids (+ empty-input short-circuit).

## 6. Quality gate

- [x] 6.1 PHPCS (full `phpcs.xml` standard incl. the custom NamedParameters + SpecTag sniffs) passes on all changed files; `php -l` clean; unit tests green (30 new/touched + 1455-test suite, the single suite error is a pre-existing PHPUnit-10-vs-11 incompatibility in an untouched RBAC test). NOTE: PHPMD / Psalm / PHPStan could not be run locally — installing the dev toolchain 500s the live app (known env constraint); they must run in CI.
- [x] 6.2 Regression-check: existing per-file anonymise callers (no scope param) behave as per-document (new params default to `scope='document'`); full File + Db unit suites green — no regressions for opencatalogi / softwarecatalog consumers of the anonymise path.

## 7. Cross-app note (frontend — NOT in this backend change)

- [ ] 7.1 Frontend must pass the scope signal: `scope="document"` for single-document uploads, `scope="dossier"` + a stable `dossierKey` (folder id) for folder uploads. (Backend ready: params accepted + parent-folder fallback.)
- [ ] 7.2 Frontend must add a HARD WARNING that a dossier (folder) result is published as ONE publication/dossier — files MUST NOT be split into separate publications (keeps the dossier the disclosure unit; required for the per-dossier carry-over to be legally defensible).
- [ ] 7.3 Cross-app (DocuDesk grondslagen-summary): the summary renders/keys off the placeholder TYPE — it MUST display the SAME localized label and parse localized labels present in the redacted document, so the report legend matches the document. Track as a cross-app follow-up (the summary is rendered in DocuDesk's tree).

## 8. Cherry-pick to project branch (follow-up, not part of the development PR)

- [ ] 8.1 Note: no migration to port (deterministic recompute, no table). The `findEntityIdsByValueForFiles` read method + recompute helper are additive and cherry-pick cleanly; the `DocumentProcessingHandler` placeholder-build edit + param threading are a SEMANTIC port (handler diverges between `development` and `test/anonimiseren-bij-de-bron-or`) — re-apply at the project branch's equivalent placeholder-build site, same caveat as the PDF-replacer backport.
