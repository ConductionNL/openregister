# Tasks — Integration-leaf foundation (shares case-tokens + analytics series)

## 1. Public case-token link surface
- [x] 1.1 Add `openregister_case_tokens` table (migration `Version1Date20260615000000`).
- [x] 1.2 Add `CaseToken` entity + `CaseTokenMapper` (findByToken/findByObjectUuid/findById).
- [x] 1.3 Add `CaseTokenService` (mint / resolve / revoke / listForObject).
- [x] 1.4 Override `SharesProvider::create()` to mint a `public-token`; extend `delete()` with a `token:` revoke path (NC file-share paths unchanged).
- [x] 1.5 Add `#[PublicPage]` `CaseTokenController::resolve()` + route `GET /api/public/case-tokens/{token}`.
- [x] 1.6 Wire `CaseTokenService` + `CaseTokenController` in `Application.php`.

## 2. Page-level analytics series surface
- [x] 2.1 Add `openregister_analytics_series` table (same migration).
- [x] 2.2 Add `AnalyticsSeries` entity + `AnalyticsSeriesMapper` (findByKey/findByScope).
- [x] 2.3 Add `IntegrationRegistry::registerPageWidget()/listPageWidgets()/getPageWidget()`.
- [x] 2.4 Add `AnalyticsSeriesService` (register upsert / fetch RBAC-scoped) declaring the page widget.
- [x] 2.5 Add `AnalyticsSeriesController` (register / fetch) + routes under `/api/integrations/analytics/series`.
- [x] 2.6 Wire `AnalyticsSeriesService` + `AnalyticsSeriesController` in `Application.php`.

## 3. Tests
- [x] 3.1 `CaseTokenServiceTest` — mint/resolve/revoke/expire + RBAC-respecting public read.
- [x] 3.2 `AnalyticsSeriesServiceTest` — register/upsert/fetch + visibility RBAC.
- [x] 3.3 `IntegrationRegistryTest` — page-widget register/list/get + duplicate/missing-id.
- [x] 3.4 `SharesProviderTest` — create-mints-token + delete-revokes-token + 501 fallback.

## 4. Backward compatibility
- [x] 4.1 Verify no existing public signature changed (additive create() override + new methods only).
