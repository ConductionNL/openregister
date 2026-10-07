---
kind: code
depends_on: []
---

## Why

The AVG/GDPR "verwerkingsregister" (processing-activity register) subsystem — the
`Verwerkingsactiviteit` entity/table, its controller, service call sites, the operator-importable
`avg-bundle.json` schema, its URL routes, and the Vue admin UI — was built with Dutch identifiers
throughout: field names (`naam`, `rechtsgrond`, `bewaartermijn`, ...), an enum constant
(`RECHTSGROND_VOCABULARY`) and its Dutch values, and URL segments
(`/api/avg/verwerkingsactiviteiten`). This violates this repo's hard rule that ALL code — including
standardised/domain terms — must be English; the narrow exemptions are untranslatable proper nouns
(BSN, Awb, gemeente) and external-standard-mandated field names, neither of which applies to these
identifiers (they are this app's own field names, not something an external API dictates). This is a
design-language cleanup, not a feature or behavior change: every renamed field keeps its type,
validation, and semantics exactly as-is.

**Baseline note**: the sibling change `processing-activity-register` (created 2026-06-11) built most
of this Dutch-named surface; the bulk of its tasks have already landed in `lib/` (its own
`tasks.md` shows the majority of phases checked off, with a handful of follow-up items still open —
those follow-ups are unaffected by this rename and are out of scope here). This change treats the
current `lib/` tree — which already includes that change's landed implementation — as its baseline.
It does not touch or depend on that change's artifacts; `processing-activity-register` should be
archived separately (by whoever owns that change) once this rename lands, since archiving is
orthogonal to this cleanup.

**Bundled bugfix**: while renaming `ProcessingLogService::fallbackActivityUuid()`'s Dutch setter
calls, this change also fixes a pre-existing latent bug on the same line: it currently calls
`setRechtsgrond('public-task')` (hyphenated), which is not a member of
`RECHTSGROND_VOCABULARY` (which expects `'publieke_taak'`, underscored) — so the fallback activity
has silently carried an invalid legal-basis value since it was written. The rename replaces this
with the correct call, `setLegalBasis('public_task')`, using the renamed setter and the correct
renamed enum value. This is called out explicitly here (and again in design.md) rather than being
buried inside a mechanical rename diff.

## What Changes

- Rename 13 Dutch entity field names (DB column + property + getter/setter + JSON/API key, kept in
  lockstep) on `Verwerkingsactiviteit`/`VerwerkingsactiviteitMapper`: `naam`→`name`,
  `beschrijving`→`description`, `doelbinding`→`purpose`, `rechtsgrond`→`legalBasis`,
  `categorieenBetrokkenen`→`dataSubjectCategories`, `categorieenPersoonsgegevens`→
  `personalDataCategories`, `bewaartermijn`→`retentionPeriod`, `ontvangers`→`recipients`,
  `doorgifteBuitenEu`→`internationalTransfers`, `technischeMaatregelen`→`technicalMeasures`,
  `organisatorischeMaatregelen`→`organisationalMeasures`, `verwerkingsverantwoordelijke`→
  `controller`, `contactgegevensFg`→`dpoContactDetails`. **BREAKING** for any external API consumer
  of `/api/avg/verwerkingsactiviteiten*` (see routes below) — confirmed no external consumer exists
  today (only this app's own frontend calls it).
- Rename the `RECHTSGROND_VOCABULARY` constant to `LEGAL_BASIS_VOCABULARY` and translate its 6
  stored values to GDPR Art. 6(1)(a)-(f) English terms (`toestemming`→`consent`,
  `overeenkomst`→`contract`, `wettelijke_verplichting`→`legal_obligation`,
  `vitaal_belang`→`vital_interests`, `publieke_taak`→`public_task`,
  `gerechtvaardigd_belang`→`legitimate_interest`). **BREAKING** for the 7 existing rows on the
  shared dev Postgres instance (values in use today: `wettelijke_verplichting`,
  `publieke_taak`) — the DB migration remaps all 6 possible values defensively (see design.md).
  `STATUS_VOCABULARY` is already English (`concept`/`published`/`archived`) and needs no change.
- Update `VerwerkingsactiviteitMapper::validate()` error messages and the `findAll()` raw
  `orderBy('naam', ...)` string literal to the new field names.
- Update `VerwerkingsactiviteitenController::hydrateFromPayload()`'s `stringFields`/`arrayFields`
  dispatch maps to the renamed keys/setters.
- Rename URL route segments in `appinfo/routes.php`: `/api/avg/verwerkingsactiviteiten` →
  `/api/avg/processing-activities`, `/api/avg/verantwoording` → `/api/avg/accountability`.
  **BREAKING** in principle, but confirmed no external consumer — only this app's
  `EditActivityDialog.vue`/`AvgIndex.vue`/`src/store/modules/avg.js` call it, and they are updated
  in the same change.
- Update `AvgRetentionService`'s report-payload dict keys (`naam`, `bewaartermijn`, ...) to English.
  Its `ObjectEntity::setDeleted()` write path also switches to the new `retentionPeriod` key for new
  writes only — historically-persisted deletion-log payloads are point-in-time snapshots and are
  deliberately NOT rewritten (see design.md).
- Fix `ProcessingLogService::fallbackActivityUuid()`'s Dutch setter calls (rename +
  the `setRechtsgrond('public-task')` bugfix described above under "Why").
- Rename the `avg-bundle.json` `verwerker` schema (register `avg`) to `processor`, with its Dutch
  property keys translated to English (see design.md for the full mapping table). This bundle is
  operator-importable only — confirmed not auto-imported by any PHP repair step or service — so the
  rename carries no known-live-instance migration risk.
- Update the Vue admin UI (`EditActivityDialog.vue`, `AvgIndex.vue`, `src/store/modules/avg.js`) to
  bind to the renamed fields and call the renamed URL segments.
- Update PHPUnit integration tests that call the renamed getters/setters/array keys
  (`AvgVerwerkingsregisterIntegrationTest`, `VerwerkingsactiviteitenControllerIntegrationTest`,
  `AvgRetentionServiceIntegrationTest`, `DsarServiceIntegrationTest`). The
  `oc_openregister_processing_log` table and the `openregister_verwerkingsactiviteiten` TABLE name
  (only its columns change) are unaffected.
- **Scope correction, added after initial drafting**: `DsarController`'s public methods `inzage()`,
  `portabiliteit()`, `vergetelheid()`, `rectificatie()` and their `appinfo/routes.php` route names/URL
  segments (`/api/avg/inzage` etc.) are real, shipped Dutch-named PHP identifiers — not aspirational
  spec prose as first assessed — and are renamed in this same change (`access`/`portability`/
  `erasure`/`rectification`; see design.md for exact naming and the URL segments). `AuditTrailController::inzageverzoek()`
  (route `/api/audit-trails/inzageverzoek`) is renamed too. Frontend callers of these routes
  (`AvgIndex.vue`, `src/store/modules/avg.js` — already in scope above) are updated in lockstep. No
  DB migration is needed for this part (pure method/route rename, no column involved); confirmed no
  external consumer.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `verwerkingsregister-api`: the CRUD REST surface's MUST-hydrate field-name list (string fields
  and array fields) changes from the 13 Dutch identifiers to their English equivalents; the URL
  path segments change from `verwerkingsactiviteiten`/`verantwoording` to
  `processing-activities`/`accountability`.
