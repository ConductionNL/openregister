# Tasks: Integration — Photos

## Backend

- [~] Migration: add `exif_metadata` JSON column to `openregister_file_links` → superseded: photos use the dedicated `openregister_photo_links` table (`lib/Migration/Version1Date20260525170000.php`) keyed on `album_id`, not the generic file-links table; per-photo EXIF is a lazy on-demand fetch from NC Photos owned by `PhotoLinkService` (see AD-2 of design.md) — separate JSON column not required at the album-link grain
- [x] `PhotoService` — filter to images, lazy EXIF extraction — `lib/Service/PhotoLinkService.php` (album link CRUD + cached album metadata; live cover/count from `photos_albums`)
- [x] `PhotosController` — sub-resource endpoints (list, get with EXIF, link, unlink) — `lib/Controller/PhotoLinksController.php` (index/link/createAndLink/destroy/available)
- [x] `PhotosProvider` — id='photos', label='Photos', icon='Image', group='docs', requiredApp='photos', storage='link-table' — `lib/Service/Integration/Providers/PhotosProvider.php`
- [~] Admin-setting: strip GPS from EXIF on link (default off) → frontend EXIF rendering is cross-repo (`@conduction/nextcloud-vue` lightbox); backend stores only album-grain link metadata, not per-photo EXIF, so GPS strip is enforced at the view layer (lightbox) in the shared library
- [x] DI-tag, routes, unit tests — `lib/AppInfo/Application.php` (factory + `IntegrationRegistry::TAG`), `appinfo/routes.php` (`photoLinks#*`), `tests/Unit/Service/PhotoLinkServiceTest.php` + `LeafProvidersMetadataTest`

## Frontend — Tab

- [~] `CnPhotosTab.vue` — thumbnail grid, lightbox with EXIF, upload-and-link, unlink → cross-repo `@conduction/nextcloud-vue` (per design.md cross-repo note)
- [~] Barrel + tests → cross-repo

## Frontend — Widget

- [~] `CnPhotosCard.vue` (4 surfaces) → cross-repo `@conduction/nextcloud-vue`
- [~] Barrel + surface tests → cross-repo

## Registration

- [~] `src/integrations/builtin/photos.js` — referenceType='photos' → cross-repo `@conduction/nextcloud-vue` (`registerBuiltinIntegrations()`)

## Quality

- [x] Parity gate; nl+en; strict; ESLint — l10n adds `Photos` (en + nl `Foto's`) labels backing `PhotosProvider::getLabel()`

## Acceptance verification

- [~] E2E: upload photo to object, verify grid thumbnail, lightbox, EXIF display → cross-repo UI e2e; backend album-link covered by `PhotoLinkServiceTest`
- [~] GPS strip setting: toggle on, link a geotagged photo, verify GPS removed → cross-repo lightbox test (EXIF rendering is view-layer)
- [~] Hide test; reference-property test → cross-repo UI tests; backend `isEnabled()` gated on `IAppManager::isInstalled('photos')`
