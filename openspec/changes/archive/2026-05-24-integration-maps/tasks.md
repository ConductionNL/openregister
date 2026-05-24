# Tasks: Integration — Maps

> **Scope note (2026-05-24)**: this iteration delivers the
> `MapsProvider` upgrade only — replaces the MarkerLookupTrait
> copy-paste with a real `OCA\Maps\Service\FavoritesService`-aware
> implementation that tags favorites via the upstream `category` field
> and surfaces them through the registry leaf-row contract. The
> wider design (MapLink entity + migration, MapLocationService with
> geocoding, MapsController sub-resource endpoints, CnMapTab and
> CnMapCard surfaces, reference-property auto-rendering) remains
> open and tracked by the deferred-tasks block below.

## Backend (this iteration)

- [x] `MapsProvider` — id='maps', label='Location', icon='MapMarker', group='docs', requiredApp='maps', storage='link-table'
- [x] Real impl using `OCA\Maps\Service\FavoritesService` (class-exists gate; tags via Maps' native `category` field; cross-user `category = "or:{uuid}"` query)
- [x] `health()` returns `unavailable` when Maps app missing; defensive class-exists check inside `list()` for half-installed cases
- [x] PHPUnit unit tests — happy, absent-app, empty, DB failure, NotImplementedException defaults (12 tests / 36 assertions)
- [x] SPDX-License-Identifier + SPDX-FileCopyrightText in main docblock; class-level `@spec` tag back to this file
- [x] DI registration preserved (constructor stays `(IDBConnection, IAppManager, IL10N)` so the bulk leaf loop in `Application.php` keeps working)

## Backend (deferred — follow-up tasks)

- [ ] `MapLink` entity + mapper (with `lat`/`lon`/`address`/`address_source` columns) + migration
- [ ] `MapLocationService` — geocode (via Maps), reverse-geocode, CRUD
- [ ] `MapsController` with sub-resource endpoints
- [ ] DI-tag, routes, controller unit tests

## Frontend — Tab (deferred, lands in `@conduction/nextcloud-vue`)

- [ ] `CnMapTab.vue` — address-list + embedded Leaflet map; add-location flows (by address, by map click); unlink
- [ ] Barrel + tests

## Frontend — Widget (deferred, lands in `@conduction/nextcloud-vue`)

- [ ] `CnMapCard.vue`:
  - `user-dashboard`: address list
  - `app-dashboard`: scoped
  - `detail-page`: mini-map with pins
  - `single-entity`: address chip, click-expands to mini-map popover
- [ ] Barrel + surface tests

## Registration (deferred)

- [ ] `src/integrations/builtin/maps.js` — register with `referenceType: 'maps'`

## Quality (deferred — covers the frontend bits)

- [ ] Parity gate; nl+en; strict; ESLint

## Acceptance verification (deferred)

- [ ] E2E: add address to object (geocoded), verify pin on mini-map; add via map click; unlink
- [ ] Hide test; reference-property test
