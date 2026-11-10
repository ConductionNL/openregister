# Integration: Maps (Location)

## Problem

Location is implicit in many OR objects (zaken with an address, permits tied to a parcel, facilities at a geolocation) but has no first-class map view. NC Maps provides the infrastructure.

## Context

- **Backend:** greenfield — wrap NC Maps API + geocoding
- **Required NC app:** `maps`
- **Storage:** `link-table` augmented with `lat`/`lon` cached columns for map rendering without per-load API calls
- **Depends on:** `pluggable-integration-registry`
- **Strong fit:** Procest (zaken), ZaakAfhandelApp (zaken afspraken/inspecties), any municipal case system

## Proposed Solution

`MapLocationService` + `MapsController` + `MapsProvider` + `CnMapTab` + `CnMapCard`. Tab shows linked points on an embedded map with address list. Detail-page widget renders inline mini-map. Geocoding via Maps' own backend (which uses Nominatim or configured provider).

## Scope

**In scope:** Backend with geocoding + reverse-geocoding, link table with cached lat/lon, provider, tab, widget, registration, tests, nl+en.

**Out of scope:** Routing (Maps owns); geofencing; full GIS features; offline tile caching.

## Acceptance criteria

- [ ] Maps tab appears when Maps installed + schema has `maps` in linkedTypes
- [ ] User can add location by address (geocoded) or by clicking on map
- [ ] Inline mini-map on detail-page shows all locations pinned
- [ ] Widget renders on all 4 surfaces
- [ ] Reference-property `referenceType: 'maps'` renders a location chip with address
- [ ] Parity gate passes; nl+en done
