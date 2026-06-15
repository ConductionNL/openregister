# Integration-leaf foundation — public case-token links + page-level analytics series

## Problem
OpenRegister is the fleet foundation: leaf apps consume its integration-provider
surfaces rather than re-implementing them. Two leaf migrations in **procest** are
blocked because the foundation surface they need does not yet exist:

1. **`public-share` leaf** — procest wants to mint a public, token-based
   "track your case" link to a case object so a citizen with no Nextcloud
   account can follow their case. Today `SharesProvider` only `list()`s and
   `delete()`s NC **file** shares (it inherits `NotImplementedException` for
   `create()`). There is no surface to mint/revoke a public token bound to an
   *object*, and no anonymous resolve endpoint.

2. **`sla-dashboard` leaf** — procest's SLA dashboard needs a **page-level**
   chart fed pre-computed series (labels + datasets), not the per-object
   report-LINK rows `AnalyticsProvider` returns for the sidebar tab. There is
   no reusable surface where a leaf supplies a series and the registry exposes
   it as a renderable chart widget.

## Proposed Solution
Add two **additive, backward-compatible** foundation surfaces — no existing
public signature changes, so every other app keeps working.

### 1. Public case-token link surface (Shares provider)
- New `openregister_case_tokens` table + `CaseToken` entity + `CaseTokenMapper`.
- New `CaseTokenService` with `mint()` / `resolve()` / `revoke()`.
- `SharesProvider::create()` (was 501) mints a public token bound to an object
  when the payload selects `type: 'public-token'`; `SharesProvider::delete()`
  gains a `token:`-prefixed revoke path. The NC-file-share `list()`/`delete()`
  behaviour is untouched.
- New `#[PublicPage]` endpoint `GET /api/public/case-tokens/{token}` resolves a
  valid token to a **public-safe** view of the object. The resolve runs the
  canonical OR read path with `_rbac: true` — it does **not** bypass RBAC; the
  token is an addressing handle, never an authorisation grant. Unknown /
  revoked / expired tokens and RBAC-denied reads all return a uniform **404**
  (no enumeration oracle, ADR-005 fail-closed).

### 2. Page-level analytics series surface
- New `openregister_analytics_series` table + `AnalyticsSeries` entity +
  `AnalyticsSeriesMapper`.
- `IntegrationRegistry` gains `registerPageWidget()` / `listPageWidgets()` /
  `getPageWidget()` — a declarative, RBAC-scoped page-widget surface alongside
  (not replacing) the per-object provider registry.
- New `AnalyticsSeriesService` `register()` (upsert) / `fetch()` (RBAC-scoped),
  which also declares the matching chart page-widget on the registry.
- New endpoints `POST /api/integrations/analytics/series` and
  `GET /api/integrations/analytics/series/{seriesKey}`. OR owns the reusable
  persistence + render contract; the leaf owns the SLA maths.

## Impact
- Additive only: `SharesProvider::create()` now overrides the abstract 501;
  every other method signature is unchanged. New tables, services, controllers
  and routes; no migration of existing data.
- Unblocks procest's `public-share` and `sla-dashboard` leaf-migrations.
