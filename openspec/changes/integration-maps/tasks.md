# Tasks: Integration — Maps

## Backend

- [x] `MapLink` entity + mapper (with `lat`/`lon`/`address`/`address_source` columns) + migration
- [x] `MapLocationService` — geocode (via Maps), reverse-geocode, CRUD
- [x] `MapsController` with sub-resource endpoints
- [x] `MapsProvider` — id='maps', label='Location', icon='MapMarker', group='docs', requiredApp='maps', storage='link-table'
- [x] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnMapTab.vue` — address-list + embedded Leaflet map; add-location flows (by address, by map click); unlink (nextcloud-vue repo — separate PR)
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnMapCard.vue`: (nextcloud-vue repo — separate PR)
  - `user-dashboard`: address list
  - `app-dashboard`: scoped
  - `detail-page`: mini-map with pins
  - `single-entity`: address chip, click-expands to mini-map popover
- [ ] Barrel + surface tests

## Registration

- [x] `src/integrations/builtin/maps.js` — register with `referenceType: 'maps'`

## Quality

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: add address to object (geocoded), verify pin on mini-map; add via map click; unlink
- [ ] Hide test; reference-property test
