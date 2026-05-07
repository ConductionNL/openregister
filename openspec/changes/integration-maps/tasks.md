# Tasks: Integration — Maps

## Backend

- [ ] `MapLink` entity + mapper (with `lat`/`lon`/`address`/`address_source` columns) + migration
- [ ] `MapLocationService` — geocode (via Maps), reverse-geocode, CRUD
- [ ] `MapsController` with sub-resource endpoints
- [ ] `MapsProvider` — id='maps', label='Location', icon='MapMarker', group='docs', requiredApp='maps', storage='link-table'
- [ ] DI-tag, routes, unit tests

## Frontend — Tab

- [ ] `CnMapTab.vue` — address-list + embedded Leaflet map; add-location flows (by address, by map click); unlink
- [ ] Barrel + tests

## Frontend — Widget

- [ ] `CnMapCard.vue`:
  - `user-dashboard`: address list
  - `app-dashboard`: scoped
  - `detail-page`: mini-map with pins
  - `single-entity`: address chip, click-expands to mini-map popover
- [ ] Barrel + surface tests

## Registration

- [ ] `src/integrations/builtin/maps.js` — register with `referenceType: 'maps'`

## Quality

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification

- [ ] E2E: add address to object (geocoded), verify pin on mini-map; add via map click; unlink
- [ ] Hide test; reference-property test
