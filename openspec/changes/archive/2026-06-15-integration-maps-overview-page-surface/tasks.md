# Tasks — Integration maps overview (page-level multi-object map surface)

## 1. Page-level map overview surface
- [x] 1.1 Add `MapsOverviewService` with `registerOverview()` (declares a `map` page widget on `IntegrationRegistry`, PDOK default base layer, overridable) and `queryPoints()` (RBAC-scoped marker extraction via the canonical OR read path).
- [x] 1.2 Reuse `GeoFeatureCollectionBuilder` to locate geometry; reduce to a representative `{lat,lng}` per object; derive a `label` from `@self`/name/title.
- [x] 1.3 Enforce RBAC in `queryPoints()` — `ObjectService::findAll(_rbac:true)` for non-admins, `_rbac:false` for admins; register/schema scope keys caller-immutable; cap at `MAX_POINTS`.
- [x] 1.4 Add `MapsOverviewController` with `#[NoAdminRequired]` `register()` + `points()`.
- [x] 1.5 Register routes `POST /api/integrations/maps/overviews` and `GET /api/integrations/maps/overviews/{register}/{schema}/points` in `appinfo/routes.php` (gate-14).

## 2. Tests
- [x] 2.1 `MapsOverviewServiceTest` — register declares `map` widget (+ PDOK default / override); rejects empty key / scope; anonymous write fail-closed.
- [x] 2.2 `MapsOverviewServiceTest` — `queryPoints()` extracts markers from Point/Polygon geometry, skips geometry-less objects.
- [x] 2.3 `MapsOverviewServiceTest` — `queryPoints()` reads with `_rbac:true` for anonymous/non-admin, `_rbac:false` for admin; scope keys caller-immutable; rejects empty scope.

## 3. Backward compatibility
- [x] 3.1 Verify no existing public signature changed (new service / controller / routes only; per-object `MapsProvider` untouched).
