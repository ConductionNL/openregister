# Tasks: geometry-on-a-map

## Implementation tasks

### Task 1: Validate geometry on save
- **spec_ref**: `openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save`
- **files**: `lib/Service/ObjectService.php` (the write-path guard `enforceDeclaredShapes`, which runs on create and update whether or not hard validation is on; the design named SaveObject, the guard is where the declared bounds are already enforced), `lib/Service/Geo/GeoJsonGeometryValidator.php`
- **acceptance_criteria**:
  - invalid geometry answers 400 naming the property
  - valid geometry saves
- [x] Implement
- [x] Test (red first): `tests/Unit/Service/Geo/GeometryValidatedOnSaveTest.php`, 3 of 5 red on development

### Task 2: Map presentation and area search on the records list
- **spec_ref**: `openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-002-records-with-a-geometry-can-be-seen-and-searched-on-a-map`
- **files**: `src/views/object/MapView.vue`, `src/views/search/SearchIndex.vue`, `src/services/geo/mapData.js`, `src/store/modules/object.js`, `package.json`
- **acceptance_criteria**:
  - map toggle for geometry schemas only
  - drawn area narrows the list through geo-search
- [x] 2.1 Add `leaflet` 1.9 and `leaflet-draw` to `package.json` (versions checked with `npm view`) and import them in `src/views/object/MapView.vue` instead of relying on a global `L`. Render `buildMarkers()` and `markerBounds()` from `src/services/geo/mapData.js` on the base layer from `defaultBaseLayer()`.
  - done: `leaflet` 1.9.4 and `leaflet-draw` 1.0.4 in `package.json`, loaded on demand by `src/services/geo/leaflet.js` (puts Leaflet on `window.L` for the draw plugin). `MapView.vue` and `mapData.js` were removed as unused by 6fe5ff563d and are restored from before it; MapView now draws `buildMarkers()` as circle markers on the `defaultBaseLayer()` tiles (`tileUrl()` turns a PDOK WMTS layer into its EPSG:3857 tile template). `Application::relaxCspForMapTiles()` allows the PDOK and OpenStreetMap tile hosts as image sources.
- [x] 2.2 In `src/views/search/SearchIndex.vue`, add `map` beside `table`, `kanban` and `calendar` in the presentation switch (`presentationType`). Offer the toggle only when the selected schema has a property whose `format` or `type` is a `geo:*` type, the same test as `GeoJsonGeometryValidator::declaredGeoType()`; port that test to a `isGeoProperty()` helper in `mapData.js`.
  - done: `isGeoProperty()` and `geoPropertyOf()` in `mapData.js`. The presentation comes from the saved view, so the map is a toggle beside it: SearchIndex offers "Show on map" in the list header only when `geoPropertyOf(schema)` finds a property, `presentationType` is `map` while it is open, and "Table" goes back.
- [x] 2.3 A draw control in `MapView.vue` emits the drawn polygon as GeoJSON. `SearchIndex.vue` stores it in the object store's search parameters and sends it to `POST /api/objects/{register}/{schema}/geo-search` (`objects#geoSearch`) as `geometry.within`, together with the active filters, so the list and the map show only the records inside. Clearing the drawing restores the unfiltered list.
  - done: MapView's draw control (polygon, rectangle) emits `area` / `areaCleared`; SearchIndex posts it with the list's active search parameters through `src/services/geo/geoSearch.js` as `geometry.within` (plus the geometry `property`) and shows the records inside; clearing the drawing or closing the map restores the list. The area is kept in SearchIndex, not in the object store's `searchParams`: the shared search plugin forwards every key there to the GET listing as a field filter.
- [x] 2.4 Red first: extend `src/services/geo/mapData.spec.js` for `isGeoProperty()`, and add `src/views/search/SearchIndex.spec.js` cases: the map toggle is absent for a schema without a geometry property, and a drawn polygon produces a geo-search call with `geometry.within`.
  - done: `src/services/geo/mapData.spec.js` (isGeoProperty, geoPropertyOf, tileUrl, drawnArea), `src/services/geo/geoSearch.spec.js`, and six cases in `src/views/search/SearchIndex.spec.js` (no toggle without a geometry property, map only when opened, a drawn polygon sends geo-search with `geometry.within`, a failed search, clearing).
- [ ] 2.5 `tests/e2e/ci/records-map.spec.ts`: seed records in two neighbourhoods, open the map on `/tables`, draw a polygon around one and read that the list holds only its records.

### Task 3: Offer the geometry types in the property editor
- **spec_ref**: `openspec/changes/geometry-on-a-map/specs/geo-metadata-kaart/spec.md#requirement-req-geomap-001-a-geometry-is-validated-on-save`
- **files**: `src/modals/schema/schemaPropertyForm.js`, `src/modals/schema/EditSchemaProperty.vue`, `src/services/propertyEditor.js`
- **acceptance_criteria**:
  - a functional administrator can declare a point, line, polygon, multipolygon or any geometry without editing JSON
  - the record form edits a geometry value
- [ ] 3.1 Add `geo:point`, `geo:linestring`, `geo:polygon`, `geo:multipolygon` and `geo:geometry` to `formatSelect()` in `src/modals/schema/schemaPropertyForm.js` for type `object`, with translated labels.
  - Done except the translated labels: `formatSelect('object')` offers the five formats (`GEO_FORMATS`) and the property modal enables the format select for objects. The select shows every format by its id, string formats included, so labels for all of them belong in one l10n pass across the locales.
- [x] 3.2 In `src/services/propertyEditor.js` `editorFor()`, route a `geo:*` property to a small map picker (`src/components/geo/GeometryEditor.vue`, Leaflet with the draw control from task 2.1) that writes GeoJSON, with a JSON text fallback.
  - done: `editorFor()` returns `geometry` for a `geo:*` property; ViewObject renders `src/components/geo/GeometryEditor.vue`: a Leaflet map with the draw control limited to the shapes the format allows (`src/services/geo/geometryValue.js`, a polygon drawn for a multipolygon is wrapped) and the JSON text as the fallback.
- [x] 3.3 Red first: `src/services/propertyEditor.spec.js` returns the geometry editor for each `geo:*` format; a new `src/modals/schema/schemaPropertyForm.spec.js` lists the five formats.
  - `schemaPropertyForm.spec.js` (existing file) lists the five formats and `EditSchemaProperty.logic.spec.js` pins the select per type, red before the change. `propertyEditor.spec.js` now returns the geometry editor for each `geo:*` format (red before 3.2), and `src/services/geo/geometryValue.spec.js` covers the editor's shapes and JSON text.

## Verification

- Each task has a PHPUnit or jest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `stylelint`, `test:l10n`, `test:l10n:parity`, `check:schema-l10n`, `check:specs` and the hydra gates (hydra-gates@main) once before push.
