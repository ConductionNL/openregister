# Tasks: Geo Metadata en Kaart

> **Status (2026-05-02): scope-tightened to GeoJSON storage + spatial-query API on the OpenRegister side.** UI / map-rendering / base-registration overlays / map drawing / NL Design System map styling are handed off to consuming apps:
> - mydash: new change `map-support` proposed at https://github.com/ConductionNL/mydash/blob/main/openspec/changes/map-support/proposal.md
> - tilburg-woo-ui: tracked at https://github.com/ConductionNL/tilburg-woo-ui/issues/436

## In-OR-scope (storage + API)

- [x] **REQ-GEO-001 — Schema properties MUST support geospatial data types.** `geo` added to the `validTypes` whitelist on `PropertyValidatorHandler`. Schema authors can declare `{"type": "geo"}` on any property; the validator accepts it as a first-class JSON-Schema type alongside `string`, `number`, `array`, etc.
- [x] **REQ-GEO-002 — GeoJSON storage in MagicMapper.** Storage works through the existing JSON-column path on magic tables — `geo`-typed properties serialise as GeoJSON `Geometry` or `FeatureCollection` documents and round-trip through the standard save/read cycle without a new column. Indexing for at-scale spatial queries is tracked in `geo-spatial-queries` (a focused follow-up gated on a real customer with >50k geo rows).
- [x] **REQ-GEO-004 — Spatial queries in the API.** Bounding-box and within-polygon filters are exposed via the existing `?_filter` mechanism on the listing API; clients pass GeoJSON as filter values and the standard listing pipeline applies them. Server-side spatial-index optimisation (PostGIS `GIST`, Solr `LatLonPointSpatialField`, ES `geo_shape`) is tracked under `geo-spatial-queries`.
- [x] **REQ-GEO-011 — Geo-filtering in search and facets.** Same surface as REQ-GEO-004 — geo-filter parameters route through the listing/facet pipeline. Faceted geo-aggregations (e.g. heatmap buckets) are scoped under `geo-spatial-queries`.
- [x] **REQ-GEO-015 — Coordinate transformation and Dutch grid support.** OpenRegister stores GeoJSON in WGS84 (the GeoJSON spec's mandatory CRS). Transformation to/from RD (EPSG:28992) is a UI-side concern handled by the consuming apps via `proj4` (per the mydash `map-support` proposal). Documented as the canonical contract: storage = WGS84 GeoJSON, presentation-time transformation = consumer responsibility.

## Out of OR-scope (handed off to consuming apps)

- [x] **REQ-GEO-003 — Map visualization component with PDOK tile layers.** Moved to mydash `map-support` + tilburg-woo-ui#436. Each consuming app picks its own map library (Leaflet/MapLibre/OpenLayers) and tile layer.
- [x] **REQ-GEO-005 — Geocoding via PDOK Locatieserver.** Moved to consuming apps. PDOK Locatieserver is a UI-input affordance ("type a street name → get coords") that doesn't belong in OR's storage/API layer.
- [x] **REQ-GEO-006 — BAG and BGT base-registration integration.** Moved to consuming apps. BAG/BGT overlays are tile-layer choices in the map widget, not OR data.
- [x] **REQ-GEO-007 — Multi-layer map views with layer control.** Pure UI feature. Moved to mydash `map-support`.
- [x] **REQ-GEO-008 — WFS and GeoJSON export.** GeoJSON export comes free with the OR listing API (`?_format=json` already returns GeoJSON for `geo`-typed properties). WFS specifically is a niche export format; deferred to a follow-up `wfs-export` change if a real customer requires it.
- [x] **REQ-GEO-009 — INSPIRE metadata compliance.** Publication-side concern (which datasets are catalogued), not storage-side. INSPIRE metadata records are themselves register objects in the publication app's register; OR doesn't impose a separate compliance pass.
- [x] **REQ-GEO-010 — Geo-fencing with event triggers.** Implementable on top of OR's existing event pipeline + `CustomScopeEvaluatingEvent` once a real consumer needs it. Not a storage/API concern; deferred to a focused `geo-fencing-events` change when triggered by demand.
- [x] **REQ-GEO-012 — Solr and Elasticsearch spatial query support.** Folded into the broader `aggregations-backend-native` change (which adds Solr + ES backends). Geo-specific Solr / ES query construction will live there.
- [x] **REQ-GEO-013 — Map drawing and geometry editing.** UI-side concern. Moved to a follow-up mydash `map-drawing-tools` change.
- [x] **REQ-GEO-014 — NL Design System map styling.** UI-side concern. Each consuming app applies NLDS tokens to its map widget per its own design pass.

## Hand-off summary

| Concern | Owner |
|---|---|
| Storage + API (GeoJSON, WGS84, listing filters) | OpenRegister (this change, in-OR-scope items above) |
| Map widget rendering, base layers, drawing tools, layer control, NLDS styling | mydash `map-support` |
| Tilburg WOO map integration | tilburg-woo-ui#436 |
| Spatial-index optimisation (PostGIS GIST, Solr/ES spatial fields) | future `geo-spatial-queries` change (gated on >50k row customer) |
| Geo-fencing event triggers | future `geo-fencing-events` change (demand-gated) |
| WFS export | future `wfs-export` change (demand-gated) |
| Solr / ES spatial query construction | folded into `aggregations-backend-native` |
