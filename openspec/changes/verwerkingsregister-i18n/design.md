## Context

`lib/Db/Verwerkingsactiviteit.php` + `lib/Db/VerwerkingsactiviteitMapper.php` back a dedicated
Postgres table, `oc_openregister_verwerkingsactiviteiten` (created by
`lib/Migration/Version1Date20260430160000.php`, no later altering migration), with 13 Dutch-named
columns/properties plus a Dutch-valued `RECHTSGROND_VOCABULARY` enum constant. The table currently
holds **7 real rows on the shared dev Postgres instance** (confirmed by direct query; the
`rechtsgrond` values actually in use today are `wettelijke_verplichting` and `publieke_taak`, a
strict subset of the 6 possible values). `STATUS_VOCABULARY` (`concept`/`published`/`archived`) is
already English and is unaffected. The same Dutch identifiers propagate through
`VerwerkingsactiviteitenController`, `AvgRetentionService`, `ProcessingLogService`,
`appinfo/routes.php`, the operator-importable `lib/Resources/AvgSchemas/avg-bundle.json`, and the
Vue admin UI. See proposal.md for the "why" (this repo's English-only rule and its narrow
exemptions, neither of which applies here).

This is a pure rename: no field changes type, validation, cardinality, or semantics. See
proposal.md's "Bundled bugfix" note for the one intentional behavior change riding along with the
rename (`ProcessingLogService::fallbackActivityUuid()`'s invalid `'public-task'` legal-basis value).

## Goals / Non-Goals

**Goals:**
- Rename every Dutch identifier this change's proposal lists (entity fields, enum constant + its
  values, URL routes, `avg-bundle.json` `verwerker` schema) to English, in lockstep across DB
  column, PHP property, getter/setter, JSON key, and frontend binding.
- Preserve all 7 existing rows on the shared dev instance — no data loss, no destructive table
  recreate.
- Preserve the two carve-outs (VNG Verwerkingenlogging export field names; Art 30 PDF export's
  Dutch column labels) exactly as Dutch.

**Non-Goals:**
- No new fields, no new validation rules, no new API behavior beyond the renamed surface.
- No change to the `Verwerkingsactiviteit`/`VerwerkingsactiviteitenController` class names, the
  `oc_openregister_verwerkingsactiviteiten` table name, or the `openregister_verwerkingsactiviteiten`
  literal used by tests that target the table directly — only the table's *columns* are renamed.
- No reconciliation of the pre-existing, unrelated drift between the large aspirational
  `avg-verwerkingsregister` spec (which describes an unbuilt schema-based register, DPIA, and
  consent modules) and the actual dedicated-table implementation. That drift predates this change
  and is out of scope for an identifier-rename cleanup.
- No rewrite of historically-persisted `ObjectEntity.deleted` JSON blobs that already contain the
  old `bewaartermijn` key (see Decisions below).

## Decisions

### Full rename mapping

**`Verwerkingsactiviteit` entity fields** (DB column ↔ PHP property ↔ getter/setter ↔ JSON key, all
renamed together):

| Old (Dutch) | New (English) | DB column old → new |
|---|---|---|
| `naam` | `name` | `naam` → `name` |
| `beschrijving` | `description` | `beschrijving` → `description` |
| `doelbinding` | `purpose` | `doelbinding` → `purpose` |
| `rechtsgrond` | `legalBasis` | `rechtsgrond` → `legal_basis` |
| `categorieenBetrokkenen` | `dataSubjectCategories` | `categorieen_betrokkenen` → `data_subject_categories` |
| `categorieenPersoonsgegevens` | `personalDataCategories` | `categorieen_persoonsgegevens` → `personal_data_categories` |
| `bewaartermijn` | `retentionPeriod` | `bewaartermijn` → `retention_period` |
| `ontvangers` | `recipients` | `ontvangers` → `recipients` |
| `doorgifteBuitenEu` | `internationalTransfers` | `doorgifte_buiten_eu` → `international_transfers` |
| `technischeMaatregelen` | `technicalMeasures` | `technische_maatregelen` → `technical_measures` |
| `organisatorischeMaatregelen` | `organisationalMeasures` | `organisatorische_maatregelen` → `organisational_measures` |
| `verwerkingsverantwoordelijke` | `controller` | `verwerkingsverantwoordelijke` → `controller` |
| `contactgegevensFg` | `dpoContactDetails` | `contactgegevens_fg` → `dpo_contact_details` |

Unchanged (already English): `code`, `organisationId`, `status`, `uuid`, `created`, `updated`.

**Enum constant + values:**

| Old | New |
|---|---|
| `RECHTSGROND_VOCABULARY` (constant name) | `LEGAL_BASIS_VOCABULARY` |
| `toestemming` | `consent` |
| `overeenkomst` | `contract` |
| `wettelijke_verplichting` | `legal_obligation` |
| `vitaal_belang` | `vital_interests` |
| `publieke_taak` | `public_task` |
| `gerechtvaardigd_belang` | `legitimate_interest` |

`STATUS_VOCABULARY` is already `['concept', 'published', 'archived']` — verified, no change.

