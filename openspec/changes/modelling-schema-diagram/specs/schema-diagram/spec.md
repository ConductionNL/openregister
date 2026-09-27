# schema-diagram

## ADDED Requirements

### Requirement: A register's model is available as nodes and edges

The system SHALL answer `GET /api/registers/{id}/model` with the register's schemas
as nodes and the links declared by `$ref`, `items.$ref` and `inversedBy` as edges,
including external nodes for schemas in other registers and dangling edges for refs
that do not resolve. It MUST apply the same read checks as `registers#schemas` and
MUST NOT read objects.

#### Scenario: A functional administrator reads the model of a register

- **GIVEN** a register with schemas `zaak`, `document` and `contact`, where `zaak.documenten` is an array ref to `document` with `inversedBy: zaak`
- **WHEN** a functional administrator requests `GET /api/registers/{id}/model`
- **THEN** the response has three nodes
- **AND** one edge from `zaak` to `document` marked many, with inverse property `zaak`
- @e2e exclude {specified only; task 1.2 adds the API test}

#### Scenario: A broken link shows instead of disappearing

- **GIVEN** a property whose `$ref` names a schema that was deleted
- **WHEN** the model is requested
- **THEN** the edge is returned and marked dangling
- @e2e exclude {specified only; task 1.1 adds the service test}

#### Scenario: A schema the caller may not read stays nameless

- **GIVEN** a ref to a schema in a register the caller may not read
- **WHEN** the caller requests the model
- **THEN** the external node carries no title and no properties
- @e2e exclude {specified only; task 1.2 adds the API test}

### Requirement: The register page draws the model

The register detail page SHALL offer a Diagram view that draws the model, opens a
schema when its node is clicked, keeps a user's moved nodes where they put them, and
SHALL render the same nodes and edges as a keyboard-reachable table.

#### Scenario: An administrator opens a schema from the diagram

- **GIVEN** an administrator on the register detail page of `zaken`
- **WHEN** they open the Diagram view and click the `document` box
- **THEN** the schema page of `document` opens
- @e2e exclude {specified only; task 2.1 adds tests/e2e/schema-diagram.spec.ts}

#### Scenario: The diagram downloads as SVG

- **GIVEN** the Diagram view of a register
- **WHEN** the administrator chooses download
- **THEN** the browser saves an SVG file of the drawn model
- @e2e exclude {specified only; task 2.4 adds the download check to tests/e2e/schema-diagram.spec.ts}
