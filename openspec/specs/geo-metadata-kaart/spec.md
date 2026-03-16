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
