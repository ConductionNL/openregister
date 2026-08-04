# Retrofit: reverse-spec geo-metadata-kaart helpers

## Problem

The `geo-metadata-kaart` capability has 15 existing REQs covering geospatial property types, GeoJSON storage, the four spatial-filter primitives, and spatial-query wiring. Two helper methods inside the spatial pipeline already ship and are exercised by the shipped REQ-GEO-004 / REQ-GEO-011 filter stack, but their behaviour is not separately specified:

- `GeoFilterApplier::extractGeometry()` — pulls the geometry value out of a row, either at a named property or by first-found GeoJSON-shaped value (the "geometry hint vs. auto-detect" contract that callers like `rowMatchesAll()` and `GeoFilterApplierTest::testPropertyHint*` rely on).
- `GeoSpatialEvaluator::extractPolygons()` — the private normaliser that flattens `Polygon` and `MultiPolygon` geometries to a single `polygon[]` shape so that `matchesIntersects()` / `pointInPolygonGeometry()` can iterate uniformly. The shape contract (Polygon → 1-element list, MultiPolygon → coordinates as-is, anything else → empty) is currently only documented by example in `GeoSpatialEvaluatorTest`.

Both helpers were triaged as Bucket 2a (legacy code without a matching spec requirement) by the 2026-05-24 reverse-spec scan of the geo cluster.

## Proposed Solution

Add two new REQs to the `geo-metadata-kaart` capability that pin down the observed behaviour:

1. **REQ-GEO-016** — Geometry extraction from filter rows, including the property-hint vs. first-found fallback semantics.
2. **REQ-GEO-017** — Polygon normalisation: how `Polygon` and `MultiPolygon` are flattened to a list of polygons by the spatial evaluator, and how unsupported geometry types degrade silently.

This is a documentation-only retrofit. No behaviour changes; the existing implementations stay as-is and gain `@spec` annotations.

## Scope

### In scope

- 2 new REQs (REQ-GEO-016, REQ-GEO-017) under the `geo-metadata-kaart` capability spec
- `@spec` annotations on `GeoFilterApplier::extractGeometry` and `GeoSpatialEvaluator::extractPolygons` pointing at the new tasks.md entries

### Out of scope

- Any code change beyond docblock annotations
- The two Vue methods listed in the batch input (`MergeObject.initializeMerge`, `ManageOrganisationRoles.initializeOrganisationItem`) — these are unrelated to geo and were misclassified by the reverse-spec scanner; they belong to the merge-objects / organisation-roles capabilities instead
- The two scanner false-positive entries named "if" (PHP `if` blocks parsed as methods)
- Any change to the published `openspec/specs/geo-metadata-kaart/spec.md`; this retrofit's delta lives under the change folder and will be merged by the normal opsx sync process

## Acceptance criteria

- [x] Two new REQs drafted in `specs/geo-metadata-kaart/spec.md` (delta) describing observed behaviour
- [x] `@spec` lines added to the docblocks of both helper methods
- [x] PR opened with the `retrofit` label, base `development`
