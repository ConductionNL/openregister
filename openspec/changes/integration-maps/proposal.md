# Integration: Maps (Location)

## Problem

Location is implicit in many OR objects (zaken with an address, permits tied to a parcel, facilities at a geolocation) but has no first-class map view. NC Maps provides the infrastructure. Today this leaf is **stub** per the 2026-05-24 registry audit — `MapsProvider.php` is a 137-line copy-paste of the MarkerLookupTrait template with no `OCA\Maps\…` imports and `getLinkedItems()` returns `[]`. This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like Procest and Zaakafhandelapp have no working integration path and reinvent address-on-case linkage locally.

## Context

- **Audit bucket**: stub (2026-05-24)
- **Current backend**: 137-line MarkerLookupTrait template, no `OCA\Maps\…` imports; `getLinkedItems()` returns `[]`
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget; backend returns `[]` so the tab is empty
- **Target NC class(es)**: `OCA\Maps\Service\FavoritesService`
- **Storage strategy**: `link-table` augmented with `lat`/`lon` cached columns for map rendering without per-load API calls
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)
- **Strong fit:** Procest (zaken), ZaakAfhandelApp (zaken afspraken/inspecties), any municipal case system

## Proposed Solution

`MapLocationService` + `MapsController` + `MapsProvider` + `CnMapTab` + `CnMapCard`. Tab shows linked points on an embedded map with address list. Detail-page widget renders inline mini-map. Geocoding via Maps' own backend (which uses Nominatim or configured provider). Provider imports `OCA\Maps\Service\FavoritesService` for the linked-favorite query and falls back to `IntegrationHealth::missingApp('maps')` when NC Maps is not installed.

## Scope

**In scope:** Backend with geocoding + reverse-geocoding, link table with cached lat/lon, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Routing (Maps owns); geofencing; full GIS features; offline tile caching.

## Acceptance criteria

- [ ] Maps tab appears when Maps installed + schema has `maps` in linkedTypes
- [ ] User can add location by address (geocoded) or by clicking on map
- [ ] Inline mini-map on detail-page shows all locations pinned
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'maps'` renders a location chip with address
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `OCA\Maps\Service\FavoritesService` import for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('maps')` when NC Maps absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` keeps generic `leaf()` shell with notes — bespoke Tab + Widget components are OUT of this change's scope; file follow-up if needed
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
