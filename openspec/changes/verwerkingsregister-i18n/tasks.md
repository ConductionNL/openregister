## 1. Database migration (expand/contract, data-preserving)

- [ ] 1.1 Add expand migration: new nullable columns `name`, `description`, `purpose`,
  `legal_basis`, `data_subject_categories`, `personal_data_categories`, `retention_period`,
  `recipients`, `international_transfers`, `technical_measures`, `organisational_measures`,
  `controller`, `dpo_contact_details` on `oc_openregister_verwerkingsactiviteiten`, each guarded by
  `hasColumn()`; `postSchemaChange()` copies data from the 13 old Dutch columns into the new ones,
  remapping all 6 `rechtsgrond` → `legal_basis` values defensively (only `wettelijke_verplichting`/
  `publieke_taak` are in use on the shared dev instance today), logging a warning on any
  unrecognized legacy value instead of dropping it.
- [ ] 1.2 Add contract migration (later timestamp, same PR): drop the 13 old Dutch-named columns,
  each guarded by `hasColumn()`.
- [ ] 1.3 Run both migrations against the shared dev Postgres instance and verify all 7 existing
  rows survive with correctly remapped `legal_basis` values.

## 2. Backend entity, mapper, and vocabulary rename

- [ ] 2.1 Rename all 13 field properties, getters/setters, `@method` docblocks, `addType()` calls,
  and `jsonSerialize()` keys on `lib/Db/Verwerkingsactiviteit.php` per the design.md mapping table;
  rename `RECHTSGROND_VOCABULARY` → `LEGAL_BASIS_VOCABULARY` and its 6 values; rename
  `isValidRechtsgrond()` → `isValidLegalBasis()`; confirm `STATUS_VOCABULARY` needs no change.
- [ ] 2.2 Update `lib/Db/VerwerkingsactiviteitMapper.php`: rename `findAll()`'s
  `orderBy('naam', ...)` literal, and update `validate()`'s error messages to reference the new
  field names (`name`, `purpose`, `legalBasis`) while keeping the same AVG article citations.

## 3. Controller, service, and route rename

- [ ] 3.1 Update `lib/Controller/VerwerkingsactiviteitenController.php`'s `hydrateFromPayload()`
  `stringFields`/`arrayFields` maps to the renamed keys/setters; leave the entity/table name
  references in error strings (`"Verwerkingsactiviteit not found"`, etc.) as-is.
- [ ] 3.2 Rename URL segments in `appinfo/routes.php`: `/api/avg/verwerkingsactiviteiten` →
  `/api/avg/processing-activities`, `/api/avg/verantwoording` → `/api/avg/accountability`.
- [ ] 3.3 Update `lib/Service/AvgRetentionService.php`: rename `getBewaartermijn()` call and the
  report-payload dict keys to English; switch the `ObjectEntity::setDeleted()` write to the new
  `retentionPeriod` key for new writes only (do not touch historically-persisted `deleted` blobs).
- [ ] 3.4 Update `lib/Service/ProcessingLogService.php::fallbackActivityUuid()`: rename its Dutch
  setter calls to `setName`/`setPurpose`/`setLegalBasis`/etc., and fix the pre-existing
  `setRechtsgrond('public-task')` bug to `setLegalBasis('public_task')` in the same edit.
- [ ] 3.5 Rename `lib/Controller/DsarController.php`'s `inzage()`/`portabiliteit()`/`vergetelheid()`/
  `rectificatie()` methods to `access()`/`portability()`/`erasure()`/`rectification()`, rename
  `lib/Controller/AuditTrailController.php::inzageverzoek()` to `subjectAuditTrail()`, and update the
  corresponding `dsar#*`/`auditTrail#inzageverzoek` route names and URL segments in
  `appinfo/routes.php` (`/api/avg/inzage`→`/api/avg/access`, etc.;
  `/api/audit-trails/inzageverzoek`→`/api/audit-trails/subject-lookup`) — no DB migration involved,
  pure method/route rename (see design.md).

## 4. avg-bundle.json processor schema rename

