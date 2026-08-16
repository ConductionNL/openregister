---
status: draft
---

# Geo Metadata en Kaart

## Purpose
Enable OpenRegister to store, validate, query, and visualize geospatial data attached to register objects. Objects MUST support GeoJSON geometry types (Point, Polygon, MultiPolygon, LineString), coordinate reference system negotiation (WGS84/EPSG:4326 and RD New/EPSG:28992), and references to Dutch base registrations (BAG, BGT, BRT). A map visualization component MUST render object locations on interactive maps using PDOK tile services, support marker clustering for large datasets, and enable spatial filtering through both the UI and API. This spec positions OpenRegister as a geospatially-aware register platform that meets the spatial data requirements found in 35% of analyzed Dutch government tenders.

**Tender demand**: 35% of analyzed government tenders require geo/map capabilities. The VNG Objects API (competitor) already supports PostGIS geometry fields with `geometry.within` polygon queries and CRS header negotiation -- OpenRegister MUST match and extend this capability with richer spatial query operators, PDOK integration, and NL Design System-compliant map styling.

## ADDED Requirements

### Requirement: REQ-GEO-001 -- Schema properties MUST support geospatial data types
Schema definitions MUST support geospatial property types for storing coordinates, areas, and routes. Each geo property type MUST validate incoming data against the GeoJSON specification (RFC 7946). The system MUST support `geo:point`, `geo:polygon`, `geo:multipolygon`, `geo:linestring`, `geo:geometry` (any GeoJSON type), and `geo:bag` (BAG nummeraanduiding reference). These types SHALL be registered as first-class property types in `SchemaService` alongside existing types (string, integer, boolean, etc.).

#### Scenario: Define a point coordinate property
- **GIVEN** a schema `meldingen` is being configured by an admin
- **WHEN** the admin adds a property `locatie` with type `geo:point`
- **THEN** the property MUST accept values in GeoJSON Point format: `{"type": "Point", "coordinates": [5.1214, 52.0907]}`
- **AND** the coordinates MUST use WGS84 (EPSG:4326) by default
- **AND** longitude MUST be the first element (per RFC 7946) and MUST be between -180 and 180
- **AND** latitude MUST be the second element and MUST be between -90 and 90
- **AND** invalid coordinates (e.g., `[999, 999]`) MUST be rejected with a 422 validation error

#### Scenario: Define a polygon property with closure validation
- **GIVEN** a schema `gebieden` is being configured
- **WHEN** the admin adds a property `grenzen` with type `geo:polygon`
- **THEN** the property MUST accept GeoJSON Polygon format with an outer ring and optional inner rings (holes)
- **AND** each ring MUST contain at least 4 coordinate positions
- **AND** the first and last coordinate of each ring MUST be identical (closure validation)
- **AND** a polygon with an unclosed ring MUST be rejected with a 422 error indicating which ring is unclosed

#### Scenario: Define a multipolygon property for complex boundaries
- **GIVEN** a schema `gemeentegrenzen` requires storing municipalities that consist of multiple disconnected areas (e.g., islands)
- **WHEN** the admin adds a property `grondgebied` with type `geo:multipolygon`
- **THEN** the property MUST accept GeoJSON MultiPolygon format
- **AND** each constituent polygon MUST be individually validated for closure
- **AND** the system MUST store and return all polygons as a single GeoJSON MultiPolygon feature

#### Scenario: Define a linestring property for routes
- **GIVEN** a schema `wegwerkzaamheden` tracks road works
- **WHEN** the admin adds a property `traject` with type `geo:linestring`
- **THEN** the property MUST accept GeoJSON LineString format
- **AND** the linestring MUST contain at least 2 coordinate positions

#### Scenario: Define a BAG address reference property
- **GIVEN** a schema `vergunningen` needs to reference official Dutch addresses
- **WHEN** the admin adds a property `adres` with type `geo:bag`
- **THEN** the property MUST accept a BAG nummeraanduiding identifier (16-digit string, e.g., `0363200000123456`)
- **AND** the identifier format MUST be validated: 4-digit gemeentecode + 2-digit objecttypecode + 10-digit volgnummer
- **AND** the system SHOULD resolve the BAG ID to coordinates via the BAG API (see REQ-GEO-005)
- **AND** unresolvable BAG IDs MUST NOT block saves (the BAG API may be temporarily unavailable)

### Requirement: REQ-GEO-002 -- GeoJSON storage and indexing in MagicMapper
Geospatial data MUST be stored in GeoJSON format within the object's properties. For MagicMapper tables, geo properties MUST be stored in dedicated columns with appropriate database-level support. PostgreSQL deployments SHOULD use PostGIS geometry columns for native spatial indexing. MariaDB/MySQL deployments MUST use JSON columns with application-level spatial calculations.

#### Scenario: Store GeoJSON point in MagicMapper table
- **GIVEN** schema `meldingen` has a `geo:point` property `locatie` and uses MagicMapper storage
- **WHEN** an object is created with `locatie: {"type": "Point", "coordinates": [5.1214, 52.0907]}`
- **THEN** the MagicMapper table MUST store the GeoJSON in a dedicated column
- **AND** on PostgreSQL with PostGIS, the column SHOULD be of type `geometry(Point, 4326)` for native spatial indexing
- **AND** on MariaDB, the column MUST be a JSON column and spatial filtering SHALL use application-level Haversine calculations

