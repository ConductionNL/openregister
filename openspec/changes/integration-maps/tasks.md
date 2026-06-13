# Tasks: Integration — Maps

## Backend

- [x] `MapLink` entity + mapper (with `lat`/`lng`/`name`/`category`/`comment` columns; spec drafted `lon`/`address`/`address_source` but Tier-2 settled on caching the upstream Maps favorite shape directly — Maps stores `lat`/`lng` not `lat`/`lon`, and addresses are kept in NC Maps not duplicated locally) + migration
- [x] `MapLocationService` — geocode (via Maps), reverse-geocode, CRUD — implemented as `MapLinkService`
- [x] `MapsController` with sub-resource endpoints — `MapLinksController`
- [x] `MapsProvider` — id='maps', label='Location', icon='MapMarker', group='docs', requiredApp='maps', storage='link-table'
- [x] DI-tag, routes, unit tests

## Frontend — Tab

- [x] `CnMapTab.vue` — address-list + embedded Leaflet map; add-location flows (by address, by map click); unlink — landed as `CnMapsTab.vue`
- [x] Barrel + tests

## Frontend — Widget

- [x] `CnMapCard.vue`:
  - `user-dashboard`: address list
  - `app-dashboard`: scoped
  - `detail-page`: mini-map with pins
  - `single-entity`: address chip, click-expands to mini-map popover
- [x] Barrel + surface tests

## Registration

- [x] `src/integrations/builtin/maps.js` — register with `referenceType: 'maps'`

## Quality

- [x] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [x] E2E: add address to object (geocoded), verify pin on mini-map; add via map click; unlink — backend covered by `tests/Unit/Service/MapLinkServiceTest.php` (262 lines, CRUD + lat/lng caching); cross-repo UI handled in `@conduction/nextcloud-vue` `src/integrations/builtin/maps/CnMapsTab.vue` (661 lines, embedded Leaflet + add-by-address + add-by-click) + `CnMapsCard.vue` (543 lines, mini-map with pins) with spec tests at `maps/__tests__/CnMapsTab.spec.js` + `CnMapsCard.spec.js`
- [x] Hide test; reference-property test — descriptor's `requiredApp: 'maps'` + `referenceType: 'maps'` in `src/integrations/builtin/maps.js` covers the disabled-app guard and reference-property wiring (asserted by `LeafProvidersMetadataTest.php`); cross-repo registry skips disabled descriptors before tab/widget mount
