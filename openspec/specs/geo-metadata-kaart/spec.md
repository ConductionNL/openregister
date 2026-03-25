---
status: draft
---

# geo-metadata-kaart Specification

## Purpose
Add geospatial metadata support and map visualization to register objects. Objects MUST support storing coordinates (point), polygons, and references to BAG/BGT base registrations. A map widget using Leaflet MUST visualize object locations, support clustering, and enable spatial queries for filtering objects by geographic area.

**Tender demand**: 35% of analyzed government tenders require geo/map capabilities.

## ADDED Requirements

### Requirement: Schema properties MUST support geospatial data types
Schema definitions MUST support point coordinates, polygons, and base registration references as property types.

#### Scenario: Define a point coordinate property
- GIVEN a schema `meldingen`
- WHEN the admin adds a property `locatie` with type `geo:point`
- THEN the property MUST accept values in GeoJSON Point format: `{"type": "Point", "coordinates": [5.1214, 52.0907]}`
- AND the coordinates MUST use WGS84 (EPSG:4326) by default

#### Scenario: Define a polygon property
- GIVEN a schema `gebieden`
- WHEN the admin adds a property `grenzen` with type `geo:polygon`
- THEN the property MUST accept GeoJSON Polygon format
- AND the polygon MUST be validated for closure (first and last coordinate match)

#### Scenario: Define a BAG address reference
- GIVEN a schema `vergunningen`
- WHEN the admin adds a property `adres` with type `geo:bag`
- THEN the property MUST accept a BAG nummeraanduiding identifier
- AND the system SHOULD resolve the BAG ID to coordinates via the BAG API

### Requirement: Objects MUST be visualizable on a map widget
The UI MUST include a Leaflet-based map widget that displays objects with geospatial properties on an interactive map.

#### Scenario: Display objects as map markers
- GIVEN 50 meldingen objects with `locatie` point coordinates
- WHEN the user opens the map view for schema `meldingen`
- THEN the map MUST display 50 markers at the correct locations
- AND clicking a marker MUST show a popup with the object title and a link to the detail view
- AND the map MUST use OpenStreetMap tiles by default

#### Scenario: Cluster markers at low zoom levels
- GIVEN 500 objects spread across the Netherlands
- WHEN the map is zoomed out to show the entire country
- THEN nearby markers MUST be clustered with a count badge
- AND zooming in MUST progressively uncluster markers

#### Scenario: Display polygon boundaries
- GIVEN schema `wijken` with polygon boundaries
- WHEN the map view is opened
- THEN each wijk MUST be displayed as a colored polygon overlay
- AND clicking a polygon MUST show the wijk details

### Requirement: The system MUST support spatial queries
API endpoints MUST support filtering objects by geographic criteria.

#### Scenario: Filter objects within a bounding box
- GIVEN 200 meldingen objects across a city
- WHEN the API receives GET /api/objects/{register}/{schema}?geo.bbox=5.10,52.05,5.15,52.10
- THEN only objects with coordinates within the bounding box MUST be returned

#### Scenario: Filter objects within radius of a point
- GIVEN 200 meldingen objects
- WHEN the API receives GET /api/objects/{register}/{schema}?geo.near=5.12,52.09&geo.radius=500
- THEN only objects within 500 meters of the specified point MUST be returned
- AND results SHOULD be sorted by distance from the center point

### Requirement: The system MUST integrate with BAG and BGT base registrations
Objects with BAG/BGT references MUST support lookup and enrichment from the national base registrations.

#### Scenario: Enrich object with BAG address data
- GIVEN an object with BAG nummeraanduiding ID `0363200000123456`
- WHEN the object is saved or enriched
- THEN the system MUST resolve the BAG ID to:
  - Street name, house number, postal code, city
  - WGS84 coordinates
- AND store the resolved data as enrichment metadata on the object

#### Scenario: Validate BAG reference
- GIVEN an object with BAG ID `9999999999999999` (non-existent)
- WHEN the object is saved with validateReference enabled
- THEN the system SHOULD warn that the BAG ID could not be resolved
- BUT the save MUST NOT be blocked (the BAG API may be temporarily unavailable)

### Requirement: The map widget MUST support layer toggling
The map MUST support toggling between different base layers and overlay layers.

#### Scenario: Switch between map layers
- GIVEN the map widget is displayed
- WHEN the user clicks the layer control
- THEN the user MUST be able to switch between:
  - OpenStreetMap (default)
  - Satellite imagery
  - Cadastral overlay (Dutch cadastral data)
- AND switching layers MUST preserve the current zoom level and marker positions

### Using Mock Register Data

The **BAG** mock register provides test data for BAG address resolution and geospatial features.

**Loading the register:**
```bash
# Load BAG register (32 addresses + 21 objects + 21 buildings, register slug: "bag", schemas: "nummeraanduiding", "verblijfsobject", "pand")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/bag_register.json
```

**Test data for this spec's use cases:**
- **BAG address references**: BAG `nummeraanduiding` records with 16-digit identification numbers -- test `geo:bag` property type resolution
- **Verblijfsobject coordinates**: BAG `verblijfsobject` records can be used for map marker display
- **Cross-municipality coverage**: BAG records span multiple municipalities (Amsterdam 0363, Rotterdam 0599, Den Haag 0518, etc.) -- test map clustering
- **Building data**: BAG `pand` records include `oorspronkelijkBouwjaar` -- test property display on map popups