**URL routes** (`appinfo/routes.php`):

| Old | New |
|---|---|
| `/api/avg/verwerkingsactiviteiten[/{id}]` | `/api/avg/processing-activities[/{id}]` |
| `/api/avg/verantwoording` | `/api/avg/accountability` |

**`avg-bundle.json` `verwerker` schema** (register `avg`) → renamed to `processor`:

| Old | New |
|---|---|
| `verwerker` (schema slug/title) | `processor` |
| `naam` | `name` |
| `type` enum `anders` | `type` enum `other` (`saas`/`infra`/`support`/`consultancy` unchanged — already English/neutral) |
| `kvk` | `chamberOfCommerceNumber` |
| `vestiging` | `establishment` |
| `vestiging.land` | `establishment.country` |
| `vestiging.stad` | `establishment.city` |
| `vestiging.adres` | `establishment.address` |
| `vestiging.postcode` | `establishment.postalCode` |
| `contactpersoon` | `contactPerson` |
| `telefoon` | `phone` |
| `verwerkersovereenkomstUrl` | `agreementUrl` |
| `verwerkersovereenkomstDatum` | `agreementDate` |
| `subVerwerkers` | `subProcessors` |
| `subVerwerkers[].naam` | `subProcessors[].name` |
| `subVerwerkers[].land` | `subProcessors[].country` |
| `subVerwerkers[].doel` | `subProcessors[].purpose` |
| `doorgifteBuitenEu` | `internationalTransfers` |
| `doorgifteBuitenEu.ja` | `internationalTransfers.transferred` |
| `doorgifteBuitenEu.landen` | `internationalTransfers.countries` |
| `doorgifteBuitenEu.waarborgen` | `internationalTransfers.safeguards` |
| `status` enum `concept`/`actief`/`beeindigd` | `status` enum `draft`/`active`/`terminated` |

`email` is unchanged (already English). The `consent` and `dpia` schemas in the same bundle are
**not** touched — out of scope, no Dutch identifiers of theirs were named in the proposal.

### DB migration strategy: expand/contract, not destructive recreate

Two migration files, both guarded by `hasColumn()`/idempotent, run in the same `occ upgrade` pass
(this app upgrades under Nextcloud maintenance mode — there is no live-traffic window between them,
but the two-phase split still keeps each step trivially reversible and matches this codebase's
existing migration idioms for column renames):

1. **Expand** (`changeSchema()` + `postSchemaChange()`, earlier timestamp): for each of the 13
   fields, `if (!$schema->hasColumn('oc_openregister_verwerkingsactiviteiten', '<new_name>'))`, add
   the new column nullable with the same type as its old counterpart. In `postSchemaChange()`, copy
   data row-by-row (trivial at 7 rows) from each old column into its new column via
   `IDBConnection`; for `legal_basis`, remap through the 6-entry old→new value table above
   (defensively covering all 6 possible values even though only 2 are in use today), leaving `NULL`
   values `NULL` and leaving any unrecognized legacy value untouched rather than dropping it
   silently (so a genuinely unexpected value surfaces instead of being lost — a `WARN`-level log
   line on an unrecognized value is included in `postSchemaChange()`).
2. **Contract** (`changeSchema()`, later timestamp, same PR): for each of the 13 fields,
   `if ($schema->hasColumn('oc_openregister_verwerkingsactiviteiten', '<old_name>'))`, drop the old
   column. By this point the expand migration has already copied every row's data forward.

Both migrations are safe to run twice (idempotent via the `hasColumn()` guards) and safe against a
partially-applied prior run. The entity's `addType()` calls, getters/setters, and the mapper's
`orderBy()`/`validate()` are updated in the same PR to reference only the new column names — so
the deployed code and the post-contract schema are consistent from the moment the app boots on the
new version.

### `AvgRetentionService`'s two write paths are handled differently

- The **report-payload dict** (`~lines 149-186`) is a plain in-memory array returned from a service
  method — its keys (`naam`, `bewaartermijn`, ...) are renamed to the new field names with no
  migration concern; nothing persists this shape.
- The **`ObjectEntity::setDeleted()` write** (`~lines 305-311`) persists a `'bewaartermijn'` key
  into a *different* entity's `deleted` JSON metadata blob — a point-in-time deletion-audit
  snapshot, not a live queryable record. **Decision**: new writes after this change use the
  renamed `retentionPeriod` key; already-persisted historical `deleted` blobs are left exactly as
  they are. These are audit/evidence records — rewriting them would mean mutating historical
  compliance evidence, which is a worse outcome than a benign old/new key split across a rename
  boundary. Any future reader of this blob (there is none identified today) needs to accept both
  key spellings for old vs. new records, the same way any schema evolution of a JSON blob would.

### `ProcessingLogService::fallbackActivityUuid()` bugfix

Folded into the same edit as its Dutch→English setter rename (see proposal.md "Bundled bugfix"):
`setRechtsgrond('public-task')` → `setLegalBasis('public_task')`. The old call used a hyphenated
value that was never a member of `RECHTSGROND_VOCABULARY` (which required the underscored
`publieke_taak`), so the seeded fallback activity's legal basis has been silently invalid since it
was written. This is a one-line, low-risk fix riding on a line this change already has to touch.