#### Scenario: Store GeoJSON polygon in MagicMapper table
- **GIVEN** schema `wijken` has a `geo:polygon` property `grenzen`
- **WHEN** an object is created with a valid GeoJSON Polygon value
- **THEN** the polygon MUST be stored as a complete GeoJSON object preserving all coordinate precision
- **AND** on PostgreSQL with PostGIS, the polygon SHOULD be indexed with a GiST spatial index for efficient `ST_Within` and `ST_Intersects` queries

#### Scenario: Coordinate reference system storage
- **GIVEN** an object is submitted with coordinates in RD New (EPSG:28992) format via `Content-Crs: EPSG:28992` header
- **WHEN** the object is saved
- **THEN** the system MUST transform the coordinates to WGS84 (EPSG:4326) for internal storage
- **AND** when the client requests `Accept-Crs: EPSG:28992`, the response MUST transform coordinates back to RD New
- **AND** the response MUST include a `Content-Crs` header indicating the CRS of the returned geometry

#### Scenario: Spatial index creation during MagicMapper table setup
- **GIVEN** a schema with one or more `geo:*` properties is configured for MagicMapper
- **WHEN** the MagicMapper creates or updates the dedicated table
- **THEN** on PostgreSQL with PostGIS, each geo column MUST have a GiST spatial index created
- **AND** the index creation MUST be logged for monitoring
- **AND** if PostGIS is not installed, the system MUST fall back to JSON storage with a warning in the admin log

### Requirement: REQ-GEO-003 -- Map visualization component with PDOK tile layers
The UI MUST include an interactive map component that displays objects with geospatial properties. The map MUST use PDOK (Publieke Dienstverlening Op de Kaart) tile services as the default base layer, providing government-standard Dutch map tiles. The component MUST support marker clustering, polygon overlays, and responsive behavior.

#### Scenario: Display objects as map markers on PDOK base map
- **GIVEN** 50 `meldingen` objects with `locatie` point coordinates
- **WHEN** the user opens the map view for schema `meldingen`
- **THEN** the map MUST display 50 markers at the correct locations
- **AND** the default base layer MUST be PDOK BRT Achtergrondkaart (`https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0`)
- **AND** clicking a marker MUST show a popup with the object title, key properties, and a link to the detail view
- **AND** the map MUST auto-fit the viewport to contain all markers with appropriate padding

#### Scenario: Cluster markers at low zoom levels
- **GIVEN** 500+ objects spread across the Netherlands
- **WHEN** the map is zoomed out to show the entire country
- **THEN** nearby markers MUST be clustered with a count badge showing the number of grouped markers
- **AND** zooming in MUST progressively uncluster markers using spiderfication at the finest level
- **AND** clicking a cluster MUST zoom to the bounds of its constituent markers
- **AND** cluster colors MUST follow NL Design System color tokens (see REQ-GEO-014)

#### Scenario: Display polygon boundaries with styling
- **GIVEN** schema `wijken` with polygon boundaries
- **WHEN** the map view is opened
- **THEN** each wijk MUST be displayed as a filled polygon overlay with configurable fill color and opacity
- **AND** polygon borders MUST be visually distinct from fills (darker stroke, 2px weight)
- **AND** clicking a polygon MUST show the wijk name and key properties in a popup
- **AND** hovering over a polygon MUST highlight it with increased opacity

#### Scenario: Map view as toggle alongside table/card views
- **GIVEN** the object list view supports table and card view modes
- **WHEN** the schema contains at least one `geo:*` property
- **THEN** a map view toggle icon MUST appear in the view mode selector
- **AND** switching to map view MUST preserve any active search filters and facets
- **AND** the map view MUST show a sidebar or bottom panel listing the currently visible objects

#### Scenario: Responsive map behavior on mobile
- **GIVEN** the map view is displayed on a mobile device (viewport < 768px)
- **WHEN** the user interacts with the map
- **THEN** the map MUST be full-width and at least 300px tall
- **AND** the object list panel MUST collapse to a bottom sheet that can be swiped up
- **AND** touch gestures (pinch zoom, drag) MUST work without interfering with page scroll

### Requirement: REQ-GEO-004 -- Spatial queries in the API
API endpoints MUST support filtering objects by geographic criteria. Spatial query parameters MUST be available on the standard object list endpoints (`GET /api/objects/{register}/{schema}`) and via a dedicated search endpoint (`POST /api/objects/{register}/{schema}/geo-search`). The `MagicSearchHandler` MUST be extended to parse and execute spatial filters.

#### Scenario: Filter objects within a bounding box
- **GIVEN** 200 `meldingen` objects across a city
- **WHEN** the API receives `GET /api/objects/{register}/{schema}?geo.bbox=5.10,52.05,5.15,52.10`
- **THEN** only objects with geo properties whose coordinates fall within the bounding box (west,south,east,north) MUST be returned
- **AND** the bounding box parameter MUST accept exactly 4 comma-separated decimal values
- **AND** invalid bounding boxes (e.g., west > east) MUST return a 422 error

#### Scenario: Filter objects within radius of a point
- **GIVEN** 200 `meldingen` objects across a city
- **WHEN** the API receives `GET /api/objects/{register}/{schema}?geo.near=5.12,52.09&geo.radius=500`
- **THEN** only objects within 500 meters of the specified point MUST be returned
- **AND** results MUST be sorted by distance from the center point (ascending) unless another sort is specified
- **AND** each result MUST include a `_geo_distance` metadata field showing the distance in meters

#### Scenario: Filter objects within a polygon (geometry.within)
- **GIVEN** a set of objects with point coordinates
- **WHEN** the API receives `POST /api/objects/{register}/{schema}/geo-search` with body:
  ```json
  {
    "geometry": {
      "within": {
        "type": "Polygon",
        "coordinates": [[[4.8, 52.3], [5.0, 52.3], [5.0, 52.4], [4.8, 52.4], [4.8, 52.3]]]
      }
    }
  }
  ```
