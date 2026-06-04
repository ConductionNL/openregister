# Tasks -- retrofit-2026-05-24-geo-metadata-kaart

Reverse-spec retrofit: pin down two helper methods inside the `geo-metadata-kaart` spatial-filter pipeline. Documentation-only; both methods already ship.

## Tasks

- [x] **task-1 — REQ-GEO-016: Geometry extraction from filter rows.** Annotated `OCA\OpenRegister\Service\Geo\GeoFilterApplier::extractGeometry()`. The helper returns the GeoJSON geometry at a hinted property key, or — when no hint is given — the first GeoJSON-shaped value found by iterating the row in insertion order. Non-geometry values at a hinted key return null without falling back to scan; a row with no geometry returns null. A value is "GeoJSON-shaped" when it is an array with `type` in `{Point, Polygon, MultiPolygon, LineString}`. Method is `public` and called from `GeoFilterApplier::rowMatchesAll()` for every filter on every row.
- [x] **task-2 — REQ-GEO-017: Polygon normalisation for spatial intersection.** Annotated `OCA\OpenRegister\Service\Geo\GeoSpatialEvaluator::extractPolygons()`. The helper normalises a GeoJSON geometry to a uniform `polygon[]` shape so `matchesIntersects()` and `pointInPolygonGeometry()` can iterate uniformly. `Polygon` → single-element list, `MultiPolygon` → coordinates as-is, anything else (including `Point`, `LineString`, missing `type`) → empty list, causing the calling test to return `false` without throwing. Method is `private` and called from three sites inside `GeoSpatialEvaluator`.

## Excluded from this retrofit

The reverse-spec batch input also listed four entries that are out of scope:

- `src/modals/object/MergeObject.vue#initializeMerge` — object-merge initialiser, unrelated to geo; belongs to a different capability spec.
- `src/modals/organisation/ManageOrganisationRoles.vue#initializeOrganisationItem` — organisation-role initialiser, unrelated to geo; belongs to the organisations capability.
- Two entries named `if` — scanner false-positives (PHP `if` blocks parsed as methods).

These should be re-routed to the correct capability clusters by a follow-up triage pass on the reverse-spec input.
