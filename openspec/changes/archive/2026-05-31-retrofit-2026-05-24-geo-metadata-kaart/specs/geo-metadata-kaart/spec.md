---
status: draft
---

# Geo Metadata en Kaart -- Retrofit 2026-05-24

## Purpose

Retrofit two helper methods inside the spatial-filter pipeline that already ship under REQ-GEO-004 (`GeoFilter` primitives + post-filter applier) but have observable contracts not separately specified. This delta adds REQ-GEO-016 (geometry extraction from filter rows) and REQ-GEO-017 (polygon normalisation in the spatial evaluator). No behaviour change; pure documentation.

## ADDED Requirements

### Requirement: REQ-GEO-016 -- Geometry extraction from filter rows

`GeoFilterApplier` MUST expose a geometry-extraction helper that locates the GeoJSON geometry inside a filter row. The helper MUST support two modes:

- **Property hint**: when the caller passes a non-null property name, the helper MUST return the geometry at that key in the row (coerced to a GeoJSON-shaped array) or null when the value at that key is not a valid GeoJSON geometry.
- **First-found fallback**: when the property name is null, the helper MUST iterate over the row's values in insertion order and return the first value that coerces to a valid GeoJSON geometry. When no value in the row is a valid geometry, the helper MUST return null.

A value is "GeoJSON-shaped" when it is an array containing a `type` field whose value is one of `Point`, `Polygon`, `MultiPolygon`, or `LineString`. The helper MUST NOT throw on malformed rows.

#### Scenario: Property hint targets a specific row key

- **GIVEN** a row `['locatie' => ['type' => 'Point', 'coordinates' => [5.12, 52.09]], 'name' => 'foo']`
- **WHEN** `extractGeometry(row, property: 'locatie')` is called
- **THEN** the helper MUST return the GeoJSON Point at `locatie`
- **AND** the `name` field MUST NOT be inspected

#### Scenario: Property hint points at a non-geometry value

- **GIVEN** a row `['locatie' => 'not-a-geometry', 'extra' => ['type' => 'Point', 'coordinates' => [5.0, 52.0]]]`
- **WHEN** `extractGeometry(row, property: 'locatie')` is called
- **THEN** the helper MUST return null
- **AND** the helper MUST NOT fall back to scanning other row values

#### Scenario: First-found fallback when no property is hinted

- **GIVEN** a row `['name' => 'foo', 'geo' => ['type' => 'Polygon', 'coordinates' => [[[0,0],[1,0],[1,1],[0,0]]]], 'extra' => ['type' => 'Point', 'coordinates' => [5, 52]]]`
- **WHEN** `extractGeometry(row, property: null)` is called
- **THEN** the helper MUST return the Polygon at `geo` (the first GeoJSON-shaped value in insertion order)
- **AND** the helper MUST NOT continue scanning past the first match

#### Scenario: Row contains no geometry

- **GIVEN** a row `['name' => 'foo', 'count' => 3]`
- **WHEN** `extractGeometry(row, property: null)` is called
- **THEN** the helper MUST return null

### Requirement: REQ-GEO-017 -- Polygon normalisation for spatial intersection

`GeoSpatialEvaluator` MUST normalise input geometries to a uniform polygon list before iterating in `matchesIntersects()` and `pointInPolygonGeometry()`. The normalisation rules MUST be:

- For a `Polygon` geometry, the helper MUST wrap the `coordinates` array as a single-element list (one polygon in the result).
- For a `MultiPolygon` geometry, the helper MUST return the `coordinates` array as-is (each polygon already a separate entry).
- For any other geometry type (including `Point`, `LineString`, missing `type`, or unsupported types), the helper MUST return an empty list. This MUST cause the calling intersection / containment test to return `false` without throwing.

The shape of each polygon entry in the result MUST follow the GeoJSON Polygon `coordinates` convention: `array<int, array<int, array<int, float>>>` — a list of rings, each ring a list of `[lon, lat]` vertices.

#### Scenario: Polygon input is wrapped as a single-element list

- **GIVEN** a geometry `['type' => 'Polygon', 'coordinates' => [[[0,0],[2,0],[2,2],[0,2],[0,0]]]]`
- **WHEN** the polygon normaliser is invoked
- **THEN** the result MUST be a list of length 1
- **AND** the single entry MUST equal the input `coordinates`

#### Scenario: MultiPolygon input is passed through

- **GIVEN** a geometry `['type' => 'MultiPolygon', 'coordinates' => [[[[0,0],[1,0],[1,1],[0,0]]], [[[5,5],[6,5],[6,6],[5,5]]]]]`
- **WHEN** the polygon normaliser is invoked
- **THEN** the result MUST be a list of length 2
- **AND** each entry MUST be a polygon `coordinates` array drawn from the input

#### Scenario: Unsupported geometry types yield an empty list

- **GIVEN** a geometry `['type' => 'Point', 'coordinates' => [5, 52]]`
- **WHEN** the polygon normaliser is invoked
- **THEN** the result MUST be an empty list
- **AND** the calling intersection test MUST therefore return `false`

#### Scenario: Geometry without a type field yields an empty list

- **GIVEN** a geometry `['coordinates' => [[[0,0],[1,0],[1,1],[0,0]]]]` (no `type`)
- **WHEN** the polygon normaliser is invoked
- **THEN** the result MUST be an empty list