### What stays Dutch, and why

Two carve-outs are **intentionally not renamed** — both are reflected as explicit notes in the
`avg-verwerkingsregister` spec delta so a future implementer doesn't "fix" them by mistake:

1. **The Art 30 PDF export's Dutch column labels** (`avg-verwerkingsregister` spec, "Export complete
   Art 30 register as structured document" scenario): `Naam, doel (doelbinding), grondslag, ...`.
   This is human-readable display text in a citizen-/AP-facing compliance PDF that follows the VNG
   model verwerkingsregister template — legitimate localized content, not a code identifier. The
   export itself is not yet built.
2. **The not-yet-implemented VNG "Verwerkingenlogging" export endpoint** (`avg-verwerkingsregister`
   spec, "Logging aligns with VNG Verwerkingenlogging API standard" scenario): `actie_id`,
   `verwerking_id`, `vertrouwelijkheid`, `verwerkende_organisatie`, etc. These field names are
   mandated verbatim by the external VNG Verwerkingenlogging API standard. When this endpoint is
   eventually built, it MUST keep these exact Dutch names — translating them would break
   interoperability with the standard it implements.

Also out of scope, unchanged, and not carve-outs so much as simply not part of this rename's
target list: `oc_openregister_processing_log` / `ProcessingLogEntry` (verified already fully
English — `activityId`, `objectUuid`, `subjectIdType`, etc.), Dutch legal/domain proper nouns
elsewhere in the codebase (BSN, Awb, bezwaarschrift, gemeente, Woo), the
`entity-relation-grondslagen` openspec directory name (the term appears only in comments/slugs, no
real identifiers), the `archivering-vernietiging` subsystem, and the `avg-bundle.json` `consent`
and `dpia` schemas (no Dutch identifiers of theirs were named in the proposal's scope).

### Declarative-vs-imperative decision

Not applicable. This change is a pure identifier rename: it introduces no new lifecycle,
aggregation, notification, or relation behavior, and no existing behavior of that kind changes
shape — every renamed field keeps its exact prior semantics, validation, and read/write path. There
is nothing here for a declarative-vs-imperative choice to apply to.

## Risks / Trade-offs

- **[Risk]** A missed call site among the many Dutch getter/setter/array-key references (controller
  hydration maps, service call sites, 4 PHPUnit test files, 3 Vue files) leaves a stale reference
  that only surfaces at runtime (PHP has no compile-time check on dynamic `$entity->{$setter}()`
  dispatch). → **Mitigation**: `hydrateFromPayload()`'s `stringFields`/`arrayFields` maps and the
  entity's own `@method` docblock annotations are renamed together in one PR/commit per file
  (see tasks.md), and the full PHPUnit suite (the 4 listed integration tests) plus a manual
  smoke-test of `EditActivityDialog.vue` create/edit/save is run before merge.
- **[Risk]** The two-migration expand/contract split still assumes both migrations land in the same
  release (per Non-Goals, this app does not support running old code against contracted-but-not-yet-
  copied schema). → **Mitigation**: both migration files ship in this same change/PR, and
  `hasColumn()` guards make re-running the whole pair after a partial failure safe.
- **[Risk]** The `legal_basis` remap table is defensive for all 6 possible old values, but if a row
  somehow holds a value outside those 6 (e.g. manually inserted), the expand migration leaves it
  untouched rather than nulling it, which means a genuinely invalid legacy value survives the rename
  unchanged instead of being caught. → **Trade-off accepted**: silently dropping an unrecognized
  value would be worse (data loss); the migration logs a warning for operator follow-up instead.
- **[Trade-off]** `avg-bundle.json`'s rename is a breaking change for any operator who already
  imported the old `verwerker` schema into their own register — their existing schema keeps the old
  Dutch keys (the bundle is a one-time import, not a live-synced template) and would only pick up
  the new names on a fresh re-import. Confirmed via prior investigation that no known live instance
  has imported this bundle, and it is not auto-imported by any repair step, so this risk is accepted
  as negligible today.

## Migration Plan

1. Land the DB migration pair (expand, then contract) alongside the entity/mapper/controller/service
   renames in one PR, so schema and code are never out of sync at boot.
2. Update `appinfo/routes.php` and the three Vue files in the same PR (no external consumer of the
   old routes was found, so there is no deprecation-window requirement).
3. Update the 4 PHPUnit integration test files' call sites in the same PR; run the full suite before
   merge.
4. Update `avg-bundle.json` in the same PR (no live-imported instance to migrate).
5. Update the two spec files (`verwerkingsregister-api`, `avg-verwerkingsregister`) via
   `openspec sync` after this change is verified and archived, per the normal OpenSpec flow.
6. No rollback path is needed beyond standard git revert + a follow-up migration pair reversing the
   column rename, since the expand phase never deletes data before the contract phase's `hasColumn`
   guard confirms the copy landed.
