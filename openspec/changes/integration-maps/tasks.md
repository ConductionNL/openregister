# Tasks: Integration — Maps

## Backend

- [~] `MapLink` entity + mapper (with `lat`/`lon`/`address`/`address_source` columns) + migration — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `MapLocationService` — geocode (via Maps), reverse-geocode, CRUD — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] `MapsController` with sub-resource endpoints — deferred to downstream cycle / fleet-wide adoption (handoff)
- [x] `MapsProvider` — id='maps', label='Location', icon='MapMarker', group='docs', requiredApp='maps', storage='link-table'
- [~] DI-tag, routes, unit tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] `CnMapTab.vue` — address-list + embedded Leaflet map; add-location flows (by address, by map click); unlink — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Barrel + tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] `CnMapCard.vue`: — deferred to downstream cycle / fleet-wide adoption (handoff)
  - `user-dashboard`: address list
  - `app-dashboard`: scoped
  - `detail-page`: mini-map with pins
  - `single-entity`: address chip, click-expands to mini-map popover
- [~] Barrel + surface tests — deferred to downstream cycle / fleet-wide adoption (handoff)

## Registration

- [~] `src/integrations/builtin/maps.js` — register with `referenceType: 'maps'` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate; nl+en; strict; ESLint — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] E2E: add address to object (geocoded), verify pin on mini-map; add via map click; unlink — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test; reference-property test — deferred to downstream cycle / fleet-wide adoption (handoff)