- **THEN** only objects whose geo property point lies within the specified polygon MUST be returned
- **AND** this MUST be compatible with the VNG Objects API `geometry.within` search pattern

#### Scenario: Filter objects that intersect a geometry
- **GIVEN** schema `wijken` with polygon boundaries and a query polygon that partially overlaps several wijken
- **WHEN** the API receives a geo-search with `"geometry": {"intersects": { ... polygon ... }}`
- **THEN** all wijken whose boundaries intersect (overlap, touch, or are within) the query polygon MUST be returned
- **AND** wijken completely outside the query polygon MUST NOT be returned

#### Scenario: Combine spatial and property filters
- **GIVEN** 200 `meldingen` objects with `locatie` and `status` properties
- **WHEN** the API receives `GET /api/objects/{register}/{schema}?geo.near=5.12,52.09&geo.radius=1000&status=open`
- **THEN** only objects within 1000 meters AND with `status=open` MUST be returned
- **AND** spatial filters MUST compose with all existing filter types (facet, search, date range)

### Requirement: REQ-GEO-005 -- Geocoding via PDOK Locatieserver
The system MUST support forward geocoding (address to coordinates) and reverse geocoding (coordinates to address) using the PDOK Locatieserver API (`https://api.pdok.nl/bzk/locatieserver/search/v3_1/`). This enables users to search for objects by address and automatically enrich objects with coordinates based on Dutch addresses.

#### Scenario: Forward geocoding -- address to coordinates
- **GIVEN** a user enters an address `Keizersgracht 123, Amsterdam` in the map search bar
- **WHEN** the system queries the PDOK Locatieserver `free` endpoint with `q=Keizersgracht+123+Amsterdam`
- **THEN** the map MUST center on the returned coordinates
- **AND** the system MUST display up to 5 autocomplete suggestions as the user types (debounced at 300ms)
- **AND** each suggestion MUST show the full address and type (adres, straat, woonplaats, postcode, gemeente)

#### Scenario: Reverse geocoding -- coordinates to address
- **GIVEN** a user clicks on the map to set a location for a new object
- **WHEN** the click coordinates are captured
- **THEN** the system MUST call the PDOK Locatieserver `reverse` endpoint with the coordinates
- **AND** the nearest address MUST be displayed and offered as a pre-fill for address fields
- **AND** if no address is found within 100 meters, the system MUST show the raw coordinates

#### Scenario: Auto-geocode address properties on save
- **GIVEN** a schema `vergunningen` has a text property `adres` and a `geo:point` property `locatie`
- **AND** a geocoding rule is configured linking `adres` to `locatie`
- **WHEN** an object is saved with `adres: "Markt 1, 2611 GP Delft"` but no `locatie` value
- **THEN** the system MUST automatically geocode the address via PDOK Locatieserver
- **AND** the resolved coordinates MUST be stored in the `locatie` property
- **AND** a `_geocoded` metadata flag MUST be set to `true` on the object

#### Scenario: Geocoding failure handling
- **GIVEN** the PDOK Locatieserver is unreachable or returns no results
- **WHEN** an object is saved with an address that cannot be geocoded
- **THEN** the object MUST still be saved successfully (geocoding is non-blocking)
- **AND** the `locatie` property MUST remain null
- **AND** a warning MUST be logged indicating the geocoding failure
- **AND** a background job SHOULD retry geocoding for objects with empty coordinates

### Requirement: REQ-GEO-006 -- BAG and BGT base registration integration
Objects with BAG (Basisregistratie Adressen en Gebouwen) or BGT (Basisregistratie Grootschalige Topografie) references MUST support lookup and enrichment from the national base registrations via their public APIs. BAG integration enables address validation, coordinate resolution, and building data enrichment. BGT integration enables topographic boundary display.

#### Scenario: Enrich object with BAG address data
- **GIVEN** an object with a `geo:bag` property set to BAG nummeraanduiding ID `0363200000123456`
- **WHEN** the object is saved or explicitly enriched via an API call
- **THEN** the system MUST call the BAG API (`https://api.bag.kadaster.nl/lvbag/individuelebevragingen/v2/nummeraanduidingen/{id}`)
- **AND** resolve the BAG ID to: street name, house number, house letter, house number addition, postal code, city (woonplaats)
- **AND** resolve the associated verblijfsobject to WGS84 coordinates
- **AND** store the resolved data as enrichment metadata: `_bag_enrichment: { straat, huisnummer, postcode, woonplaats, coordinates, resolvedAt }`

#### Scenario: Validate BAG reference exists
- **GIVEN** an object with BAG ID `9999999999999999` (non-existent)
- **WHEN** the object is saved with BAG validation enabled (configurable per schema)
- **THEN** the system SHOULD warn that the BAG ID could not be resolved
- **BUT** the save MUST NOT be blocked (the BAG API may be temporarily unavailable)
- **AND** the enrichment metadata MUST include `_bag_validation: { status: "not_found", checkedAt: "2026-03-19T10:00:00Z" }`

#### Scenario: BAG address search for object creation
- **GIVEN** a user is creating a new object in a schema with a `geo:bag` property
- **WHEN** the user types an address in the BAG search field
- **THEN** the system MUST query the PDOK Locatieserver with `fq=type:adres` to find matching BAG addresses
- **AND** each result MUST include the BAG nummeraanduiding ID, full address, and coordinates
- **AND** selecting a result MUST populate both the `geo:bag` field and any linked `geo:point` field

