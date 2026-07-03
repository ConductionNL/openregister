---
kind: code
depends_on: []
new_capabilities:
  - mdm-frontend
modified_capabilities: []
---

# Change: mdm-frontend

## Why

ADR-045 makes OpenRegister the owner of the generic MDM / data-governance
**surface**: any register whose schema declares `x-openregister-quality`,
`x-openregister-dedup`, and/or `x-openregister-survivorship` should get the
steward-facing governance experience for free, rather than every leaf app
(pipelinq being the canonical instance) rebuilding it. Program change #1
(`mdm-surface-api`) shipped the read APIs — quality statistics, lowest-quality
listing, duplicate candidates — and change #2 (`mdm-survivorship-engine`)
materialises golden records with `attributeProvenance` on survivorship-enabled
objects. Both are **merged**. What is missing is the surface itself: a steward
today has an API but no view. This change is program step #3 — the OpenRegister
frontend that consumes those already-merged APIs and golden records.

## What Changes

- **Data Quality dashboard** view: a register+schema selector drives KPI cards
  (average score, good/fair/poor bucket counts), the 10-bucket score histogram,
  and a lowest-quality objects table. Consumes `GET /api/objects/quality/{register}/{schema}/stats`
  and `GET /api/objects/quality/{register}/{schema}`.
- **Duplicate Candidates** view: a register+schema selector drives a paginated,
  read-only candidate-pair list (`objectA` / `objectB` / `score` / `matchedOn`).
  Consumes `GET /api/objects/duplicates/{register}/{schema}`. Merge **execution**
  is explicitly out of scope (needs the reversible-merge backend follow-on).
- **Master-entity list** view: objects of a survivorship-enabled schema shown
  with `qualityScore` / `qualityStatus` columns, plus a golden-record detail
  panel rendering the materialised `attributeProvenance` (which source won each
  attribute). Consumes the existing object-list endpoint + the materialised
  golden record — no new backend.
- **Queue / sync-health** view: surfaces OpenRegister's **existing** webhook
  delivery / retry / dead-letter data (`GET /api/webhooks/logs` +
  `GET /api/webhooks/{id}/logs/stats`, which already returns
  total / success / failed / `pendingRetries`) as a queue-health summary. No new
  queue subsystem and — per the design decision below — **no new backend
  endpoint** in this change; a fleet-wide aggregate stats endpoint is noted as an
  optional follow-on (`mdm-webhook-health-api`) but is not required.
- **Manifest + navigation**: four `pages[]` entries and a grouped `menu[]`
  section (label "Data quality") in `src/manifest.json`, with the nav icons
  imported + registered in `src/main.js`.
- **Store module**: a single new Pinia module (`src/store/modules/quality.js`,
  `useQualityStore`) registered in `src/store/store.js`, wrapping the quality /
  duplicate / webhook-log reads (Options-API `defineStore` + `@nextcloud/axios`,
  matching the existing `reports.js` store).
- **i18n**: English source strings for all new labels, headings, and empty
  states.
- **Visual + e2e coverage**: a visual-regression spec and Playwright e2e
  coverage for each new page (ADR-004 / gate-26).

No existing spec's requirements change; no schema changes; no backend behaviour
is added (see the queue-health decision in `design.md`).

## Capabilities

### New Capabilities
- `mdm-frontend`: The register/schema-scoped steward-facing MDM surface in the
  OpenRegister Vue frontend — Data Quality dashboard, Duplicate Candidates
  (read-only), Master-entity list with golden-record detail, and a Queue /
  sync-health view — plus the manifest pages, grouped navigation, Pinia store
  module, and i18n that host them, all consuming the already-merged
  `mdm-surface-api` + `mdm-survivorship-engine` backends and OR's existing
  webhook infrastructure.

### Modified Capabilities
<!-- None. This change only consumes already-merged backend capabilities;
     no existing spec's requirements change. -->

## Impact

- **New frontend code**: `src/views/quality/` (four view components +
  golden-record detail + register/schema selector), `src/store/modules/quality.js`.
- **Modified**: `src/manifest.json` (four `pages[]` + one grouped `menu[]`
  section), `src/main.js` (nav-icon imports + registration), `src/store/store.js`
  (register `useQualityStore`), `src/l10n/` (English source strings).
- **APIs consumed (no change)**: `quality#stats`, `quality#index`,
  `duplicate#index`, `webhooks#allLogs`, `webhooks#logStats` — all already
  routed in `appinfo/routes.php` and marked `#[NoAdminRequired]`.
- **Dependencies**: none unmet — the backends (#1, #2) are merged into
  `development`; webhook infra pre-exists. `@conduction/nextcloud-vue` `Cn*`
  components + `NcSelect` are already app dependencies.
- **Downstream**: unblocks the pipelinq `mdm-consume-or-surface` migration
  (program change #5) — the steward deep-links to these OR views instead of
  pipelinq's app-local MDM module.
- **Out of scope (named follow-ons)**: merge-execution UI (reversible-merge
  backend follow-on), trust-configuration editing UI, GDPR/AVG DSAR workflow
  (ADR-047), the fleet-wide webhook-health aggregate endpoint
  (`mdm-webhook-health-api`), and the pipelinq migration itself.
