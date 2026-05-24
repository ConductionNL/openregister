# Integration: Analytics

## Problem

NC Analytics provides data visualization widgets (KPIs, charts, reports) but they live in a separate app, not on the objects they describe. Embedding analytics on detail pages and dashboards closes the loop from object → aggregated insight. Today this leaf is **stub** per the 2026-05-24 registry audit — `AnalyticsProvider.php` is a 137-line copy-paste of the MarkerLookupTrait template with no `OCA\Analytics\…` imports and `getLinkedItems()` returns `[]`. This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like PipelinQ and MyDash have no working integration path and reinvent report-embedding locally.

## Context

- **Audit bucket**: stub (2026-05-24)
- **Current backend**: 137-line MarkerLookupTrait template, no `OCA\Analytics\…` imports; `getLinkedItems()` returns `[]`
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget; backend returns `[]` so the tab is empty
- **Target NC class(es)**: `OCA\Analytics\Service\ReportService`
- **Storage strategy**: `link-table` (object/schema → report id)
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)

## Proposed Solution

`AnalyticsReportService` + `AnalyticsController` + `AnalyticsProvider` + `CnAnalyticsTab` + `CnAnalyticsCard`. Tab lists linked reports with inline chart previews. Widget embeds the report's visualization directly on dashboards and detail pages. Provider imports `OCA\Analytics\Service\ReportService` for the linked-report query and falls back to `IntegrationHealth::missingApp('analytics')` when NC Analytics is not installed.

## Scope

**In scope:** Backend service wrapping Analytics, link table, provider, tab with inline chart previews, widget with embedded visualizations, registration, tests, nl+en.

**Out of scope:** Report authoring (Analytics app owns); dataset management; custom chart libraries beyond what Analytics exposes.

## Acceptance criteria

- [ ] Analytics tab appears when Analytics installed + schema has `analytics` in linkedTypes
- [ ] Tab lists linked reports with chart thumbnails
- [ ] Widget embeds chart on all 4 surfaces
- [ ] Reference-property `referenceType: 'analytics'` renders report chip
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCA\Analytics\Service\ReportService` import for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('analytics')` when NC Analytics absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` keeps generic `leaf()` shell with notes — bespoke Tab + Widget components are OUT of this change's scope; file follow-up if needed
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
