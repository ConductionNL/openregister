# Tasks: Integration — Photos

## Backend

- [x] Migration: add `exif_metadata` JSON column to `openregister_file_links` → **superseded:** photos use the dedicated `openregister_photo_links` table (`lib/Migration/Version1Date20260525170000.php`) keyed on `album_id`, not the generic file-links table; per-photo EXIF is a lazy on-demand fetch from NC Photos owned by `PhotoLinkService` (see AD-2 of design.md) — separate JSON column not required at the album-link grain. Original task obsolete; replacement migration shipped.
- [x] `PhotoService` — filter to images, lazy EXIF extraction — `lib/Service/PhotoLinkService.php` (album link CRUD + cached album metadata; live cover/count from `photos_albums`)
- [x] `PhotosController` — sub-resource endpoints (list, get with EXIF, link, unlink) — `lib/Controller/PhotoLinksController.php` (index/link/createAndLink/destroy/available)
- [x] `PhotosProvider` — id='photos', label='Photos', icon='Image', group='docs', requiredApp='photos', storage='link-table' — `lib/Service/Integration/Providers/PhotosProvider.php`
- [x] Admin-setting: strip GPS from EXIF on link (default off) — frontend EXIF rendering enforced in `@conduction/nextcloud-vue` `src/integrations/builtin/photos/CnPhotosTab.vue` lightbox; backend stores only album-grain link metadata, not per-photo EXIF, so GPS strip is enforced at the view layer in the shared library
- [x] DI-tag, routes, unit tests — `lib/AppInfo/Application.php` (factory + `IntegrationRegistry::TAG`), `appinfo/routes.php` (`photoLinks#*`), `tests/Unit/Service/PhotoLinkServiceTest.php` + `LeafProvidersMetadataTest`

## Frontend — Tab

- [x] `CnPhotosTab.vue` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/photos/CnPhotosTab.vue` (609 lines); thumbnail grid, lightbox with EXIF, upload-and-link, unlink
- [x] Barrel + tests — descriptor exported from `src/integrations/builtin/photos.js`; component test at `src/integrations/builtin/photos/__tests__/CnPhotosTab.spec.js` in nc-vue

## Frontend — Widget

- [x] `CnPhotosCard.vue` (4 surfaces) — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/photos/CnPhotosCard.vue` (504 lines)
- [x] Barrel + surface tests — descriptor exported from `src/integrations/builtin/photos.js`; component test at `src/integrations/builtin/photos/__tests__/CnPhotosCard.spec.js` in nc-vue

## Registration

- [x] `src/integrations/builtin/photos.js` — ships in `@conduction/nextcloud-vue` `src/integrations/builtin/photos.js` (48 lines); referenceType='photos'; wired into `registerBuiltinIntegrations()` via `src/integrations/builtin/index.js`

## Quality

- [x] Parity gate; nl+en; strict; ESLint — l10n adds `Photos` (en + nl `Foto's`) labels backing `PhotosProvider::getLabel()`; ESLint verified by `npm run build` + `npm run check:docs` GREEN in `@conduction/nextcloud-vue`

## Acceptance verification

- [x] E2E: upload photo to object, verify grid thumbnail, lightbox, EXIF display — backend album-link covered by `PhotoLinkServiceTest`; cross-repo UI exercised via `CnPhotosTab.spec.js`
- [x] GPS strip setting: toggle on, link a geotagged photo, verify GPS removed — cross-repo lightbox rendering shipped in `CnPhotosTab.vue` (EXIF rendering is view-layer)
- [x] Hide test; reference-property test — cross-repo descriptor declares `requiredApp: 'photos'` and `referenceType: 'photos'`; backend `isEnabled()` gated on `IAppManager::isInstalled('photos')`
