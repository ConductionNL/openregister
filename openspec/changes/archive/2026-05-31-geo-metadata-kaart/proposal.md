# Geo Metadata en Kaart

## Why

Dutch government registers are inherently geospatial — addresses, parcels, plan boundaries, monuments, traffic decisions, environmental permits — and tender requirements consistently demand BAG/BGT/BRT integration, RD New (EPSG:28992) coordinate handling, and PDOK-based map visualisation. OpenRegister currently treats geometry fields as opaque JSON: no validation, no spatial query, no map view, no INSPIRE compliance. This blocks ecosystem apps (opencatalogi catalogue maps, zaakafhandelapp location-based zaken, docudesk geo-tagged documents) and disqualifies Conduction from a large slice of municipal/provincial tenders. This change makes geospatial a first-class capability across schema definition, storage, query, and UI.

## What Changes

- Schema property types extended to support GeoJSON geometry (Point, Polygon, MultiPolygon, LineString) with CRS metadata.
- MagicMapper indexes geometry columns and accepts spatial query parameters (bbox, within, intersects, distance).
- Map visualization Vue component with PDOK (BRT, Luchtfoto) tile layers, marker clustering, and layer control.
- Geocoding via PDOK Locatieserver and reverse geocoding helpers exposed through the API.
- BAG and BGT base-registration lookup integration; resolved IDs stored alongside free-form addresses.
- WFS and GeoJSON export endpoints for downstream GIS tooling.
- INSPIRE metadata fields on schemas for compliant European geo-data sharing.
- Coordinate transformation between WGS84/EPSG:4326 and RD New/EPSG:28992 (proj4js).
- Geo-fencing event triggers (object moved into/out of polygon → workflow events).
- Geo-filtering integrated into search/facet API and Solr/Elasticsearch spatial backends.
- Map drawing/editing UI for in-place geometry capture.
- NL Design System map styling tokens (no hardcoded colours).

## Problem
Enable OpenRegister to store, validate, query, and visualize geospatial data attached to register objects. Objects MUST support GeoJSON geometry types (Point, Polygon, MultiPolygon, LineString), coordinate reference system negotiation (WGS84/EPSG:4326 and RD New/EPSG:28992), and references to Dutch base registrations (BAG, BGT, BRT).

## Proposed Solution
Enable OpenRegister to store, validate, query, and visualize geospatial data attached to register objects. Objects MUST support GeoJSON geometry types (Point, Polygon, MultiPolygon, LineString), coordinate reference system negotiation (WGS84/EPSG:4326 and RD New/EPSG:28992), and references to Dutch base registrations (BAG, BGT, BRT). A map visualization component MUST render object locations on interactive maps using PDOK tile services, support marker clustering for large datasets, and enable sp
