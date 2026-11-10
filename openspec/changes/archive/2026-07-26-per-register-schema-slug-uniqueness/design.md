---
kind: code
---

# Design — Per-register schema slug uniqueness

## Context

### Verified root cause

OpenBuild's `automation` schema (`trigger:object`, carries `applicationSlug`)
was never created because a CRM app already owned a schema with slug
`automation` (id 71, `trigger:string`, `n8nWorkflowId`). OpenBuild's import
resolved "does this schema already exist" too broadly, matched the CRM app's
row, and reused it instead of creating OpenBuild's own — so OpenBuild's register
never got an `automation` schema of its own shape, and every automation save
failed schema validation.

### The mechanism (verified, file:line)

- `lib/Db/SchemaMapper.php:593` — `findBySlug(slug, limit, offset, _rbac,
  _multitenancy)`: organisation-scoped only. Returns the first row matching
  `slug` (exact match, not lower-cased) within the caller's organisation filter.
  Not id/register-scoped.
- `lib/Db/SchemaMapper.php:426` — `findBySlugInIds(slug, schemaIds)`: matches
  `LOWER(slug)` against an explicit candidate id set. **Already exists**, already
  used by `ObjectService::setSchema()` for register-scoped runtime resolution
  (added by the still-open `schema-slug-cross-app-scoping` change), but **not
  used on the import path**.
- `lib/Db/SchemaMapper.php:384` — `findByApplicationAndSlug(slug, application)`:
  app-scoped lookup, added by the same prior change.