#### Scenario: Display BAG/BGT data on the map
- **GIVEN** objects in a register have BAG references with resolved coordinates
- **WHEN** the map view is opened
- **THEN** the user MUST be able to toggle a BAG/BGT overlay layer
- **AND** the BAG layer MUST show building footprints from PDOK WMS (`https://service.pdok.nl/lv/bag/wms/v2_0`)
- **AND** the BGT layer MUST show topographic features from PDOK WMS (`https://service.pdok.nl/lv/bgt/wms/v1_0`)

### Requirement: REQ-GEO-007 -- Multi-layer map views with layer control
The map MUST support multiple overlay layers and base layer switching. Users MUST be able to toggle individual layers on/off, adjust layer opacity, and configure which schema properties drive layer rendering.

#### Scenario: Switch between base map layers
- **GIVEN** the map widget is displayed
- **WHEN** the user clicks the layer control
- **THEN** the user MUST be able to switch between at least:
  - PDOK BRT Achtergrondkaart (default, standard Dutch topographic map)
  - PDOK BRT Achtergrondkaart Grijs (greyscale variant for data overlays)
  - PDOK Luchtfoto (aerial/satellite imagery from `https://service.pdok.nl/hwh/luchtfotorgb/wmts/v1_0`)
  - OpenStreetMap (international fallback)
- **AND** switching layers MUST preserve the current zoom level, center position, and all overlay markers

#### Scenario: Display multiple schemas as separate overlay layers
- **GIVEN** a register `publieke-ruimte` has schemas `meldingen`, `speeltuinen`, and `afvalcontainers`, each with geo properties
- **WHEN** the map view is opened at the register level
- **THEN** each schema MUST appear as a separate toggleable overlay layer with a distinct marker color/icon
- **AND** the layer control MUST show a legend with schema name, marker style, and object count per layer
- **AND** toggling a layer off MUST hide all markers of that schema without affecting other layers

#### Scenario: Cadastral overlay from Kadaster
- **GIVEN** the map view is displayed for a register dealing with property/land data
- **WHEN** the user enables the "Kadastrale kaart" overlay
- **THEN** the map MUST display the Kadaster DKK (Digitale Kadastrale Kaart) from PDOK WMS (`https://service.pdok.nl/kadaster/kadastralekaart/wms/v5_0`)
- **AND** parcel boundaries and cadastral designations MUST be visible as an overlay

#### Scenario: Adjust layer opacity
- **GIVEN** the user has enabled both the aerial photo base layer and a polygon overlay for wijken
- **WHEN** the user adjusts the polygon overlay opacity via a slider in the layer control
- **THEN** the polygon fill opacity MUST update in real-time
- **AND** the opacity value MUST persist in the user's browser local storage for that schema

### Requirement: REQ-GEO-008 -- WFS and GeoJSON export
The system MUST support exporting register objects with geospatial data as GeoJSON FeatureCollections. A WFS-like endpoint MUST be provided for integration with external GIS tools (QGIS, ArcGIS).

#### Scenario: Export objects as GeoJSON FeatureCollection
- **GIVEN** schema `meldingen` has 100 objects with `locatie` point coordinates
- **WHEN** the API receives `GET /api/objects/{register}/{schema}?_format=geojson`
- **THEN** the response MUST be a valid GeoJSON FeatureCollection
- **AND** each object MUST be a Feature with its geo property as the geometry and other properties as Feature properties
- **AND** the response MUST include `Content-Type: application/geo+json`

#### Scenario: GeoJSON export with property selection
- **GIVEN** objects have 20 properties but the user only needs `title`, `status`, and `locatie`
- **WHEN** the API receives `GET /api/objects/{register}/{schema}?_format=geojson&_fields=title,status`
- **THEN** each Feature's properties MUST contain only `title` and `status`
- **AND** the geometry MUST always be included regardless of `_fields` selection

#### Scenario: WFS GetFeature-compatible endpoint
- **GIVEN** a GIS analyst wants to load register data into QGIS
- **WHEN** they configure a WFS connection to `GET /api/geo/{register}/{schema}/wfs?service=WFS&request=GetFeature&outputFormat=application/json`
- **THEN** the response MUST be a GeoJSON FeatureCollection compatible with WFS GetFeature responses
- **AND** the endpoint MUST support `bbox` and `maxFeatures` (or `count`) parameters
- **AND** the endpoint MUST advertise itself in a WFS GetCapabilities response listing available schemas as feature types

#### Scenario: Export polygons with area calculations
- **GIVEN** schema `gebieden` has polygon boundaries
- **WHEN** exported as GeoJSON
- **THEN** each Feature MUST include a computed `_area_m2` property showing the polygon area in square meters
- **AND** the area MUST be calculated using geodesic measurements (accounting for earth curvature)

### Requirement: REQ-GEO-009 -- INSPIRE metadata compliance
Register schemas with geospatial data MUST support INSPIRE (Infrastructure for Spatial Information in the European Community) metadata when required for government interoperability. INSPIRE metadata elements MUST be storable as schema-level configuration.

#### Scenario: Configure INSPIRE metadata for a schema
- **GIVEN** a schema `milieuzones` is being configured by an admin
- **WHEN** the admin enables INSPIRE metadata on the schema
- **THEN** the admin MUST be able to configure:
  - Resource title and abstract
  - Topic category (e.g., `environment`, `transportation`, `planningCadastre`)
  - Spatial resolution (e.g., `1:10000`)
  - Temporal extent (date range the data covers)
  - Lineage statement (data source description)
  - Conformity to INSPIRE data specifications
