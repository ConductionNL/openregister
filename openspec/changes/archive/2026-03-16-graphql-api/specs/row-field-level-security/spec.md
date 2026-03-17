## MODIFIED Requirements

### Requirement: RLS rules MUST apply to all access methods
Row-level security MUST be enforced on REST API, GraphQL, search results, exports, and the UI.

#### Scenario: RLS in search results
- **WHEN** user `jan` (sociale-zaken) searches for meldingen
- **THEN** only meldingen where `afdeling: "sociale-zaken"` MUST appear in results
- **AND** facet counts MUST reflect only the accessible objects

#### Scenario: RLS in data export
- **WHEN** user `jan` exports meldingen to CSV
- **THEN** the export MUST only contain objects passing the RLS rules
- **AND** the export MUST NOT include objects from other departments

#### Scenario: RLS in GraphQL queries
- **WHEN** user `jan` (sociale-zaken) queries `meldingen { title afdeling }` via GraphQL
- **THEN** only meldingen where `afdeling: "sociale-zaken"` MUST be returned
- **AND** the RLS filter MUST be applied at the MagicRbacHandler query level before GraphQL resolvers execute
- **AND** facets requested in the GraphQL connection MUST reflect only RLS-accessible objects

#### Scenario: RLS in GraphQL mutations
- **WHEN** user `pieter` (ruimtelijke-ordening) attempts `updateMelding(id: "melding-1")` on a melding with `afdeling: "sociale-zaken"`
- **THEN** the mutation MUST be rejected with `extensions.code: "FORBIDDEN"`
- **AND** the RLS denial MUST be logged to the audit trail

#### Scenario: RLS in GraphQL nested resolution
- **WHEN** user `jan` queries `dossier { meldingen { title } }` and some nested meldingen fail RLS
- **THEN** only RLS-passing meldingen MUST appear in the nested array
- **AND** no error MUST be raised for filtered-out items (silently excluded, matching list behavior)

### Requirement: Schemas MUST support field-level security
Individual properties MUST be configurable with visibility rules based on user roles.

#### Scenario: Hide sensitive field from basic users
- **WHEN** schema `inwoners` has property `bsn` visible only to group `bsn-geautoriseerd`
- **AND** user `medewerker-1` is NOT in `bsn-geautoriseerd`
- **THEN** the `bsn` field MUST be omitted from REST responses
- **AND** in GraphQL, `bsn` MUST resolve to `null` with a partial error at path `["inwoner", "bsn"]` with `extensions.code: "FIELD_FORBIDDEN"`

#### Scenario: Show sensitive field to authorized users
- **WHEN** user `specialist` IS in `bsn-geautoriseerd`
- **THEN** the `bsn` field MUST be included in both REST and GraphQL responses

#### Scenario: Field-level security in list views
- **WHEN** user `medewerker-1` cannot read `bsn`
- **THEN** the `bsn` column MUST NOT appear in REST list responses
- **AND** in GraphQL list queries, `bsn` MUST resolve to `null` on each edge node with partial errors

#### Scenario: Field-level write protection in GraphQL mutations
- **WHEN** user `medewerker-1` is NOT in group `redacteuren`
- **AND** they attempt `updateInwoner(id: "...", input: { interneAantekening: "text" })`
- **THEN** the mutation MUST be rejected with `extensions.code: "FIELD_FORBIDDEN"`
- **AND** `PropertyRbacHandler::getUnauthorizedProperties()` MUST be called to determine the blocked fields