- `lib/Service/Configuration/ImportHandler.php:1502-1545` — the existing-schema
  resolution inside `importSchema()`. With an app id: `findByApplicationAndSlug()`
  (app-scoped). Without one: `find($slug, _multitenancy: false)` — the
  **global-ish** lookup, org-scoped only, first-match-wins. This is the exact
  branch the OpenBuild incident description names ("found via the global
  lookup").
- `lib/Service/Configuration/ImportHandler.php:1791-1964` — the schema import
  pass inside `importFromJson()`: a **flat, two-pass** loop over
  `components.schemas` (keyed by schema slug), independent of which register(s)
  will reference it. `components.registers.*.schemas` (raw slug lists, read
  **after** the schema pass, at :1971) is resolved against the in-memory
  `schemasMap` built by that same pass to attach ids to registers.
- DB: `openregister_schemas` carries `schemas_org_app_slug_unique` on
  `(organisation, application, slug)`, added by
  `lib/Migration/Version1Date20260723000000.php`. `openregister_registers`
  carries the equivalent `registers_org_app_slug_unique` — **untouched by this
  change** (registers are not many-to-many with anything, so per-application
  register-slug uniqueness is not the problem being solved here).
- Many-to-many, verified: 769 `Schema` rows are referenced by more than one
  `Register`'s `schemas` id list, some by up to 6 registers. No `register_id`
  column exists on `openregister_schemas`, and none is added by this change.

## Goals / Non-Goals

**Goals**
- A schema slug is unique **within a single register's schema set** — two
  distinct schema rows must not both carry the same slug while both are
  referenced by the same register.
- Two different registers (same app or different apps) MAY each own a distinct
  schema row with the same slug.
- A schema legitimately shared across multiple registers (the 769-schema case)
  stays exactly one row, untouched by an unrelated import.
- Re-importing the same app/register/slug combination updates the existing row
  in place (idempotent import — no duplicate growth on every boot).
- No new DB unique index; the invariant lives at the service layer because the
  M:N model cannot express "unique within a register's set" as a single-table
  constraint.

**Non-Goals**
- Repairing the specific OpenBuild vs. CRM `automation` collision (schema id 71)
  — a separate, post-merge re-import the fleet coordinator runs once this ships.
- Making schemas 1:1 with a register, or adding a `register_id` column.
  Explicitly rejected — would require duplicating all 769 shared schemas.
- Changing register slug uniqueness. `registers_org_app_slug_unique` is
  untouched.
- Archiving the prior `schema-slug-cross-app-scoping` change (pre-existing,
  unarchived repo state; unrelated to correctness of this change). Noted as a
  deviation, not fixed here.
- Fixing `ImportService::autoCreateRegisterFromBundle()`
  (`lib/Service/ImportService.php:846`), a narrower single-register bundle-import
  helper that also calls `importSchema()` without a register-scoped id set. It
  is idempotent by register slug already and is a much smaller collision
  surface (single-schema bundles, typically from one app's own CSV/register-bundle
  flow). Threading register context into it would require injecting
  `RegisterMapper` into `ImportService`, a new dependency with its own blast
  radius. Left as a known limitation (see Risks).

## Decisions

### D1 — Resolution scope: the union of the target register(s)' existing schema ids, computed once per `importFromJson()` call

**Decision.** Before the existing two-pass schema import loop runs, compute a
map `schemaSlug(lower) → int[]` from the **raw** (pre-mutation)
`components.registers.*.schemas` slug lists already present in the import
payload:

1. For every register declared in this import, if it lists `schemaSlug` in its
   `schemas` array, record that `schemaSlug` targets that register.
2. For every such register slug, look it up via `RegisterMapper::find()`
   (`_multitenancy: false`, mirroring the existing lookup convention at
   `importRegister():764`). If it does not exist yet (fresh import), it
   contributes an empty id set — there is nothing to resolve against, so the
   schema must be created. If it exists, its **current, pre-import**
   `getSchemas()` id list is the contribution.
3. Union the contributed id sets per schema slug (a slug can legitimately target
   more than one register in one import — the M:N case).

`importSchema()` gains an optional `?array $registerSchemaIds` parameter. When
non-null (this schema slug is declared by at least one register in this
import), it resolves the existing schema via
`SchemaMapper::findBySlugInIds($slug, $registerSchemaIds)` **instead of** the
app-scoped/global branch. When null (schema not attached to any register in
this config — a rare, standalone-definition case), the previous
app-scoped/global fallback is unchanged, preserving backward compatibility.

**Why compute this before the schema pass rather than move register import
first.** Reordering "registers, then schemas" would be a much larger,
riskier change: registers currently resolve their schema ids **from** the
schema pass's in-memory `schemasMap` (`:1975`), and multiple registers in one
config routinely reference the *same* schema definition intentionally (the M:N
sharing case) — that has to stay a single `importSchema()` call per unique
schema key, not one call per (schema, register) pair. Precomputing the target
id set from the **raw**, not-yet-mutated `components.registers` data (read
before the schema pass touches anything) gets the register-scoping the fix
needs without touching the pass ordering, the `schemasMap` sharing semantics, or
any of the ~90 existing `importSchema()`/`importFromJson()` unit tests' call
shapes (the new parameter is optional and appended last, so untouched call
sites — including 30+ positional-arg test calls — are unaffected).

### D2 — Not-found in the target register's set ⇒ create new, even if the slug exists elsewhere

**Decision.** When `findBySlugInIds()` returns null, `importSchema()` proceeds
to its existing create branch unconditionally — it does **not** fall back to a
global/app lookup to "reuse" a foreign schema. A same-slug row owned elsewhere
is only surfaced for observability (one `logger->info()` call), mirroring the
existing foreign-owner warning pattern the prior app-scoped change introduced
at `:1509-1527`.

This is the actual fix: previously, "not found in my scope" fell through to a
**broader** lookup that could still find and reuse a foreign row (that broader
lookup **is** how OpenBuild found the CRM app's `automation` #71). Now, "not
found in my scope" means "I need my own row," full stop.

### D3 — Found in the target register's set ⇒ update in place (not refuse)

**Decision.** Matches existing import idempotency semantics (version-gate +
content-diff, unchanged) rather than refusing. A register re-importing its own
previously-created schema (same slug, same register) must not accumulate
duplicate rows on every app boot — the existing version/force/content-diff logic
in `importSchema()` (`:1547-1596`) is reused untouched; only the **resolution**
that decides "existing" vs. "new" changes.

This also is what keeps the 769-shared-schema case correct without any special
casing: a schema already referenced by two registers has its id present in
**both** registers' existing `getSchemas()` sets, so the union always contains
it, `findBySlugInIds()` always finds it, and it is always updated in place —
never duplicated, never orphaned from either register.

### D4 — DB migration: drop the unique index, add no replacement

**Decision.** New `lib/Migration/VersionXXXXDate.php` drops
`schemas_org_app_slug_unique` on `openregister_schemas`, idempotently (checks
table + index existence before dropping, mirroring
`Version1Date20260723000000`'s `widenSlugUniqueIndex()` guard style). No
replacement index is added: `(register, slug)` is not expressible as a
single-table unique index because a schema's register membership is a
**separate join table** (`openregister_schemas` has no `register_id` column;
membership lives in each `Register` row's `schemas` JSON array), not a column on
`openregister_schemas` itself. Enforcing "unique within a register's set" at the
DB layer would require either a join-table redesign (out of scope, would touch
every schema/register read path) or a trigger (avoided — this codebase's other
invariants of this shape are already service-layer, e.g. the version-gate
content-diff logic in D3). The invariant is therefore enforced exclusively by
the resolve-before-create logic in D1/D2.

**Migration is strictly permissive, not corrective.** Dropping a unique index
never fails on existing data (it only removes a constraint); no backfill, no
data migration, no risk of failing on the 769 shared-schema rows or any other
existing content.

## Risks / Trade-offs

- **`ImportService::autoCreateRegisterFromBundle()` is not register-scoped by
  this change** (see Non-Goals). Its own single-register bundle-import flow
  remains on the old (app-less, global) `importSchema()` fallback. Mitigation:
  it is a narrower, already-idempotent-by-register-slug path (bundle import for
  CSV/single-register flows), not the configuration-import path the OpenBuild
  incident occurred on. Flagged for a future, separate change if a collision is
  observed there.
- **No DB-level guard against the invariant being violated by a future bug.**
  Because the invariant is service-layer only (D4), a bug in a *different* code
  path that creates a schema and attaches it to a register without going
  through `importSchema()`'s resolution could still produce a same-slug
  collision within one register's set, undetected by the DB. Mitigation: this
  mirrors how the codebase already enforces comparable cross-entity invariants
  (e.g. the deletion-audit-trail ordering, the version-gate content-diff) at the
  service layer with test coverage rather than a DB constraint; the read paths
  that matter (`ObjectService::setSchema()`) already resolve register-scoped via
  `findBySlugInIds()` per the prior change, so a collision would surface as
  "wrong schema resolved," not silent data corruption.
- **Precomputing existing register schema ids adds one extra `RegisterMapper::find()`
  call per register declared in an import that lists `schemas`.** Mitigation:
  bounded by the number of registers in one config (typically 1, occasionally a
  handful), cached per import call, negligible next to the schema import loop's
  own per-schema DB round-trips.

## Migration Plan

Additive/permissive only:
- The index drop cannot fail on existing data (see D4).
- `findBySlugInIds()` is reused as-is, no signature change.
- `importSchema()`'s new parameter is optional, appended last, defaults to
  `null` (falls back to previous behaviour) — every existing caller
  (`ImportService::autoCreateRegisterFromBundle()`, all 30+ direct-call unit
  tests) is unaffected unless `importFromJson()` supplies the new
  register-scoped context.

Rollback = revert the migration (re-add the index — safe, since the service
layer already prevents same-register duplicates) and revert the code change;
nothing persists a new column/table.

## Open Questions

1. Should `ImportService::autoCreateRegisterFromBundle()` get the same
   register-scoped treatment? Provisional: defer to a follow-up change once
   real collision evidence exists there (see Risks).
