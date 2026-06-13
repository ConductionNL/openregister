# Tasks: Schema Versioning & Object Migration

## Phase 1 — Diff, classification, changelog

- [ ] 1.1 Add `lib/Service/Schema/SchemaDiffService.php`: structural diff of two definitions over `properties`/`required`/constraint keywords → typed `SchemaChangeSet`; pure classification function (`compatible` | `breaking`) per the spec's table; derive bump level (major/minor/patch).
- [ ] 1.2 Migration + entity/mapper for `oc_openregister_schema_changelog` (schema_id, version, classification, changes JSON, actor, acknowledged_by/at, created_at).
- [ ] 1.3 Hook the diff into the shared schema-update path (controller update, `schemas#uploadUpdate`, runtime schema API, configuration import): classification-driven version bump replaces the unconditional patch bump in `SchemaMapper`; changelog row written in the same transaction; metadata-only saves produce no bump/entry.
- [ ] 1.4 `GET /api/schemas/{id}/changelog` route + controller action (admin-gated, newest-first, paginated).
- [ ] 1.5 Unit tests: each classification rule (added optional, relaxed/tightened constraint, type change, removed property, required-without-default), bump levels, metadata-only no-op, transactionality.

## Phase 2 — Breaking-change acknowledgement gate

- [ ] 2.1 Enforce in the shared service path: `breaking` classification without `acknowledgeBreaking: true` → HTTP 409 with classification + change list (+ latest revalidation invalid count when present); record acknowledging actor/timestamp on the changelog row.
- [ ] 2.2 Configuration-import carve-out: implicit acknowledgement only for schemas owned by the importing app's own register; foreign schemas stay gated.
- [ ] 2.3 Verify the runtime schema API path (`openregister-runtime-schema-api`) flows through the gate; add an integration test for the 409 + acknowledged-update contract there.
- [ ] 2.4 Unit tests: 409 contract shape, acknowledged path, compatible-change bypass, import carve-out.

## Phase 3 — Population revalidation (impact analysis)

- [ ] 3.1 Migration + entity/mapper for `oc_openregister_schema_runs` (type, state machine, definition snapshot, plan, progress, report, cursor, started_by); side table for large per-object report entries; conditional uniqueness = one active run per schema (409 otherwise).
- [ ] 3.2 Add `lib/Service/Schema/SchemaRevalidationService.php` + `lib/BackgroundJob/SchemaRunJob.php`: batched iteration of the schema's non-deleted population via the magic mapper, validation via the existing `ValidateObject` handler against current or supplied proposed definition, per-object errors capped, progress + resumable cursor persisted per batch; dry-run guarantees zero mutation.
- [ ] 3.3 Current-definition runs update each object's validity status and backfill/refresh `schemaVersion` stamps (metadata-only update, no data/audit churn for the object content itself).
- [ ] 3.4 Routes + controller: `POST /api/schemas/{id}/revalidate`, `GET /api/schemas/{id}/runs`, `GET /api/schemas/{id}/runs/{run}` (admin-gated, gates 5/14/29).
- [ ] 3.5 Validity/`schemaVersion` filters on object listing (REST query params + faceting where applicable).
- [ ] 3.6 Tests: dry-run non-mutation, report correctness on a seeded mixed population, 409 on concurrent run, resumability after simulated worker restart, validity filter.

## Phase 4 — Migration engine

- [ ] 4.1 Add `lib/Service/Schema/SchemaMigrationService.php`: transform chain (`rename`, `setDefault`, `cast`, `drop`, `compute` via the existing Twig mapping engine); plan validation (unknown transform/field → 422); preview mode applying the chain to a bounded sample returning before/after pairs without persisting.
- [ ] 4.2 Execution in `SchemaRunJob`: batched load → transform → persist through the standard save pipeline under system context with run attribution (audit, content versions, events under bulk suppression, re-stamp `schemaVersion`); per-object failure recording; `stopOnError` policy; record `{uuid, preVersionId, postVersionId}` per touched object.
- [ ] 4.3 Routes + controller: `POST /api/schemas/{id}/migrations/preview`, `POST /api/schemas/{id}/migrations`.
- [ ] 4.4 Tests: each transform happy/edge path (uncastable value → failure entry, continue vs `stopOnError`), preview non-persistence, audit/version side-effects asserted, unchanged objects skipped.

## Phase 5 — Rollback

- [ ] 5.1 `SchemaMigrationService::rollback(run)`: restore each touched object's `preVersionId` through the save pipeline only when its current version still equals `postVersionId`; conflict-skip + report otherwise; run state → `rolled-back`; double rollback → 409.
- [ ] 5.2 Route + controller: `POST /api/schemas/{id}/runs/{run}/rollback`.
- [ ] 5.3 Tests: full rollback, conflict-skip on post-migration edit, double-rollback 409, rollback-of-failed-run (partial set).

## Phase 6 — Spec, frontend, docs

- [ ] 6.1 Sync `specs/schema-migration/spec.md` into `openspec/specs/` on archive.
- [ ] 6.2 Minimal UI: schema detail gains a Changelog tab, a "Check objects" (revalidation) action with run progress/report view, and a breaking-change acknowledgement dialog on save; migration plans via JSON editor + preview table (rich builder UI deferred).
- [ ] 6.3 Newman collection `tests/integration/openregister-schema-migration.postman_collection.json`: changelog read, dry-run lifecycle, 409 gate + acknowledged update, preview/execute/rollback happy path; wire into `tests/newman/run-all.sh`.
- [ ] 6.4 Docs page "Schema evolution & migrations" (classification table, run lifecycle, transform reference, rollback semantics); update `openregister-runtime-schema-api` docs to mention the gate. Bump `appinfo/info.xml` `<version>`.
