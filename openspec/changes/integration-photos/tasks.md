# Tasks: Integration — Photos

## Backend

- [ ] Migration: add `exif_metadata` JSON column to `openregister_file_links`
- [ ] `PhotoService` — filter to images, lazy EXIF extraction
- [ ] `PhotosController` — sub-resource endpoints (list, get with EXIF, link, unlink)
- [ ] `PhotosProvider` — id='photos', label='Photos', icon='Image', group='docs', requiredApp='photos', storage='link-table'
- [ ] Admin-setting: strip GPS from EXIF on link (default off)
- [ ] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnPhotosTab.vue` — thumbnail grid, lightbox with EXIF, upload-and-link, unlink
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnPhotosCard.vue`:
  - `user-dashboard`: recent photos across user's objects
  - `app-dashboard`: scoped
  - `detail-page`: horizontal photo strip (scrollable)
  - `single-entity`: thumbnail chip with filename
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/photos.js` — register with `referenceType: 'photos'`

## Quality

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: upload photo to object, verify grid thumbnail, lightbox, EXIF display
- [ ] GPS strip setting: toggle on, link a geotagged photo, verify GPS removed
- [ ] Hide test; reference-property test