- **AND** this metadata MUST be stored in the schema's configuration

#### Scenario: Expose INSPIRE metadata via CSW-compatible response
- **GIVEN** a schema has INSPIRE metadata configured
- **WHEN** an external system queries `GET /api/geo/{register}/{schema}/metadata`
- **THEN** the response MUST include INSPIRE-compliant metadata elements in ISO 19115/19119 format
- **AND** the metadata MUST be valid for submission to the PDOK metadata catalog (NGR -- Nationaal Georegister)

#### Scenario: INSPIRE metadata defaults for Dutch municipalities
- **GIVEN** a new schema with geo properties is created
- **WHEN** INSPIRE metadata is enabled
- **THEN** the system MUST pre-fill sensible defaults:
  - Spatial reference system: EPSG:28992 (RD New) and EPSG:4326 (WGS84)
  - Access constraints: `geen beperkingen` (unless configured otherwise)
  - Metadata language: `dut` (Dutch) with `eng` (English) as alternate
- **AND** these defaults MUST be editable by the admin

### Requirement: REQ-GEO-010 -- Geo-fencing with event triggers
The system MUST support defining geographic boundaries (geo-fences) on schemas. When an object enters, exits, or is created within a geo-fence boundary, the system MUST fire events that can trigger n8n workflows or webhooks.

#### Scenario: Define a geo-fence on a schema
- **GIVEN** a schema `voertuigen` tracks vehicle positions
- **WHEN** an admin defines a geo-fence named `milieuzone-centrum` with a polygon boundary
- **THEN** the geo-fence MUST be stored as a schema-level configuration with a name, GeoJSON polygon, and event types (enter, exit, create)
- **AND** the geo-fence boundary MUST be validated for closure and minimum area (> 100 m2)

#### Scenario: Trigger event on object entering a geo-fence
- **GIVEN** a geo-fence `milieuzone-centrum` is configured on schema `voertuigen`
- **AND** object `voertuig-1` has `locatie` outside the geo-fence
- **WHEN** `voertuig-1` is updated with a new `locatie` that falls inside the geo-fence polygon
- **THEN** the system MUST fire an `ObjectEnteredGeoFence` event with the object ID, geo-fence name, and timestamp
- **AND** the event MUST be available to n8n workflows and webhook handlers

#### Scenario: Trigger event on object creation within a geo-fence
- **GIVEN** a geo-fence `stadsdeel-noord` is configured on schema `meldingen` with event type `create`
- **WHEN** a new `melding` is created with `locatie` inside `stadsdeel-noord`
- **THEN** an `ObjectCreatedInGeoFence` event MUST be fired
- **AND** the event payload MUST include the object data, geo-fence name, and matched boundary ID

#### Scenario: Multiple overlapping geo-fences
- **GIVEN** two geo-fences `wijk-centrum` and `milieuzone` overlap in a central area
- **WHEN** an object is created with coordinates in the overlapping area
- **THEN** events MUST be fired for BOTH geo-fences
- **AND** each event MUST reference its specific geo-fence

### Requirement: REQ-GEO-011 -- Geo-filtering in search and facets
The existing search and facet system (per zoeken-filteren spec) MUST be extended with geospatial facets and map-driven filtering. Users MUST be able to filter search results by drawing a polygon on the map or selecting predefined areas.

#### Scenario: Map-driven bounding box filter
- **GIVEN** the map view is displayed with 500 objects
- **WHEN** the user pans and zooms the map to a specific area
- **THEN** an optional "filter to map extent" toggle MUST limit the object list to only objects visible on the current map viewport
- **AND** the bounding box filter MUST update as the user pans/zooms (debounced at 500ms)
- **AND** the object count in the list header MUST reflect the spatial filter

#### Scenario: Draw polygon filter on map
- **GIVEN** the map view is displayed
- **WHEN** the user activates the "draw filter area" tool and draws a polygon on the map
- **THEN** the object list MUST filter to only objects within the drawn polygon
- **AND** the drawn polygon MUST be editable (move vertices, add/remove vertices)
- **AND** the polygon filter MUST compose with existing search text and facet filters

#### Scenario: Predefined area facets (wijken, stadsdelen)
- **GIVEN** a register has a reference schema `wijken` with polygon boundaries
- **AND** facets are configured with a `geo:area` facet type referencing the `wijken` schema
- **WHEN** the user opens the facet panel
- **THEN** a geographic facet MUST show `wijken` as clickable filter options with object counts
- **AND** selecting a wijk MUST filter results to objects whose coordinates fall within that wijk's polygon
- **AND** the selected wijk MUST be highlighted on the map

#### Scenario: Distance facet (proximity rings)
- **GIVEN** a user has set a center point (via address search or map click)
- **WHEN** a distance facet is configured
- **THEN** the facet MUST show proximity rings: `< 500m`, `500m - 1km`, `1km - 5km`, `> 5km`
- **AND** each ring MUST show the count of objects at that distance
- **AND** selecting a ring MUST filter the object list and visually show the ring on the map

### Requirement: REQ-GEO-012 -- Solr and Elasticsearch spatial query support
When OpenRegister is configured with Solr or Elasticsearch as a search backend, spatial queries MUST leverage the native geo capabilities of these engines for optimal performance on large datasets.

#### Scenario: Solr spatial field mapping
- **GIVEN** a schema with a `geo:point` property `locatie` is registered and Solr is the search backend
- **WHEN** the `SolrEventListener` creates field mappings for the schema
- **THEN** the `locatie` field MUST be mapped to a Solr `location` (LatLonPointSpatialField) field type
- **AND** the Solr schema MUST include the dynamic field mapping for spatial queries

