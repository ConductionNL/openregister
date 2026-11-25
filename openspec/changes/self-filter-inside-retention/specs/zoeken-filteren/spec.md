# Delta: zoeken-filteren

## ADDED Requirements

### Requirement: A @self filter can name a field inside the archival metadata
A `@self` filter key MUST be able to name a field inside the `retention` or `tmlo` metadata block with a dotted key (`retention.archiefactiedatum`). It MUST take the operator vocabulary of a filter inside an object property and MUST be applied identically on the QueryBuilder path and the raw UNION path. A dotted key into any other metadata column MUST still be refused as an unknown metadata field. (REQ-SFR-001)

#### Scenario: Dossiers past their archive action date
- **GIVEN** objects whose `retention.archiefactiedatum` is 2026-03-31 and 2027-03-31
- **WHEN** the caller filters with `@self[retention.archiefactiedatum][lt]=2026-11-24`
- **THEN** only the object dated 2026-03-31 MUST be returned

#### Scenario: Retention not resolved
- **GIVEN** an object whose `retention` holds no `archiefnominatie`
- **WHEN** the caller filters with `@self[retention.archiefnominatie][isnull]=true`
- **THEN** that object MUST be returned

#### Scenario: A path into an access column is refused
- **WHEN** the caller filters with `@self[authorization.read]=x`
- **THEN** the filter MUST be refused as an unknown metadata field, never evaluated
