# Schema Versioning & Object Migration

## Why

OpenRegister is a schema-driven register platform whose schemas change
over the life of a register — and today a schema edit is **live and
unmanaged**: the definition changes in place and every stored object
silently keeps whatever shape it had. The 2026-06-11 feature
re-evaluation (`FEATURE-REEVALUATION-2026-06-11/openregister.md`) rated
this the **highest-value expected gap** (EXPECTED-GAPS #1) and made it
recommendation 3: with `openregister-runtime-schema-api` in flight
(runtime schema edits from openbuild and the no-code layer),
*uncontrolled schema drift over live object populations becomes the
fleet's biggest data-integrity risk*.

The primitives half-exist, unconnected:

- `Schema.version` is auto-bumped (patch) on every update
  (`SchemaMapper`, "Set or update the version"), but nothing classifies
  the change, records what changed, or distinguishes a harmless
  description tweak from a breaking type change.
- `ObjectEntity.schemaVersion` exists as a column, but nothing manages
  the population: there is no way to ask "which objects no longer
  validate against the current definition?", let alone fix them.
- Validation (`ValidateObject`) runs on write only — existing objects
  are never re-checked, so a tightened constraint creates invisible
  invalid data that resurfaces as a 4xx on the object's *next*
  unrelated edit, in some other user's session.

Every comparable platform manages this: Airtable/Baserow/NocoDB
field-type conversions migrate or flag existing rows; headless CMSs
(Contentful, Sanity) have content migrations as a first-class CLI/API
concept; Dutch government register standards expect documented,
versioned model changes (GGM/VNG informatiemodellen are explicitly
versioned). A register platform that re-validates nothing after a model
change cannot honestly claim data integrity.

## What Changes

- **Schema change classification + changelog.** Every schema definition
  update is diffed against the previous definition and classified:
  `compatible` (added optional property, relaxed constraint,
  metadata/UI-only) bumps minor/patch; `breaking` (removed/renamed
  property, type change, tightened constraint, new required property
  without default) bumps major. The per-version diff summary is stored
  and queryable as the schema's changelog.
- **Population impact analysis (dry-run revalidation).** A new
  operation re-validates a schema's existing object population against
  the current (or a proposed) definition without mutating anything,
  reporting totals + per-object validation errors, batched in a
  background job for large populations.
- **Validity tracking on objects.** Objects record the schema version
  they last validated against (`ObjectEntity.schemaVersion`, today an
  unmanaged column) plus a validity status maintained by writes and
  revalidation runs; consumers can filter on it (e.g. "all objects
  invalid under the current version").
- **Managed migration runs.** A migration plan — an ordered list of
  declarative transforms (`rename`, `setDefault`, `cast`, `drop`,
  `compute`-from-template) — can be previewed against a sample,
  executed batched in the background with progress reporting, writes
  flowing through the normal save pipeline (audit trail, versions,
  events), and a final per-object report.
- **Rollback.** A completed or aborted migration run can be rolled
  back by restoring each touched object's pre-migration version via
  the existing content-versioning (time-travel) machinery.
- **Runtime-schema guardrail.** Schema updates classified `breaking`
  MUST be refused unless explicitly acknowledged
  (`acknowledgeBreaking: true`), and the response carries the impact
  summary — closing the loop with `openregister-runtime-schema-api`,
  whose callers (openbuild, virtual apps) otherwise push breaking edits
  with zero friction.
- New capability spec `specs/schema-migration/spec.md`.

## Problem

1. **Silent corruption window.** Tighten `minLength`, mark a property
   `required`, or change `string → integer`, and every pre-existing
   object is now invalid — undetected until the next write of each
   object fails in an unrelated user flow.
2. **No operator answer to "what would this change break?"** Schema
   editors fly blind: there is no impact report before or after a
   change, and no way to enumerate non-conforming objects.
3. **The no-code layer multiplies the risk.** The runtime schema API
   exists precisely so non-developers can evolve schemas from openbuild
   and virtual apps; shipping that without classification, impact
   analysis, and an acknowledgement gate hands the fleet's data
   integrity to a modal-free button press.
4. **Manual fixes don't scale and bypass nothing safely.** Today the
   only remedy is hand-editing objects or ad-hoc scripts against magic
   tables — outside audit, versioning, events, and RBAC.

## Proposed Solution

- `lib/Service/Schema/SchemaDiffService` — structural diff of two
  schema definitions → typed change list + `compatible`/`breaking`
  classification; invoked on every schema save (UI, upload, runtime
  API, configuration import); changelog persisted per version.
- `lib/Service/Schema/SchemaRevalidationService` + a queued background
  job — batched re-validation of a schema's population using the
  existing `ValidateObject` handler; results in a run report entity;
  updates each object's `schemaVersion`/validity status.
- `lib/Service/Schema/SchemaMigrationService` + job — executes a
  declarative transform plan in batches **through the standard object
  save pipeline** (so audit trail, content versioning, events, hooks,
  and RBAC system-context attribution all apply); run states
  `draft → previewed → running → completed | failed | rolled-back`.
- Rollback reuses content-versioning: each migrated object's
  pre-migration version id is recorded in the run report; rollback
  restores those versions (again through the save pipeline).
- API surface: schema changelog read; revalidation run CRUD
  (start/status/report); migration plan preview/execute/rollback;
  `acknowledgeBreaking` flag on the existing schema-update paths.

## Out of scope

- **Automatic transform inference** — the platform classifies and
  reports; humans (or apps) author the transform plan. No "AI guesses
  the rename" in this change.
- **Multi-schema/cross-register migrations** and relation re-pointing —
  one schema's population per run (relation integrity is
  `referential-integrity`'s domain).
- **Zero-downtime dual-version serving** (objects validating against
  either of two live versions) — single current version per schema
  stays the model.
- **Schema version pinning per consumer/app** — consumers always see
  the current definition.
- **The runtime schema API itself** — `openregister-runtime-schema-api`
  ships the API; this change adds the breaking-change gate it calls
  into.
- UI for authoring transform plans beyond a JSON editor + preview —
  rich migration UI can follow once the engine exists.

## See also

- `FEATURE-REEVALUATION-2026-06-11/openregister.md` — EXPECTED-GAPS #1
  and recommendation 3 (sequence ahead of / alongside
  `openregister-runtime-schema-api`).
- `openspec/changes/openregister-runtime-schema-api/` — the in-flight
  change that raises the stakes; its update path gains the
  `acknowledgeBreaking` gate.
- `openspec/specs/content-versioning/spec.md` — per-object versions and
  time travel reused for migration rollback.
- `openspec/specs/object-lifecycle/spec.md`,
  `lib/Service/Object/ValidateObject.php` — the write-path validation
  this change extends to populations.
- `lib/Db/SchemaMapper.php` (auto version bump) and
  `lib/Db/ObjectEntity.php` (`schemaVersion` column) — existing,
  currently unmanaged primitives this change connects.
- `openspec/specs/data-import-export/spec.md` — `rollbackImport`
  precedent for run-scoped rollback semantics.
