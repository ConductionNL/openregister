## ADDED Requirements

### Requirement: The MDTO export carries the parties and the containment between records

The MDTO XML for a record SHALL include a `betrokkene` element for each party the schema names as involved, and SHALL include `isOnderdeelVan` and `bevatOnderdeel` when the schema declares which relation expresses containment. A schema that declares neither SHALL get exactly the export it gets today.

#### Scenario: A record with a declared party names it in the export

- **GIVEN** a schema whose archival block names `aanvrager` as the party property
- **AND** a record whose `aanvrager` is the organisation `Gemeente Zuiddrecht`
- **WHEN** the record is exported as MDTO XML
- **THEN** the document holds one `betrokkene` naming `Gemeente Zuiddrecht`
- **AND** the document validates against the vendored MDTO-XML 1.0.1 schema

#### Scenario: A parent and its children point at each other

- **GIVEN** a schema whose archival block names `onderdelen` as the containment relation
- **AND** a parent record with two child records in `onderdelen`
- **WHEN** the three records are exported
- **THEN** the parent's document lists both children under `bevatOnderdeel`
- **AND** each child's document names the parent under `isOnderdeelVan`

#### Scenario: A schema without the declarations keeps today's export

- **GIVEN** a schema whose archival block names no party and no containment relation
- **WHEN** a record of it is exported
- **THEN** the document holds no `betrokkene`, `isOnderdeelVan` or `bevatOnderdeel` element
