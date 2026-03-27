# Geo Metadata & Map Visualization

## Overview

OpenRegister will support storing, validating, querying, and visualizing geospatial data via GeoJSON integration, PDOK (Publieke Dienstverlening Op de Kaart) address lookup, and map visualization in the admin interface. This enables location-aware registers for permits, environmental data, social services, and other spatial datasets common in Dutch government use cases.

**Status**: Planned (spec defined, implementation pending)

## Planned Capabilities

### GeoJSON Property Type

A new JSON Schema property type `format: geojson` will be added to support geospatial data:

```json
{
  "properties": {
    "locatie": {
      "type": "object",
      "format": "geojson",
      "title": "Locatie",
      "description": "Geografische locatie van de melding"
    }
  }
}
```

Supported GeoJSON geometry types: `Point`, `LineString`, `Polygon`, `MultiPoint`, `MultiLineString`, `MultiPolygon`, `GeometryCollection`.

### Coordinate Reference Systems

| System | Description | Use case |
|--------|-------------|---------|
| WGS84 (EPSG:4326) | GPS coordinates (lat/lon) | Default; international |
| RD New (EPSG:28992) | Dutch National Grid | Dutch government data (BAG, BGT, BRO) |

Conversion between WGS84 and RD is performed server-side via `proj4php`.

### PDOK Integration

When a schema property is configured with `format: geojson, pdokLookup: true`, the UI provides:

- Address autocomplete via the PDOK Locatieserver API
- Point placement on a map (Leaflet)
- Reverse geocoding (click on map → fill address fields)

PDOK data sources supported:

| Source | Description |
|--------|-------------|
| BAG (Basisregistratie Adressen en Gebouwen) | Addresses and buildings |
| BGT (Basisregistratie Grootschalige Topografie) | Large-scale topography |
| BRO (Basisregistratie Ondergrond) | Subsurface data |
| Kadaster (BRK) | Land registry parcels |

### Spatial Querying

Objects with geospatial fields can be queried by location:

```
GET /api/objects/{register}/{schema}?locatie[within]={"type":"Polygon","coordinates":[...]}
GET /api/objects/{register}/{schema}?locatie[near]={"lat":52.370216,"lon":4.895168,"radius":500}
GET /api/objects/{register}/{schema}?locatie[bbox]=4.8,52.3,5.0,52.5
```

Spatial queries are executed via PostGIS (PostgreSQL) or equivalent Solr/Elasticsearch spatial plugins.

### Map Visualization

The admin UI includes a map view for schemas with geospatial properties:

- Leaflet-based map with PDOK background tiles
- Object locations shown as markers, lines, or polygons
- Cluster markers for large datasets
- Click on a marker to open the object detail panel
- Filter objects by drawing a bounding box on the map

### Faceting on Location

Geospatial properties with `facetable: true` produce geographic facets:

- Grid-based heat map showing object density
- Municipality/province boundary aggregation (via PDOK administrative boundaries)
- Distance-from-point facets (0-1km, 1-5km, 5-25km, >25km)

## Schema Configuration Example

```json
{
  "title": "Meldingen openbare ruimte",
  "properties": {
    "titel": { "type": "string" },
    "categorie": { "type": "string", "enum": ["wegdek", "groen", "verlichting"] },
    "locatie": {
      "type": "object",
      "format": "geojson",
      "pdokLookup": true,
      "coordinateSystem": "WGS84",
      "facetable": {
        "type": "geo_grid",
        "title": "Locatie"
      }
    },
    "adres": {
      "type": "object",
      "properties": {
        "straat": { "type": "string" },
        "huisnummer": { "type": "string" },
        "postcode": { "type": "string", "pattern": "^[0-9]{4}[A-Z]{2}$" },
        "stad": { "type": "string" }
      }
    }
  }
}
```

## Standards

| Standard | Role |
|----------|------|
| GeoJSON (RFC 7946) | Geospatial data format |
| WGS84 (EPSG:4326) | GPS coordinate reference system |
| RD New (EPSG:28992) | Dutch National Grid |
| PDOK Locatieserver API | Dutch government address/location lookup |
| PostGIS | Spatial query execution on PostgreSQL |

## Related Features

- [Registers & Schemas](registers-and-schemas.md) — geospatial properties configured on schemas
- [Search, Filtering & Faceting](search-and-faceting.md) — spatial queries and geographic facets
- [Object Storage & Lifecycle](object-storage.md) — GeoJSON stored as object properties