#### Scenario: Elasticsearch geo_shape queries
- **GIVEN** Elasticsearch is the search backend and a schema has polygon geo properties
- **WHEN** a `geometry.within` search is performed
- **THEN** the system MUST translate the query to an Elasticsearch `geo_shape` query with `relation: within`
- **AND** performance MUST be comparable to native Elasticsearch spatial queries (< 100ms for 100k objects)

#### Scenario: Fallback to application-level spatial filtering
- **GIVEN** no external search backend is configured (pure database mode)
- **WHEN** a spatial query is performed on a MariaDB/MySQL database without spatial extensions
- **THEN** the system MUST use application-level Haversine distance calculations for radius queries
- **AND** bounding box queries MUST use simple coordinate range comparisons on the JSON column
- **AND** polygon containment queries MUST use a ray-casting algorithm implementation

### Requirement: REQ-GEO-013 -- Map drawing and geometry editing
The map component MUST support interactive geometry creation and editing for objects with geo properties. Users MUST be able to draw points, lines, and polygons directly on the map when creating or editing objects.

#### Scenario: Draw a point on the map
- **GIVEN** a user is creating a new object in a schema with a `geo:point` property
- **WHEN** the user clicks the "set location on map" button
- **THEN** the map MUST enter point-placement mode
- **AND** clicking the map MUST place a draggable marker at the clicked location
- **AND** the GeoJSON Point coordinates MUST be automatically populated in the form field
- **AND** the coordinates MUST update in real-time as the marker is dragged

#### Scenario: Draw a polygon on the map
- **GIVEN** a user is editing an object in a schema with a `geo:polygon` property
- **WHEN** the user clicks the "draw boundary" button
- **THEN** the map MUST enter polygon-drawing mode
- **AND** each click MUST add a vertex to the polygon with visual feedback (line segments connecting vertices)
- **AND** double-clicking or clicking the first vertex MUST close the polygon
- **AND** the completed polygon MUST be editable: vertices can be dragged, added (click midpoint), or removed (right-click)

#### Scenario: Edit existing geometry
- **GIVEN** an object has an existing polygon boundary displayed on the map
- **WHEN** the user clicks "edit geometry"
- **THEN** the polygon MUST become editable with draggable vertices
- **AND** the original geometry MUST be preserved until the user explicitly saves
- **AND** an "undo" button MUST revert the last vertex change (up to 20 undo steps)

#### Scenario: Snap to PDOK reference data
- **GIVEN** the user is drawing a polygon on the map
- **WHEN** a vertex is placed near a known boundary (BAG building footprint, BGT feature, kadastrale grens)
- **THEN** the system SHOULD offer snap-to-boundary assistance (within 5 meter tolerance)
- **AND** snapping MUST be toggleable via a control on the map toolbar

### Requirement: REQ-GEO-014 -- NL Design System map styling
The map component MUST follow NL Design System (NL DS) design guidelines for consistent government UI styling. Colors, typography, and interactive elements MUST use NL DS design tokens where applicable.

#### Scenario: Map controls styled with NL Design System tokens
- **GIVEN** the map component is rendered in a Nextcloud instance with NL Design System theming enabled
- **WHEN** the map is displayed
- **THEN** zoom controls, layer controls, and search bars MUST use NL DS button and input component styles
- **AND** colors MUST use CSS custom properties from the active NL DS theme (e.g., `--nl-button-primary-background-color`)
- **AND** focus indicators on interactive elements MUST meet WCAG 2.1 AA contrast requirements

#### Scenario: Marker and cluster styling with theme colors
- **GIVEN** an NL DS theme is active (e.g., `@nl-design-system/gemeente-den-haag`)
- **WHEN** markers and clusters are rendered on the map
- **THEN** marker colors MUST use the theme's primary and secondary colors
- **AND** cluster badges MUST use the theme's surface and text colors
- **AND** popup cards MUST follow NL DS card component patterns (border-radius, shadow, padding)

#### Scenario: Map accessibility compliance
- **GIVEN** a screen reader user navigates to the map view
- **WHEN** the map component receives focus
- **THEN** the map MUST have an `aria-label` describing its content (e.g., "Kaart met 50 meldingen in Amsterdam")
- **AND** all map controls MUST be keyboard-navigable (Tab to controls, Enter/Space to activate)
- **AND** a text-based alternative MUST be available: a "list view" link next to the map showing the same data as an accessible table
- **AND** marker popups MUST be accessible via keyboard (Enter on focused marker)

### Requirement: REQ-GEO-015 -- Coordinate transformation and Dutch grid support
The system MUST support coordinate transformations between WGS84 (EPSG:4326) and RD New / Amersfoort (EPSG:28992), the official Dutch national coordinate reference system. This is essential for interoperability with Dutch government systems that use RD coordinates.

#### Scenario: Accept RD New coordinates in API input
- **GIVEN** a client submits an object with coordinates in RD New format
- **WHEN** the request includes header `Content-Crs: EPSG:28992` and coordinates `[121687, 487484]` (Amsterdam Centraal in RD)
- **THEN** the system MUST transform the coordinates to WGS84 for storage: approximately `[4.9003, 52.3791]`
- **AND** the transformation MUST use the official RD-NAP to ETRS89 transformation (RDNAPTRANS2018)
- **AND** the stored GeoJSON MUST always use WGS84 internally

