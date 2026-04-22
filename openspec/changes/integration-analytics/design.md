# Design: Integration — Analytics

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths..

## Approach

`AnalyticsReportService` fetches report definitions + latest data points. Chart rendering reuses Analytics' visualization (iframe or direct chart component re-use) to avoid duplicating chart-library logic.

## Architecture Decisions

### AD-1: Render charts via Analytics' existing component, not re-implement

**Decision**: `CnAnalyticsCard` fetches Analytics' chart config + data and renders via a shared charting lib (apexcharts — already available via `@conduction/nextcloud-vue` shared deps). Does NOT re-implement Analytics' chart logic.

**Why**: Chart libraries are complex; duplicating is fragile. Reusing config-driven rendering keeps visual parity with Analytics app automatically.

**Trade-off**: Dependency on apexcharts config format. Acceptable — apexcharts is the dominant choice and already in deps.

### AD-2: Data refresh every 5 minutes on dashboards, on-demand elsewhere

**Decision**: Dashboard-surface cards auto-refresh every 5 minutes. Detail-page and single-entity surfaces refresh only when explicitly triggered (click or route re-entry).

**Why**: Dashboards are glance surfaces — stale data is worse than brief fetch cost. Detail views are focused work — extra refresh cost per view-open is noise.

## Files Affected

### Backend (new)
- `AnalyticsReportService`, `AnalyticsController`, `AnalyticsLink` entity + mapper + migration, `AnalyticsProvider`, unit tests

### Backend (modified)
- `Application.php`, `routes.php`

### Frontend (new)
- `CnAnalyticsTab/*`, `CnAnalyticsCard/*`, `src/integrations/builtin/analytics.js`, barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| Analytics app changes chart config format | Version-pinned adapter in `AnalyticsReportService`; fallback to link-out on unknown config |
| Large datasets slow render | Analytics' own aggregation; OR just presents |
| Report permissions differ per user | Analytics ACLs govern transitively; inaccessible reports hide |
