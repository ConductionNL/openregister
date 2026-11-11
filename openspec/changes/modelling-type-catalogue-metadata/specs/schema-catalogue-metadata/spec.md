# schema-catalogue-metadata

## ADDED Requirements

### Requirement: A schema carries a data classification

A schema SHALL accept a `classification` of `open`, `internal`, `confidential` or
`strictly-confidential`, or no classification. The system MUST refuse any other
value with a 400 that names the field. The classification MUST NOT change who may
read the schema or its objects.

#### Scenario: A functional administrator classifies a record type

- **GIVEN** a functional administrator editing the schema `meldingen` in the schema edit dialog
- **WHEN** they choose `internal` on the Catalogue tab and save
- **THEN** `GET /api/schemas/{id}` returns `"classification": "internal"`
- **AND** a caseworker who could read `meldingen` objects before can still read them
- @e2e exclude {specified only; task 4.2 adds tests/e2e/schema-catalogue-metadata.spec.ts}

#### Scenario: An unknown classification is refused

- **GIVEN** an API client with write access to a schema
- **WHEN** it sends `PUT /api/schemas/{id}` with `"classification": "secret"`
- **THEN** the response is 400 and names `classification`
- **AND** the schema keeps its previous classification
- @e2e exclude {specified only; task 1.2 adds the API test for the refusal}

### Requirement: Schemas can be listed by classification

`GET /api/schemas` SHALL accept a `classification` filter and SHALL return only the
schemas with that classification that the caller may already list.

#### Scenario: A data steward lists the internal record types

- **GIVEN** three schemas classified `internal`, `open` and `confidential`, all readable by a data steward
- **WHEN** the data steward requests `GET /api/schemas?classification=internal`
- **THEN** the response lists only the schema classified `internal`
- @e2e exclude {specified only; task 4.2 adds tests/e2e/schema-catalogue-metadata.spec.ts}

### Requirement: A schema carries catalogue metadata

A schema SHALL accept a `catalogue` block with `maintainer` (organisation and
department), `contact` (name and e-mail), `sourceSystem`, `updateFrequency` (one of
`realtime`, `daily`, `weekly`, `monthly`, `yearly`, `irregular`), `documentationUrl`
and `labels`. The system MUST refuse an unknown `updateFrequency` or a malformed
e-mail with a 400 that names the field. The contact block MUST be omitted for an
anonymous caller.

#### Scenario: A catalogue reads who maintains a record type

- **GIVEN** the schema `meldingen` with a maintainer, a contact and `updateFrequency: daily`
- **WHEN** opencatalogi requests `GET /api/schemas/{id}` as a signed-in user
- **THEN** the response carries the `catalogue` block with all six fields
- @e2e exclude {specified only; task 4.2 adds tests/e2e/schema-catalogue-metadata.spec.ts}

#### Scenario: An anonymous reader does not see the contact person

- **GIVEN** a schema readable by anonymous users with a contact e-mail in its catalogue block
- **WHEN** an anonymous client requests `GET /api/schemas/{id}`
- **THEN** the response carries `classification` and `catalogue.sourceSystem`
- **AND** the response carries no `catalogue.contact`
- @e2e exclude {specified only; task 2.2 adds the API test}

### Requirement: Catalogue metadata travels with the register

A register export, the generated OpenAPI document and the JSON-LD output SHALL carry
each schema's classification and catalogue block, and an import SHALL restore them.

#### Scenario: An administrator moves a register to another instance

- **GIVEN** a register whose schemas carry classifications and catalogue blocks
- **WHEN** an administrator exports it and imports the file on another instance
- **THEN** each imported schema has the same classification and catalogue block
- @e2e exclude {specified only; task 3.2 adds the export and import test}
