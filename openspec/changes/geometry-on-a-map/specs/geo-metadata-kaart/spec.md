# geo-metadata-kaart Specification (delta)

## ADDED Requirements

### Requirement: REQ-GEOMAP-001 A geometry is validated on save

Saving a record SHALL validate every property declared as a geometry against GeoJSON, and SHALL refuse an invalid geometry with 400 naming the property.

#### Scenario: an invalid polygon is refused

- **GIVEN** a schema with a geometry property `area`
- **WHEN** a client saves a record whose `area` is a polygon with an unclosed ring
- **THEN** the API answers 400 and the message names `area`
- @e2e exclude {specified only; task 1 adds the test}

### Requirement: REQ-GEOMAP-002 Records with a geometry can be seen and searched on a map

The records list of a schema with a geometry property SHALL offer a map presentation that shows each record at its geometry, and a user SHALL be able to draw an area to narrow the list to the records inside it.

#### Scenario: a user finds the records inside an area

- **GIVEN** a schema with a geometry property and records in two neighbourhoods
- **WHEN** a user opens the map on /tables and draws a polygon around one neighbourhood
- **THEN** the list shows only the records whose geometry lies inside the polygon
- @e2e exclude {specified only; task 2 adds the test}
