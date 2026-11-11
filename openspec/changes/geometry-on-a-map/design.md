# Design: geometry-on-a-map

Read at openregister development `b876628280`.

## What exists

| Piece | Where |
|---|---|
| Map view (dead) | `src/views/object/MapView.vue` |
| Geo endpoints | `lib/Controller/ObjectsController.php` geoSearch, geoJson, wfs |
| Validator (no caller) | `lib/Service/Geo/GeoJsonGeometryValidator.php` |
| Save path | `lib/Service/Object/SaveObject.php` property validation |

## Approach

1. Wire the validator into property validation for properties with the geometry format, red test first with a real schema fragment.
2. Mount MapView as a presentation of the records list when the schema has a geometry property; the area filter posts to geo-search.

## Declarative or imperative

The geometry format on the property is the declaration; no new schema keyword.

## Tests

- PHPUnit: an invalid polygon is refused with 400 naming the property, a valid one saves (real validator, real schema fragment).
- vitest: the map toggle appears only for a schema with a geometry property; drawing an area calls geo-search.
