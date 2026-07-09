---
kind: code
depends_on: []
---

## Why

The archived `mdm-foundation` change gave OpenRegister the MDM **primitives** —
declarative quality scoring (`x-openregister-quality`), duplicate detection
(`x-openregister-dedup`), a similarity calculator, and on-save materialisation
of `qualityScore` / `qualityStatus` onto every object. Those primitives are the
engine, not the surface. Today every app that acquires master data (pipelinq is
the canonical instance) rebuilds the steward-facing **surface** — quality
dashboards, duplicate-candidate lists, master-entity tables — on top of that
engine, in app code. ADR-045 rules that surface must be a register/schema-scoped
OpenRegister capability so every scored schema gets governance for free, driven
by schema metadata rather than per-app rebuilds.

This change is the **head of the ADR-045 delivery chain**. It ships the first,
smallest slice: a read-only REST aggregation surface over the already-scored
objects. It builds directly on the merged `mdm-foundation` primitives (hence
`depends_on: []` — that dependency is already archived and live) and unlocks the
follow-on changes described below.

## What Changes

- Add **`QualityStatisticsService`** — given `(register, schema)`, returns the
  average `qualityScore`, counts per status bucket (`good` / `fair` / `poor`), a
  score-distribution histogram, and the lowest-N objects by `qualityScore`. It
  reads the already-materialised `qualityScore` / `qualityStatus` fields via
  `ObjectService::findAll` (RBAC + tenant scoped) and reuses
  `QualityScorer::status()` thresholds. No re-scoring; it aggregates what the
  on-save listener already wrote.
- Add **`QualityController`** — two authenticated GET endpoints: schema quality
  statistics, and a filterable/sortable/paginated lowest-quality object list.
- Add **`DuplicateController`** — one authenticated GET endpoint returning
  paginated duplicate-candidate pairs from the existing
  `DuplicateDetectionService::findDuplicates(register, schema, matchRules?,
  threshold?)`. Read-only: **no** merge action in this change.
- Register all routes in `appinfo/routes.php` with the correct Nextcloud auth
  posture (`@NoAdminRequired` — authenticated steward endpoints, RBAC enforced
  in the service layer, **not** public).
- Add **ObjectService aggregation helpers only if required** — prefer expressing
  the reads through existing `findAll` config (ADR-011); a helper is added only
  where a query genuinely cannot be expressed with the existing surface.

**Explicitly out of scope (follow-on ADR-045 changes — not built here):**
- `mdm-survivorship-engine` — golden-record resolution from trust-tiered
  sources, entity-type-agnostic, driven by `x-openregister-survivorship`.
- `mdm-frontend` — the steward-facing Vue surface (dashboards, merge wizard,
  master-entity list) that consumes these read endpoints.
- GDPR/AVG generalisation, and the pipelinq `mdm-consume-or-surface` migration
  that deletes app-local MDM code in favour of schema config + a deep-link.
- Any merge / survivorship / trust / downstream-sync behaviour. This change is a
  read/aggregation surface only.

## Capabilities

### New Capabilities
- `mdm-quality-api`: the register/schema-scoped, read-only MDM read and
  aggregation REST surface — quality statistics, lowest-quality object listing,
  and duplicate-candidate listing — over objects already scored by the
  `mdm-foundation` primitives.

### Modified Capabilities
<!-- None. This change CONSUMES data-quality-scoring and duplicate-detection; it
     does not change their requirements. They are referenced, not modified. -->

## Impact

- **New code**: `lib/Service/Quality/QualityStatisticsService.php`,
  `lib/Controller/QualityController.php`, `lib/Controller/DuplicateController.php`;
  new route entries in `appinfo/routes.php`.
- **Possibly touched**: `lib/Service/ObjectService.php` — only if an aggregation
  helper is unavoidable (ADR-011 reuse preferred).
- **Consumes (unchanged)**: `QualityScorer`, `DuplicateDetectionService`,
  `SimilarityCalculator`, `ObjectService::findAll` / `count`.
- **APIs**: new authenticated `GET` endpoints under `/api/...`; no breaking
  changes to existing routes.
- **Downstream**: `mdm-frontend` will consume these endpoints; pipelinq's
  eventual `mdm-consume-or-surface` migration depends on this surface being
  stable. No app is migrated by this change.
