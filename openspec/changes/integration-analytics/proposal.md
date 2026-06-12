# Integration: Analytics

## Problem

NC Analytics provides data visualization widgets (KPIs, charts, reports) but they live in a separate app, not on the objects they describe. Embedding analytics on detail pages and dashboards closes the loop from object → aggregated insight.

## Context

- **Backend:** greenfield — wrap NC Analytics REST API (reports + datasets)
- **Required NC app:** `analytics`
- **Storage:** `link-table` (object/schema → report id)
- **Depends on:** `pluggable-integration-registry`

## Proposed Solution

`AnalyticsReportService` + `AnalyticsController` + `AnalyticsProvider` + `CnAnalyticsTab` + `CnAnalyticsCard`. Tab lists linked reports with inline chart previews. Widget embeds the report's visualization directly on dashboards and detail pages.

## Scope

**In scope:** Backend service wrapping Analytics, link table, provider, tab with inline chart previews, widget with embedded visualizations, registration, tests, nl+en.

**Out of scope:** Report authoring (Analytics app owns); dataset management; custom chart libraries beyond what Analytics exposes.

## Acceptance criteria

- [ ] Analytics tab appears when Analytics installed + schema has `analytics` in linkedTypes
- [ ] Tab lists linked reports with chart thumbnails
- [ ] Widget embeds chart on all 4 surfaces
- [ ] Reference-property `referenceType: 'analytics'` renders report chip
- [ ] Parity gate passes; nl+en done