#### Scenario: Return coordinates in requested CRS
- **GIVEN** a client requests `Accept-Crs: EPSG:28992`
- **WHEN** objects with geo properties are returned
- **THEN** all coordinates in the response MUST be transformed to RD New
- **AND** the response MUST include `Content-Crs: EPSG:28992` header

#### Scenario: Display RD coordinates in UI
- **GIVEN** a Dutch government user prefers RD coordinates over WGS84
- **WHEN** the user configures their preference via app settings
- **THEN** all coordinate displays in popups, forms, and detail views MUST show RD New coordinates
- **AND** the map visualization itself MUST still use WGS84 (as required by web map tile services)
- **AND** both CRS values MUST be shown on hover for transparency

#### Scenario: Reject unsupported CRS
- **GIVEN** a client submits a request with `Content-Crs: EPSG:3857` (Web Mercator, not suitable for Dutch government data)
- **WHEN** the system processes the request
- **THEN** it MUST return a 406 error with message indicating supported CRS values: `EPSG:4326`, `EPSG:28992`

## Using Mock Register Data

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

**DSO register for geo integration testing:**
```bash
# Load DSO register with locatie objects containing gemeente references
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/dso_register.json
```
- **DSO locatie objects**: Contain `gemeenteCode` and `adres` data, usable for testing geocoding and BAG cross-referencing (see dso-omgevingsloket spec)

## Current Implementation Status
- **Not implemented -- geospatial data types**: No `geo:point`, `geo:polygon`, `geo:multipolygon`, `geo:linestring`, or `geo:bag` property types exist in the schema system. The current property types in `lib/Db/Schema.php` and `lib/Service/SchemaService.php` do not include geospatial formats. GeoJSON data can be stored as arbitrary JSON in object properties but without type-specific validation, indexing, or coordinate system handling.
- **Not implemented -- map widget**: No Leaflet, OpenLayers, or map-related components exist in the `src/` frontend directory. No PDOK tile layer configuration exists.
- **Not implemented -- spatial queries**: No `geo.bbox`, `geo.near`, `geo.radius`, or `geometry.within` query parameters are handled in `MagicSearchHandler` (`lib/Db/MagicMapper/MagicSearchHandler.php`) or `ObjectsController` (`lib/Controller/ObjectsController.php`).
- **Not implemented -- BAG/BGT integration**: No BAG API client, PDOK Locatieserver client, or address resolution service exists in the codebase.
- **Not implemented -- map layer toggling**: No UI layer controls exist.
- **Not implemented -- geo-fencing**: No geo-fence entity, boundary check logic, or `ObjectEnteredGeoFence` events exist.
- **Not implemented -- CRS transformation**: No EPSG:28992 (RD New) to EPSG:4326 (WGS84) transformation code exists.
- **Not implemented -- INSPIRE metadata**: No INSPIRE metadata storage or CSW-compatible endpoint exists.
- **Not implemented -- WFS/GeoJSON export**: No `_format=geojson` support or WFS endpoint exists. The existing export infrastructure (CSV, Excel) does not handle geo formats.
- **Partially related -- Solr spatial**: `SolrEventListener` (`lib/EventListener/SolrEventListener.php`) handles schema-to-Solr field mappings but does not map geo property types to Solr spatial field types.
- **Tangentially related**: `ObjectEntity` (`lib/Db/ObjectEntity.php`) stores arbitrary JSON properties, so GeoJSON data could be stored as-is, but no parsing, validation, or indexing logic exists.
- **Competitor reference**: The VNG Objects API (analyzed in `concurrentie-analyse/openregister/objects-api/`) implements PostGIS geometry with `geometry.within` polygon queries, CRS header negotiation (`Content-Crs`/`Accept-Crs`), and a `GeometryValidator`. OpenRegister MUST match this baseline and extend it with richer spatial operators, PDOK integration, and the map visualization UI.

## Standards & References
- **GeoJSON**: RFC 7946 -- The GeoJSON Format (coordinate ordering: longitude, latitude)
- **WGS84**: EPSG:4326 -- World Geodetic System 1984 (default CRS for web mapping and GeoJSON)
- **RD New**: EPSG:28992 -- Amersfoort / RD New (official Dutch national coordinate reference system)
- **RDNAPTRANS2018**: Official coordinate transformation between RD/NAP and ETRS89/WGS84
- **BAG API**: Basisregistratie Adressen en Gebouwen -- `https://api.bag.kadaster.nl/lvbag/individuelebevragingen/v2/`
- **BGT**: Basisregistratie Grootschalige Topografie -- Dutch large-scale topographic data
- **BRT**: Basisregistratie Topografie -- Dutch national topographic map data
- **PDOK**: Publieke Dienstverlening Op de Kaart -- `https://www.pdok.nl/`
  - BRT Achtergrondkaart (WMTS): `https://service.pdok.nl/brt/achtergrondkaart/wmts/v2_0`
  - Luchtfoto (WMTS): `https://service.pdok.nl/hwh/luchtfotorgb/wmts/v1_0`
  - BAG WMS: `https://service.pdok.nl/lv/bag/wms/v2_0`
  - BGT WMS: `https://service.pdok.nl/lv/bgt/wms/v1_0`
  - DKK WMS (Kadaster): `https://service.pdok.nl/kadaster/kadastralekaart/wms/v5_0`
  - Locatieserver: `https://api.pdok.nl/bzk/locatieserver/search/v3_1/`
