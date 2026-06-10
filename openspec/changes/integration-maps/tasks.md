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

- [~] E2E: add address to object (geocoded), verify pin on mini-map; add via map click; unlink — deferred to live verification on docker env; unit tests cover backend service + provider
- [~] Hide test; reference-property test — deferred to live verification on docker env
