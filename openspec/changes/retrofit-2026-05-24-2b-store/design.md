# Design — 2b-store retrofit

## Why one small new capability instead of many

The scanner emitted a single `store` cluster, but the 27 methods cover 14 distinct backend domains. Minting one spec per store would generate noise (the backend domains already have specs) and inflate the spec count.

Conversely, lumping everything into a single "store" spec would mis-frame the architecture: the stores are intentionally thin reactive mirrors over the REST/GraphQL surfaces and gain nothing from a behavioral spec at the store layer.

The compromise applied here:
1. **Annotate domain-mirror stores as cross-capability** against their backend spec — same pattern used by `searchTrail.js`/`auditTrail.js` (already annotated against `audit-trail-immutable` in retrofit-2026-04-23).
2. **Mint exactly one small capability** (`frontend-ui-shell`) for the genuine UI-shell state machine (navigation + cross-section admin settings panel) that has no backend-domain counterpart.

## Why endpoints.ts is dropped

The custom-endpoint feature (configurable REST endpoint registry) has Entity + Mapper + Controller + Service in `lib/` but no corresponding capability spec. Annotating the frontend store alone would create a dangling reference. The miss is filed as a coverage gap for a future scan pass.

## Why dashboard.js + reports.js share `rapportage-bi-export`

`reports.js` is the rapportage list/widget shell (per-widget aggregation/GraphQL/statistics fetching with memo cache); `dashboard.js` is the legacy built-in registers-overview dashboard that uses the same chart-data endpoints. Both consume the chart-data API documented in `rapportage-bi-export#Requirement: The system MUST provide chart data API endpoints for frontend visualization`.

## Why navigation + settings get separate REQs

They're distinct concerns:
- **navigation** is a pure transient UI controller (which menu is selected, which modal/dialog is open, which sidebar sections are collapsed). It has no backend.
- **settings** owns long-lived async fetches against several backend services (SOLR, RBAC, multi-tenancy, retention, cache) and exposes their loaded state to the admin settings panels. It coordinates loading flags and toast notifications.

Each needs its own behavioral contract.