- [ ] 4.1 Rename the `verwerker` schema (slug, title, description) to `processor` in
  `lib/Resources/AvgSchemas/avg-bundle.json`, and rename its properties per the design.md mapping
  table (`naam`→`name`, `kvk`→`chamberOfCommerceNumber`, `vestiging`→`establishment` with nested
  `land`/`stad`/`adres`/`postcode`, `contactpersoon`→`contactPerson`, `telefoon`→`phone`,
  `verwerkersovereenkomstUrl`/`Datum`→`agreementUrl`/`agreementDate`, `subVerwerkers`→
  `subProcessors` with nested `naam`/`land`/`doel`→`name`/`country`/`purpose`,
  `doorgifteBuitenEu`→`internationalTransfers` with nested `ja`/`landen`/`waarborgen`→
  `transferred`/`countries`/`safeguards`, `type` enum `anders`→`other`, `status` enum
  `concept`/`actief`/`beeindigd`→`draft`/`active`/`terminated`); update the `required` array and
  the register's `schemas` list to reference `processor`.

## 5. Frontend rename

- [ ] 5.1 Update `src/dialogs/avg/EditActivityDialog.vue`: rename all `form.<dutchField>` v-model
  bindings and the `data()` initializer's response-object field reads to the new English keys.
- [ ] 5.2 Update `src/views/avg/AvgIndex.vue`: rename the read-only `a.naam`/`a.rechtsgrond`/
  `a.bewaartermijn` bindings to `a.name`/`a.legalBasis`/`a.retentionPeriod`.
- [ ] 5.3 Update `src/store/modules/avg.js`: change the URL segments to
  `processing-activities`/`accountability`/`access`/`portability`/`erasure`/`rectification` (no
  field-name-specific code to change here).
- [ ] 5.4 Update `src/views/avg/AvgIndex.vue`'s DSAR action calls (currently hitting
  `inzage`/`portabiliteit`/`vergetelheid`/`rectificatie`) to call the renamed routes.

## 6. Test updates

- [ ] 6.1 Update `tests/Service/AvgVerwerkingsregisterIntegrationTest.php`,
  `tests/Service/VerwerkingsactiviteitenControllerIntegrationTest.php`,
  `tests/Service/AvgRetentionServiceIntegrationTest.php`,
  `tests/Service/DsarServiceIntegrationTest.php`, and `tests/Unit/Controller/AuditTrailControllerTest.php`:
  rename all Dutch getter/setter/array-key/method-name call sites to the renamed equivalents; verify
  the `openregister_verwerkingsactiviteiten` table-name literal (delete queries etc.) is left
  untouched since only columns are renamed. Note: `DsarController` has no dedicated controller-level
  test today (only its underlying `DsarService` is covered) — a pre-existing coverage gap, not
  something this rename needs to fix.
- [ ] 6.2 Run the full PHPUnit suite and confirm all 4 updated test files pass against the migrated
  schema.

## 7. Verification

- [ ] 7.1 Manually smoke-test `EditActivityDialog.vue` create/edit/save and `AvgIndex.vue`'s list
  view against the shared dev instance to confirm the renamed fields round-trip correctly through
  the renamed routes.
- [ ] 7.2 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and confirm no new findings
  from the rename.

## Acceptance Criteria

- All 13 `Verwerkingsactiviteit` fields, the `LEGAL_BASIS_VOCABULARY` constant and its 6 values, the
  two `VerwerkingsactiviteitenController` URL routes, the four `DsarController` methods/routes, the
  one `AuditTrailController` method/route, and the `avg-bundle.json` `processor` schema use English
  identifiers with no remaining Dutch field/method/route names in code (excluding the two explicit
  spec carve-outs).
- The DB migration is data-preserving: all 7 existing rows on the shared dev instance retain their
  data after both migrations run, with `legal_basis` values correctly remapped.
- No historically-persisted `ObjectEntity.deleted` JSON blob is rewritten by this change.
- The `setRechtsgrond('public-task')` bug in `ProcessingLogService::fallbackActivityUuid()` is fixed
  to `setLegalBasis('public_task')`.
- The `verwerkingsregister-api` and `avg-verwerkingsregister` spec deltas are synced into the main
  specs, with the VNG Verwerkingenlogging field names and the Art 30 PDF export's Dutch column
  labels left untouched.
- All 4 updated PHPUnit integration test files pass; `composer check:strict` reports no new
  findings.
