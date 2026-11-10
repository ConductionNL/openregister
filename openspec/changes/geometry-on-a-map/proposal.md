---
kind: code
depends_on: []
---

# Proposal: geometry-on-a-map

## Summary

A register user sees the records of a schema that carries a geometry on a map, draws an area to find the records inside it, and cannot save a record whose geometry is not valid GeoJSON. The API for points, GeoJSON, WFS and the area search is built; the map screen and the save-time validation are not.

## The rows this closes

Source matrix: openregister `openspec/parity/capabilities.json` (comparedOn 2026-09-25). Each row is `building`: part of it works today. This change builds the missing half; the row stays `building` with `built.change` naming this change until it is built.

### mod-geometry, store a location or an area on a record as a map geometry

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `modelling`, source `competitor-derived`.

Matrix evidence, verbatim:

> Geometry stored in a JSON property and served by ObjectsController.php:2188 geoJson / wfs / geo-search (routes.php:1166-1168, lib/Service/Geo/*); src/views/object/MapView.vue:61 states it has no route or importer; GeoJsonGeometryValidator has no caller in lib

Matrix note, verbatim:

> No map screen and geometry is not validated on save.

Competitor cells rated `yes`, verbatim:

- objects-api: source read at 4.2.1, not driven: objects-api:src/objects/core/models.py:331 GeometryField (PostGIS) on each record; objects-api:src/objects/core/models.py:135 allow_geometry per type; objects-api:src/objects/api/validators.py:168 GeometryValidator; CRS headers enforced objects-api:src/objects/api/mixins.py:39
- directus: source read at v12.4.1, not driven: directus:packages/constants/src/fields.ts:36 geometry, geometry.Point, geometry.Polygon types; directus:app/src/interfaces/map/index.ts:8 map editor
- nocodb: source read at 2026.09.0, not driven: nocodb:packages/nocodb-sdk/src/lib/UITypes.ts:29 GeoData (lat/long point, nc-gui/components/cell/GeoData.vue); :46 Geometry for database geometry columns. Areas only via a pass-through database Geometry column

### rec-map, see records on a map by their location

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `records`, source `competitor-derived`.

Matrix evidence, verbatim:

> API: GET /api/integrations/maps/overviews/{register}/{schema}/points appinfo/routes.php:1010 -> lib/Controller/MapsOverviewController.php:148. src/views/object/MapView.vue exists but git grep MapView in src finds no importer; no manifest page.

Matrix note, verbatim:

> MapView.vue is dead: no page or component mounts it.

Competitor cells rated `yes`, verbatim:

- directus: source read at v12.4.1, not driven: directus:app/src/layouts/map/index.ts:23 map layout over a geometry field
- nocodb: source read at 2026.09.0, not driven: nocodb:packages/nocodb/src/controllers/maps.controller.ts:25 GET and :34 POST map views; nocodb:packages/nc-gui/components/smartsheet/Map.vue plots GeoData markers; docs https://nocodb.com/docs/product/account-settings/cloud-enterprise-edition/community-vs-paid-editions lists Map view in Community Edition

### srch-geo, find records inside an area on a map

Own rating `partial`, built.state `building`, owner `ConductionNL/openregister`, area `search`, source `competitor-derived`.

Matrix evidence, verbatim:

> lib/Controller/ObjectsController.php:2129 geoSearch (GeoJSON within/intersects) route appinfo/routes.php:1166, plus geojson/wfs :1167-1168. src/views/object/MapView.vue is imported by nothing, so no map screen

Competitor cells rated `yes`, verbatim:

- objects-api: source read at 4.2.1, not driven: objects-api:src/objects/api/v2/views.py:506 POST /api/v2/objects/search with geometry.within (GeoJSON polygon) filters records with geometry__within (:512); tests src/objects/tests/v2/test_geo_search.py
- directus: source read at v12.4.1, not driven: directus:packages/types/src/filter.ts:75 _intersects_bbox and _intersects filters on geometry fields; the map layout filters by the visible area (app/src/layouts/map/index.ts)

## Why

Location is a first-class field for permits, objects in public space and assets, and three competitors show it on a map. OpenRegister serves the geometry over four endpoints, but the one map view in the tree is mounted by nothing, and a malformed geometry is stored without complaint, so the area search can silently miss it.

## What is built today

- Geometry stored in a JSON property, served by `ObjectsController` geoJson, wfs and geoSearch (`appinfo/routes.php` geo routes, `lib/Service/Geo/*`).
- Map points API `GET /api/integrations/maps/overviews/{register}/{schema}/points` (`MapsOverviewController`).
- `src/views/object/MapView.vue` and `src/services/geo/mapData.js` exist; nothing imports MapView.
- `lib/Service/Geo/GeoJsonGeometryValidator.php` exists with no caller in lib.

## What changes

1. A schema whose property declares a geometry format gets a map toggle on its records list; the map mounts `MapView` over the points endpoint.
2. On the map a user draws a polygon; the list narrows to the geo-search result for that area.
3. The save path calls `GeoJsonGeometryValidator` for every geometry property and refuses an invalid geometry with 400 naming the property.

## Out of scope

- Editing a geometry by drawing it (the value is still entered as GeoJSON).
- Base map choice per register.