### Current Implementation Status
- **Not implemented — geospatial data types**: No `geo:point`, `geo:polygon`, or `geo:bag` property types exist in the schema system. The current property types (`lib/Db/Schema.php`, `lib/Service/SchemaService.php`) do not include geospatial formats.
- **Not implemented — map widget**: No Leaflet or map-related components exist in the `src/` frontend directory. No map visualization code is present.
- **Not implemented — spatial queries**: No `geo.bbox`, `geo.near`, or `geo.radius` query parameters are handled in `MagicSearchHandler` (`lib/Db/MagicMapper/MagicSearchHandler.php`) or `ObjectsController` (`lib/Controller/ObjectsController.php`).
- **Not implemented — BAG/BGT integration**: No BAG API client or address resolution service exists in the codebase.
- **Not implemented — map layer toggling**: No UI layer controls exist.
- **Tangentially related**: `ObjectEntity` (`lib/Db/ObjectEntity.php`) stores arbitrary JSON properties, so GeoJSON data could be stored as-is, but no parsing, validation, or indexing logic exists.

### Standards & References
- GeoJSON specification (RFC 7946) for coordinate and polygon format
- WGS84 (EPSG:4326) coordinate reference system
- BAG API (Basisregistratie Adressen en Gebouwen) — Dutch national address registry, see https://bag.basisregistraties.overheid.nl/
- BGT (Basisregistratie Grootschalige Topografie) — Dutch topographic data
- PDOK (Publieke Dienstverlening Op de Kaart) — for OpenStreetMap, satellite, and cadastral tile layers
- Leaflet.js for map rendering (https://leafletjs.com/)
- Leaflet.markercluster for clustering support

### Specificity Assessment
- **Moderately specific**: The spec defines clear scenarios for point/polygon/BAG types, map rendering, spatial queries, and layer toggling.
- **Missing details**:
  - How geospatial data is indexed for spatial queries (PostGIS extension? Application-level filtering?)
  - Database requirements (PostgreSQL with PostGIS vs. application-level spatial calculations)
  - How Solr/Elasticsearch backends should handle spatial queries
  - Performance expectations for spatial queries on large datasets
  - Mobile/responsive behavior of the map widget
- **Open questions**:
  - Should the map widget be a standalone page or embeddable in the object list view?
  - What happens with objects that have invalid/missing coordinates?
  - Should BAG resolution happen synchronously on save or asynchronously?

## Nextcloud Integration Analysis

**Status**: Not yet implemented. No geospatial property types, map widget, spatial queries, or BAG integration exist in the codebase. GeoJSON data can be stored as arbitrary JSON in object properties but without validation or indexing.

**Nextcloud Core Interfaces**:
- `IPublicShareTemplateFactory` / Widget framework: The Leaflet map widget could be implemented as a Vue component within OpenRegister's frontend, rendered in object list views and detail views. For dashboard integration, implement `IDashboardWidget` to show a map overview widget on the Nextcloud dashboard.
- `routes.php`: Expose WFS/WMS-like endpoints (e.g., `/api/geo/{register}/{schema}`) for GeoJSON FeatureCollection output, enabling integration with external GIS tools and potentially the Nextcloud Maps app.
- `IAppConfig`: Store geo configuration (default tile server URL, BAG API endpoint, coordinate reference system preferences) in Nextcloud's app configuration.
- Nextcloud Maps integration: If the Nextcloud Maps app is installed, register OpenRegister geo objects as a map layer source via Maps' extension points (if available). Otherwise, provide standalone Leaflet-based visualization.

**Implementation Approach**:
- Add `geo:point`, `geo:polygon`, and `geo:bag` as recognized property types in the schema property system. Validation logic in `SchemaService` or a dedicated `GeoValidationHandler` ensures GeoJSON format compliance (RFC 7946) and polygon closure.
- Build a `MapWidget.vue` component using Leaflet.js with `leaflet.markercluster` for clustering. The widget reads objects with geo properties from the standard API and renders markers/polygons. Use PDOK tile services for Dutch government map layers (OpenStreetMap, satellite, cadastral).
- Implement spatial query parameters (`geo.bbox`, `geo.near`, `geo.radius`) in `MagicSearchHandler`. For database-level spatial queries, use PostgreSQL's built-in geometry functions or application-level Haversine filtering for SQLite/MySQL. For Solr/Elasticsearch backends, use native geo_shape queries.
- Create a `BagResolutionService` that calls the BAG API (via OpenConnector or direct HTTP) to resolve BAG nummeraanduiding IDs to coordinates and address data. Resolution can be triggered on save (synchronous) or via a `QueuedJob` (asynchronous).

**Dependencies on Existing OpenRegister Features**:
- `SchemaService` / property type system — extension point for new geo property types.
- `MagicSearchHandler` — query parameter parsing and filter execution for spatial queries.
- `ObjectService` — standard CRUD pipeline where geo validation hooks into pre-save.
- `ObjectEntity` — stores GeoJSON as part of the object's JSON data property.
- Frontend `src/views/` — integration point for the Leaflet map widget component.
