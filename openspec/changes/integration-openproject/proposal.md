# Integration: OpenProject (External via OpenConnector)

## Problem

Many government workflows use OpenProject for project management alongside Nextcloud for collaboration. Cases and OR objects often correspond to OpenProject work packages; today there's no linkage. Users copy IDs or URLs manually, and visibility is lost.

This is the **first external-service integration** — proving the OpenConnector-routing pattern the umbrella established. Today this leaf is **partial** per the 2026-05-24 registry audit — the 364-line OpenConnector-routed provider works and returns real linked work packages, but the frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` uses the generic `leaf()` factory with no bespoke `CnOpenProjectTab` / `CnOpenProjectCard` (work-package list). This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like Procest and PipelinQ have no working integration UI path and reinvent project-management linkage locally.

## Context

- **Audit bucket**: partial (2026-05-24)
- **Current backend**: 364-line `OpenProjectProvider`, OpenConnector-routed — returns real linked work packages
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget (work-package list)
- **Target NC class(es)**: external — routed via OpenConnector source `openproject`; provider mirrors `IAppManager::isInstalled('openconnector')`
- **Storage strategy**: `external` (no local link table; OpenConnector pairing or query-time)
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)
- **First-of-kind risk:** the leaf proves both the integration registry's external path AND exposes rough edges in OpenConnector's external-service model — expect some umbrella refinements to fall out

## Proposed Solution

`OpenProjectProvider` declares `storage='external'` and references an `openproject` OpenConnector source. CRUD operations route through `ExternalIntegrationRouter` (the umbrella's dispatch helper) which invokes OpenConnector with object context. `CnOpenProjectTab` + `CnOpenProjectCard` render linked work packages with status, assignee, progress. Auth status surfaced via admin section (umbrella's unified auth UI). Provider mirrors `IAppManager::isInstalled('openconnector')` for `isEnabled()` and falls back to `IntegrationHealth::missingApp('openconnector')` when the OpenConnector app is absent or the `openproject` source is unconfigured.

## Scope

**In scope:** `OpenProjectProvider` with external storage, OpenConnector source config template, tab (link existing WP + status display), widget (4 surfaces), auth surface via admin UI, registration, tests, nl+en.

**Out of scope:** Modifying OpenConnector itself (uses as-is); OpenProject-side data modification beyond what external routing surfaces; OAuth flow implementation (OpenConnector owns).

## Acceptance criteria

- [ ] Provider appears in registry when OpenConnector source `openproject` is configured
- [ ] Admin UI shows auth status ("configured" / "missing" / "expired") with Configure button linking to OpenConnector
- [ ] Tab lists linked work packages with status/assignee/progress
- [ ] User can link an existing WP by id or URL
- [ ] Widget renders on all 4 surfaces (WP status badge style)
- [ ] Reference-property `referenceType: 'openproject'` renders WP chip
- [ ] When OpenConnector source is missing: integration hidden from registry; `health()` returns `unavailable`
- [ ] OCS capabilities advertises the integration with `authStatus` field
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real OCA/OpenConnector imports for the backing NC app (skip for `query-time` providers that genuinely should DB-query only) — this provider routes external via OpenConnector; the import is the OpenConnector source-call helper, not an `OCA\OpenProject\…` namespace which does not exist
- [ ] `health()` returns `IntegrationHealth::missingApp('openconnector')` when OpenConnector absent (or the `openproject` source missing); never throws
- [ ] PHPUnit tests cover: happy-path (OpenConnector + source configured + linked), absent-app (graceful empty), empty-result (source configured, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` wires the new bespoke `CnOpenProjectTab` + `CnOpenProjectCard` components
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