- `avg-verwerkingsregister`: field-name references across requirements/scenarios that describe the
  `Verwerkingsactiviteit` entity and the `avg-bundle.json` `verwerker`/`processor` schema move to
  English, EXCEPT two explicit carve-outs that stay Dutch by design: (1) the Art 30 PDF export's
  Dutch human-readable column labels (VNG-model-mandated display text for an as-yet-unbuilt export,
  not a code identifier), and (2) the not-yet-implemented VNG "Verwerkingenlogging" export endpoint,
  whose literal Dutch field names (`actie_id`, `vertrouwelijkheid`, `verwerkende_organisatie`, ...)
  are mandated by that external VNG API standard by name. **Correction**: the DSAR HTTP-surface
  requirement (`DsarController`'s `inzage`/`portabiliteit`/`vergetelheid`/`rectificatie` methods) DOES
  describe real shipped code and is renamed to match the code change above — it was wrongly assessed
  as untouched aspirational prose in the first draft of this proposal. Dutch field names local to the
  still-unbuilt DPIA and consent schemas remain untouched (no corresponding code exists — confirmed:
  only `DpiaPatternDetectionService`, already English, exists for DPIA; nothing exists for consent
  tracking), as do the "Standards & References" section's bilingual legal-citation glosses (reference
  material aiding Dutch-market cross-lookup, not identifiers) and the pre-existing, independently
  stale "Current Implementation Status" narrative (reconciling its accuracy is out of scope for this
  rename).

## Impact

- **Code**: `lib/Db/Verwerkingsactiviteit.php`, `lib/Db/VerwerkingsactiviteitMapper.php`,
  `lib/Controller/VerwerkingsactiviteitenController.php`, `lib/Controller/DsarController.php`,
  `lib/Controller/AuditTrailController.php`, `lib/Service/AvgRetentionService.php`,
  `lib/Service/ProcessingLogService.php`, `lib/Resources/AvgSchemas/avg-bundle.json`,
  `appinfo/routes.php`.
- **Frontend**: `src/dialogs/avg/EditActivityDialog.vue`, `src/views/avg/AvgIndex.vue`,
  `src/store/modules/avg.js`.
- **Database**: new data-preserving migration on `oc_openregister_verwerkingsactiviteiten`
  (add-copy-drop per column, guarded by `hasColumn()`, remapping the 6 legal-basis enum values) —
  see design.md. 7 real rows on the shared dev Postgres instance today; none are destroyed.
- **Tests**: `tests/Service/AvgVerwerkingsregisterIntegrationTest.php`,
  `tests/Service/VerwerkingsactiviteitenControllerIntegrationTest.php`,
  `tests/Service/AvgRetentionServiceIntegrationTest.php`,
  `tests/Service/DsarServiceIntegrationTest.php`.
- **Specs**: `openregister/openspec/specs/verwerkingsregister-api/spec.md`,
  `openregister/openspec/specs/avg-verwerkingsregister/spec.md`.
- **Dependents**: no external consumers of the renamed routes/fields were found (opencatalogi,
  softwarecatalog do not call this surface); the frontend consumers in this same app are updated in
  lockstep.
