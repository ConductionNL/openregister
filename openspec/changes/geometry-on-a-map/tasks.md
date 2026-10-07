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
- **files**: `src/views/object/MapView.vue`, `src/views/search/SearchIndex.vue`
- **acceptance_criteria**:
  - map toggle for geometry schemas only
  - drawn area narrows the list through geo-search
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a PHPUnit or jest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `stylelint`, `test:l10n`, `test:l10n:parity`, `check:schema-l10n`, `check:specs` and the hydra gates (hydra-gates@main) once before push.