- **INSPIRE**: Directive 2007/2/EC -- Infrastructure for Spatial Information in the European Community
- **ISO 19115/19119**: Geographic information -- Metadata standards
- **NGR**: Nationaal Georegister -- Dutch national metadata catalog for geo datasets
- **Kadaster**: Dutch Land Registry -- cadastral maps and parcel data
- **WFS**: OGC Web Feature Service -- standard for requesting geographic features
- **WMS**: OGC Web Map Service -- standard for rendering map images
- **Leaflet.js**: Interactive map library -- `https://leafletjs.com/`
- **Leaflet.markercluster**: Clustering plugin for Leaflet -- `https://github.com/Leaflet/Leaflet.markercluster`
- **Leaflet.draw**: Drawing and editing plugin for Leaflet -- `https://github.com/Leaflet/Leaflet.draw`
- **VNG Objects API geo pattern**: `POST /objects/search` with `geometry.within` polygon query (see `concurrentie-analyse/openregister/objects-api/docs/api-reference.md`)
- **NL Design System**: Government UI design system -- `https://nldesignsystem.nl/`

## Cross-references
- **dso-omgevingsloket**: DSO locatie objects contain geographic references (gemeenteCode, adres) that benefit from geo property types and map visualization. DSO vergunningaanvragen reference locaties that should be displayable on maps.
- **zoeken-filteren**: The existing search and facet system MUST be extended with spatial facets (area-based, distance-based) and map-driven filtering. Spatial queries compose with existing text search and property facets.
- **data-import-export**: GeoJSON export format (`_format=geojson`) extends the existing export infrastructure. WFS endpoint provides GIS-tool-compatible data access.
- **schema-hooks**: Geo-fence events (`ObjectEnteredGeoFence`, `ObjectCreatedInGeoFence`) use the existing event dispatch system.
- **audit-trail-immutable**: Geo property changes (coordinate updates, BAG enrichment) MUST be recorded in the audit trail.
- **mariadb-ci-matrix**: Spatial query implementation MUST work on both PostgreSQL (with optional PostGIS) and MariaDB (application-level fallback).

## Nextcloud Integration Analysis

**Status**: Not yet implemented. No geospatial property types, map widget, spatial queries, BAG/PDOK integration, CRS transformation, INSPIRE metadata, or geo-fencing exist in the codebase. GeoJSON data can be stored as arbitrary JSON in object properties but without validation, indexing, or visualization.

**Nextcloud Core Interfaces**:
- `IWidget` / Dashboard framework: Implement a `GeoMapDashboardWidget` to show a map overview widget on the Nextcloud dashboard, displaying recent objects with locations across all registers.
- `routes.php`: Expose geo endpoints: `/api/geo/{register}/{schema}/wfs` for WFS-compatible access, `/api/objects/{register}/{schema}/geo-search` for spatial queries, `/api/geo/{register}/{schema}/metadata` for INSPIRE metadata.
- `IAppConfig`: Store geo configuration (PDOK tile server URLs, BAG API key, default CRS, Locatieserver endpoint, geo-fence definitions) in Nextcloud's app configuration.
- `IEventDispatcher`: Dispatch `ObjectEnteredGeoFence` and `ObjectCreatedInGeoFence` events through the existing event system for n8n workflow triggers and webhooks.
- Nextcloud Maps integration: If the Nextcloud Maps app is installed, register OpenRegister geo objects as a map layer source via Maps' extension points. Otherwise, provide standalone Leaflet-based visualization.

**Implementation Approach**:
- Add `geo:point`, `geo:polygon`, `geo:multipolygon`, `geo:linestring`, `geo:geometry`, and `geo:bag` as recognized property types in the schema property system. Create a `GeoValidationHandler` in `lib/Service/Object/` for RFC 7946 compliance validation (coordinate ranges, polygon closure, ring ordering).
- Build a `MapView.vue` component using Leaflet.js with `leaflet.markercluster` for clustering and `leaflet.draw` for geometry editing. Use PDOK WMTS tile services for Dutch government map layers. Integrate with the existing view mode selector (table, card, map).
- Implement spatial query parameters (`geo.bbox`, `geo.near`, `geo.radius`, `geometry.within`, `geometry.intersects`) in `MagicSearchHandler`. For PostgreSQL with PostGIS, use native `ST_Within`, `ST_Intersects`, `ST_DWithin` functions. For MariaDB, use application-level Haversine filtering and ray-casting. For Solr/Elasticsearch, use native geo_shape/spatial queries.
- Create a `PdokService` in `lib/Service/` wrapping PDOK Locatieserver (geocoding), BAG API (address resolution), and providing CRS transformation (WGS84 <-> RD New) via PHP math or the `proj4php` library.
- Create a `GeoFenceService` in `lib/Service/` that stores fence definitions per schema, evaluates point-in-polygon on object save/update, and fires events via `IEventDispatcher`.
- Extend `SolrEventListener` to map `geo:*` property types to Solr `location` (LatLonPointSpatialField) fields for native spatial search performance.

**Dependencies on Existing OpenRegister Features**:
- `SchemaService` / property type system -- extension point for new geo property types and validation.
- `MagicSearchHandler` -- query parameter parsing and filter execution for spatial queries.
- `MagicMapper` -- table creation with spatial columns/indexes for geo properties.
- `MagicFacetHandler` -- extension point for geographic facets (area, distance).
- `ObjectService` -- standard CRUD pipeline where geo validation and geo-fence evaluation hook into pre-save/post-save.
- `ObjectEntity` -- stores GeoJSON as part of the object's JSON data property.
- `SolrEventListener` -- spatial field mapping for Solr search backend.
- `Object/ExportHandler` -- extension point for GeoJSON export format.
- Frontend `src/views/` -- integration point for the Leaflet map widget component.
- Event system (`IEventDispatcher`) -- foundation for geo-fence event triggers.
