# Integration maps overview — page-level multi-object map render surface

## Problem
OpenRegister is the fleet foundation: leaf apps consume its integration
surfaces rather than re-implementing them. The per-object `MapsProvider`
(`integration-maps`) lists the NC Maps locations linked to **one** object as a
sidebar tab. There is no **page-level** surface that shows **many** objects of a
register/schema as markers on a **single** map.

**procest** (issue #112, "cases on map") needs exactly this: an overview that
plots every readable case as a marker on one map. Today a leaf would have to
re-implement object querying, geometry extraction and RBAC scoping — duplicating
foundation logic and risking IDOR. This parallels the page-level **analytics
series** surface just added in PR #151 (`IntegrationRegistry::registerPageWidget`
+ `AnalyticsSeriesController` register/fetch + a declarative `chart` widget).

## Proposed Solution
Add one **additive, backward-compatible** foundation surface — no existing
public signature changes; the per-object `MapsProvider` is untouched.

### Page-level map overview surface
- New `MapsOverviewService`:
  - `registerOverview()` declares a `map` page widget on the existing
    `IntegrationRegistry` (id `maps-overview:{key}`, type `map`, providerId
    `maps-overview`) carrying the register/schema scope, an optional default
    filter set, an optional geo-property hint and a **declarative base-layer
    config** (Dutch PDOK WMTS by default, fully overridable). Mirrors
    `AnalyticsSeriesService::register()`'s widget declaration.
  - `queryPoints()` resolves the marker point set for a register/schema (+
    optional filters) by running the **canonical OpenRegister read path**
    (`ObjectService::findAll()`) with `_rbac: true` for non-admins, then
    extracting `{id,label,lat,lng,register,schema,geometry}` from each returned
    object's geometry (reusing `GeoFeatureCollectionBuilder`). Objects without a
    geometry are skipped.
- New `MapsOverviewController` with two `#[NoAdminRequired]` endpoints:
  - `POST /api/integrations/maps/overviews` — declare/refresh a map widget.
  - `GET  /api/integrations/maps/overviews/{register}/{schema}/points` — query
    the RBAC-scoped marker set.
- OpenRegister owns the render contract (point shape + base layer); the leaf
  owns point selection (register / schema / filters).

### RBAC (ADR-005, fail-closed)
`queryPoints()` never bypasses RBAC: it delegates to the canonical read path
with `_rbac: true` for non-admins, so a caller only ever sees objects the public
group / their groups may read — an anonymous-equivalent caller sees only
public-readable objects. No object the caller cannot read can leak as a marker.
The register/schema scope keys are caller-immutable (cannot be spoofed through
the filter bag). The endpoint returns a uniform point list (empty when nothing
is readable), never an enumeration oracle.

## Why additive
- Brand-new service, controller and routes; no existing public method signature
  changes. `MapsProvider` (per-object sidebar) is unchanged.
- Reuses existing foundation primitives: `ObjectService::findAll()`,
  `GeoFeatureCollectionBuilder`, `IntegrationRegistry::registerPageWidget()`.

## Consumable by procest
procest's "cases on map" leaf declares one overview at bootstrap
(`registerOverview('cases-on-map', register, schema, …)`) and the map page calls
`GET …/{register}/{schema}/points` to plot the RBAC-scoped markers — no
per-app object querying, geometry parsing or RBAC code.
