---
kind: code
---

# Per-register schema slug uniqueness

## Why

Schema slug uniqueness is still scoped too broadly, and the resolution used to
decide "update an existing schema" vs. "create a new one" during configuration
import can bind an importing register to a schema it does not own.

**Real incident.** OpenBuild's `automation` schema (`trigger:object`, carries
`applicationSlug`) was never created. A CRM app already had its own `automation`
schema (id 71, `trigger:string`, `n8nWorkflowId`). OpenBuild's import resolved
the existing schema by slug via a lookup that was not scoped to OpenBuild's own
register, found the CRM app's row, and reused it — so OpenBuild's register never
got its own `automation` schema, and every OpenBuild automation save failed
schema validation (400: unexpected `applicationSlug`, missing `n8nWorkflowId`
constraint mismatch). This class of slug collision recurs fleet-wide any time
two apps (or two registers) independently choose a common, descriptive slug
(`automation`, `conversation`, `order`, `task`, `contact`, …).

**The DB schema still enforces a coarser invariant than the domain needs.**
`openregister_schemas` carries a unique index `schemas_org_app_slug_unique` on
`(organisation, application, slug)` (added by
`Version1Date20260723000000`, part of the still-unarchived
`schema-slug-cross-app-scoping` change). That widened uniqueness from
per-organisation to per-application, but schemas are **many-to-many** with
registers (verified: 769 schemas are referenced by more than one register today,
some by as many as 6) — a single owning `application` column cannot express "no
two distinct schema rows share a slug within one register's set" for an app that
owns multiple registers, or for a schema-authoring flow where the app id is
absent or unreliable at import time.

`lib/Db/SchemaMapper.php` already carries the two building blocks this fix
needs: `findBySlug()` (~:593, org-scoped, the global-ish lookup) and
`findBySlugInIds()` (~:426, resolves a slug within an explicit id set — built for
runtime object resolution, not import). The import path
(`lib/Service/Configuration/ImportHandler.php::importSchema()`, ~:1502) does not
use the id-scoped lookup at all; it resolves by application (or, without an app
id, by the global org-scoped `find()`), which is exactly the gap the OpenBuild
incident fell through.

## What Changes

- **Scrap global/per-app slug uniqueness in the DB.** Drop the
  `schemas_org_app_slug_unique` index in a new, idempotent migration. Do **not**
  add a replacement unique index — schemas are many-to-many with registers, so
  "unique within a register's set" cannot be expressed as a single-table DB
  constraint over `openregister_schemas`. The invariant moves to the service
  layer.
- **Resolve schemas by slug within the target register's own schema-id set
  during import**, not globally and not merely per-application.
  `ImportHandler::importFromJson()` precomputes, per schema slug, the union of
  the on-disk schema ids already attached to the register(s) this import's
  `components.registers.*.schemas` declares for that slug, and
  `ImportHandler::importSchema()` resolves the existing schema via
  `SchemaMapper::findBySlugInIds()` against that set. Found → update in place
  (this is also what keeps a schema legitimately shared across multiple
  registers untouched — its id is already in every register that references
  it). Not found → create a **new** schema and attach it to the importing
  register, even when a different app/register already owns a schema with that
  slug. Schemas with no register context in this import (rare: a schema defined
  but not attached to any register in the same config) keep the previous
  app-scoped/global fallback, for backward compatibility.
- **Preserve the many-to-many model.** No `register_id` column is added to
  `openregister_schemas`; no schema is duplicated for a register that already
  legitimately shares it. The invariant is scoped: two **distinct** schema rows
  may not share a slug **within the same register's schema set**.

## Capabilities

### Modified Capabilities

- `data-import-export`: the configuration-import schema-resolution requirement
  gains register-scoped slug resolution, replacing the coarser
  application-scoped resolution as the primary decision (the app-scoped/global
  lookup remains only as a fallback for schemas with no register context in the
  import). The DB-level uniqueness requirement is narrowed: `openregister_schemas`
  no longer enforces any DB-level slug-uniqueness index; uniqueness within a
  register's schema set is a service-layer invariant.

## Impact

**Affected code**
- `lib/Service/Configuration/ImportHandler.php` — `importFromJson()` gains a
  precomputation step; `importSchema()`'s existing-schema resolution gains a
  register-scoped branch ahead of the app-scoped/global fallback (~:1194,
  ~:1502, ~:1791).
- `lib/Migration/VersionXXXXDate.php` (new) — drops
  `schemas_org_app_slug_unique`, idempotently.
- `lib/Db/SchemaMapper.php` — no change; `findBySlugInIds()` (~:426) is reused
  as-is.
- `appinfo/info.xml` — `<version>` bump for the new migration.

**Not in scope**
- Repairing the specific OpenBuild/CRM `automation` collision (schema id 71 vs.
  OpenBuild's own row) — a separate, post-merge re-import step the fleet
  coordinator runs once this ships. This change is app-agnostic.
- Archiving the still-open `schema-slug-cross-app-scoping` change — pre-existing
  repo state, unrelated to this change's correctness.
- Any change to register slug uniqueness (`registers_org_app_slug_unique` is
  untouched; registers are not many-to-many with anything).

**Dependents**: every app that imports its own configuration via
`ConfigurationService::importFromApp()` (all Conduction apps). Behaviour changes
**only** in genuine cross-register/cross-app slug-collision cases (which were
bugs): the importing register now gets its own schema instead of silently
reusing a foreign one. Re-imports of an app's own, previously-created schemas
are unaffected (same register, same slug → same row, updated in place).
