# Tasks: Integration — Photos

## Backend

- [~] Migration: add `exif_metadata` JSON column to `openregister_file_links` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `PhotoService` — filter to images, lazy EXIF extraction — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `PhotosController` — sub-resource endpoints (list, get with EXIF, link, unlink) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] `PhotosProvider` — id='photos', label='Photos', icon='Image', group='docs', requiredApp='photos', storage='link-table'
- [~] Admin-setting: strip GPS from EXIF on link (default off) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] DI-tag, routes, unit tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnPhotosTab.vue` — thumbnail grid, lightbox with EXIF, upload-and-link, unlink — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnPhotosCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: recent photos across user's objects
  - `app-dashboard`: scoped
  - `detail-page`: horizontal photo strip (scrollable)
  - `single-entity`: thumbnail chip with filename
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/photos.js` — register with `referenceType: 'photos'` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate; nl+en; strict; ESLint — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: upload photo to object, verify grid thumbnail, lightbox, EXIF display — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] GPS strip setting: toggle on, link a geotagged photo, verify GPS removed — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test; reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
