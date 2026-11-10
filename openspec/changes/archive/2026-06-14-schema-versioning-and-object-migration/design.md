# Design: Schema Versioning & Object Migration

## Reuse analysis

- `Schema.version` + `SchemaMapper` auto patch-bump — already exists;
  becomes classification-driven (major/minor/patch) instead of
  always-patch.
- `ObjectEntity.schemaVersion` — existing column, today written but
  never managed; becomes the validity anchor.
- `lib/Service/Object/ValidateObject.php` / `ValidationHandler` — the
  single validator reused verbatim for population revalidation (one
  definition of "valid").
- Content versioning (`content-versioning` spec, time travel) —
  rollback substrate: record pre-migration version id per object,
  restore forward.
- Bulk save pipeline + bulk event suppression (`schema-hooks` "Bulk
  Operation Event Suppression") — migration batches write through
  `saveObject`/`SaveObjects`, inheriting audit, versions, events,
  RBAC system-context attribution (`system-context-owner-attribution`).
- `rollbackImport` (`data-import-export`) — precedent for run-scoped
  rollback semantics and report shape.
- Twig via MappingService — `compute` transform templating; same
  engine as webhook payload mapping and notification subjects.
- Background jobs — same QueuedJob patterns as
  `FileTextExtractionJob` / `WebhookDeliveryJob` for batched runs.

## Key decisions

### Classification is structural, not semantic

`SchemaDiffService::diff(old, new): SchemaChangeSet` walks
`properties`, `required`, and constraint keywords and emits typed
changes; classification is a pure function over the change kinds
(table in the spec). A rename is *detected* only when the caller
declares it (a rename plan step) — structurally, rename = remove + add,
both breaking, which is the safe default. No heuristics.

### One changelog entity, embedded acknowledgements

`oc_openregister_schema_changelog`: schema_id, version, classification,
changes (JSON), actor, acknowledged_by/at (nullable), created_at.
Written inside the same transaction as the schema update so version and
changelog can't drift.

### Runs are first-class entities

`oc_openregister_schema_runs`: id, schema_id, type
(`revalidation` | `migration` | `rollback`), state (`draft` →
`previewed` → `running` → `completed` | `failed` | `rolled-back`),
definition snapshot (for dry-runs against proposals), plan (JSON),
progress counters, report (JSON, per-object entries capped + paginated
out of a side table when large), started_by, timestamps. One active run
per schema enforced by a conditional unique index on
(schema_id, active-state).

### Migration writes are normal writes

The migration job loads objects in batches (magic-mapper pagination by
id), applies the transform chain in memory, and persists via the
standard save path under the system context with the run id as
attribution. Consequences accepted deliberately:

- audit + version rows for every touched object (that is the point);
- events/webhooks fire under existing bulk-suppression rules;
- RBAC: runs execute as system context; *starting* a run requires
  schema-admin rights (same authority as editing the schema).

### Rollback = restore-forward

Per touched object the run report stores `{uuid, preVersionId,
postVersionId}`. Rollback restores `preVersionId` content through the
save pipeline **only if** the object's current version still equals
`postVersionId`; otherwise conflict-skip + report. No history rewrite,
no table-level restore (register-level snapshot/restore is a separate
expected-gap, out of scope here).

### Breaking-change gate sits in the service layer

The gate lives in the shared schema-update service path so every entry
point (controller update, upload-update, runtime schema API,
configuration import) passes through it. Configuration import — which
replays definitions from apps at install/upgrade — sets
`acknowledgeBreaking` implicitly ONLY for schemas the importing app
owns (its own register), keeping app upgrades unblocked while foreign
edits stay gated. API error contract: HTTP 409 + classification +
change list (+ latest invalid count when a revalidation report exists).

## API sketch

| Verb | Route | Purpose |
|---|---|---|
| GET | `/api/schemas/{id}/changelog` | classified version history |
| POST | `/api/schemas/{id}/revalidate` | start run (optional proposed definition = dry-run) |
| GET | `/api/schemas/{id}/runs` / `/api/schemas/{id}/runs/{run}` | list / status+report |
| POST | `/api/schemas/{id}/migrations/preview` | plan → sample before/after |
| POST | `/api/schemas/{id}/migrations` | execute plan |
| POST | `/api/schemas/{id}/runs/{run}/rollback` | roll a migration run back |

All admin-gated (schema management authority); routed via
`appinfo/routes.php` with explicit auth posture (gates 5/14/29).

## Risks

- **Long runs on huge registers** — batched jobs with persisted
  cursors; progress queryable; runs resumable after worker restart.
- **Classification false-breaking** (overly conservative) — acceptable:
  cost is one acknowledgement flag, never silent data damage.
- **Version stamp backfill** — existing objects may have null
  `schemaVersion`; first revalidation run backfills it; filters treat
  null as "unknown", not invalid.
- **Interplay with `openregister-runtime-schema-api`** — sequencing:
  the gate is additive; if this change lands second, the runtime API
  gains the gate by virtue of sharing the service path (verified in
  tasks).
