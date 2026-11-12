# Tasks — mdm-reverse-fk-source-resolution

## 1. Annotation + validation

- [x] 1.1 Accept an optional `sourceLink` block (`mode`, `sourceSchema`, `referenceField`, optional `sourceRegister`) in `SurvivorshipAnnotationValidator` and `MergeAnnotationValidator`; `sourceLinkField` stays required only for embedded mode.
  - A `reverseFk` block missing `sourceSchema` or `referenceField` degrades to a logged warning + embedded fallback (import still succeeds).
  - Absent `sourceLink` ⇒ embedded mode; existing embedded schemas validate unchanged.

## 2. Shared source resolver

- [x] 2.1 Add `lib/Service/Survivorship/SourceRecordResolver.php` with `resolveSources(masterData, masterUuid, survivorshipConfig)` branching on `sourceLink.mode`.
  - Embedded branch reproduces the current logic (embedded records + `ObjectService::find` on uuid strings) exactly.
  - reverseFk branch queries `sourceSchema`/`sourceRegister` for objects where `referenceField === masterUuid`, RBAC + multitenancy scoped, returning each hit's `->getObject()`.
  - A master with no linked sources resolves to `[]` without error.
- [x] 2.2 Route `SurvivorshipRecomputeListener` through `SourceRecordResolver` (thread the saved object's uuid); delete its private embedded `loadSourceRecords`.

## 3. Merge relink/reverse (mode-aware)

- [x] 3.1 Route `MergeService::recomputeSurvivor` through `SourceRecordResolver` (thread `$intoObject`/`$fromObject` uuids).
- [x] 3.2 In reverseFk mode, make merge rewrite each losing master's source objects' `referenceField` to the survivor UUID (persisted per source); record `{sourceUuid, priorReference}` in the merge snapshot.
  - Embedded mode keeps the existing array-merge relink untouched.
- [x] 3.3 In reverseFk mode, make reversal restore each snapshot source's `priorReference` and recompute both masters' golden records.

## 4. Source-change recompute trigger

- [x] 4.1 Add a source-object change listener (`ObjectCreated`/`Updated`/`Deleted`) that finds master schemas whose `sourceLink.reverseFk.sourceSchema` matches the saved object's schema, resolves the referenced master(s) via `referenceField` (old + new value on reassignment), and recomputes each by re-persisting through `ObjectService`.
  - A recompute failure is logged and never aborts the source object's own save/delete.
  - Register the listener in `lib/AppInfo/Application.php` next to the survivorship listener.

## 5. Consumer + proof

- [x] 5.1 Flip pipelinq `masterEntity` `x-openregister-survivorship` + `x-openregister-merge` to `sourceLink` reverseFk (`sourceSchema: sourceRecord`, `referenceField: currentMasterEntity`) in `lib/Settings/register.d/90-master-data-management.json` (separate pipelinq `config` PR).
- [x] 5.2 Extend the `mdm-views-route-scoping-e2e` seed to create linked `sourceRecord` objects (two competing systems, different trust tiers) referencing the seeded master via `currentMasterEntity`; unskip the merge-execute+reverse and conflict-resolution specs.
- [x] 5.3 Run the true Playwright e2e on a fresh isolated instance to green (merge/conflict chains now project a populated golden record).

## 6. Unit tests + quality

- [x] 6.1 Unit-test `SourceRecordResolver` (embedded parity + reverseFk query + empty-source), merge reverseFk relink/reverse, and the source-change trigger; keep `composer test` + `phpstan`/`psalm` green.
  - Cover the malformed-`sourceLink` non-fatal fallback.
  - No regression in existing survivorship/merge suites (embedded mode).

## Acceptance criteria

- A reverse-FK master's golden record is projected from its linked source objects on save, on merge preview, and in the conflict view.
- Merging a reverse-FK loser into a survivor moves the loser's sources (back-reference rewrite) and recomputes the union; reversal restores them.
- Editing/deleting a source object recomputes its referenced master's golden record.
- All existing embedded-mode survivorship/merge behaviour is unchanged (default when no `sourceLink`).
- `composer test`, PHPStan, Psalm, and the extended e2e all pass.
