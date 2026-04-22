# Design: Integration — Maps

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths..

## Approach

`MapLocationService` wraps Maps' geocoding API. Links cache `lat`/`lon` + human-readable address to avoid per-render API calls. `CnMapCard` uses Leaflet (Maps' own lib) for the mini-map.

## Architecture Decisions

### AD-1: Cache lat/lon in link table

**Decision**: Link table columns include `lat`, `lon`, `address`, `address_source` (address-geocoded / click-placed). Rendering never calls geocoding.

**Why**: Geocoding is latency-sensitive and rate-limited (Nominatim). Caching is O(1) safe.

**Trade-off**: Address changes in external data don't auto-refresh. Acceptable — explicit "refresh location" action available.

### AD-2: Single-entity widget = address chip, not map

**Decision**: `CnMapCard` at `surface='single-entity'` shows address as a chip with a map-pin icon; clicking expands to the mini-map.

**Why**: Inline maps in detail grids would be visually noisy and performance-heavy. Address is the text-equivalent answer.

## Files Affected

### Backend (new)
- `MapLocationService`, `MapsController`, `MapLink` entity + mapper + migration, `MapsProvider`, unit tests

### Backend (modified)
- `Application.php`, `routes.php`

### Frontend (new)
- `CnMapTab/*`, `CnMapCard/*`, `src/integrations/builtin/maps.js`, barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| Nominatim rate limits | Cache hits, throttled geocoding on the backend, clear error messages on rate-limit |
| Privacy — locations can be sensitive | Inherit object RBAC; Maps app permissions govern visibility transitively |
| Leaflet tile provider config differs per instance | Use Maps' own tile config (admin has already chosen) |
