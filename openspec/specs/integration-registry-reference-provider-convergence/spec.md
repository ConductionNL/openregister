# integration-registry-reference-provider-convergence Specification

## Purpose
TBD - created by archiving change integration-registry-reference-provider-convergence. Update Purpose after archive.
## Requirements
### Requirement: REQ-CONV-001 — The change SHALL deliver a responsibilities matrix

The change SHALL deliver a written responsibilities matrix that classifies every responsibility
of the OpenRegister integration registry surface (`IntegrationProvider` methods,
`IntegrationRegistry`, `AbstractIntegrationProvider`, `ExternalIntegrationRouter`) into exactly
one of two buckets: *pure READ/RENDER* (a candidate for delegation to `IReferenceProvider`) or
*genuinely VALUE-ADDING beyond a read-only reference* (CRUD write verbs, link tables,
`(register, schema, objectId)` scoping). The matrix SHALL be committed to the OpenRegister
docs.

#### Scenario: Matrix classifies read vs value-add responsibilities
- **GIVEN** the investigation decision record committed under `docs/development-notes/`
- **WHEN** a reviewer opens the responsibilities matrix
- **THEN** each of `list`, `get`, `create`, `update`, `delete`, the metadata methods, the link
  tables, and the `(register, schema, objectId)` scoping SHALL appear in the matrix assigned to
  exactly one bucket (READ/RENDER or VALUE-ADD)
- **AND** the matrix SHALL state, for each READ/RENDER row, whether `IReferenceProvider` can
  cover it

#### Scenario: Matrix is grounded in the actual code
- **GIVEN** the responsibilities matrix
- **WHEN** a reviewer cross-checks it against `lib/Service/Integration/IntegrationProvider.php`
  and the 22 built-in providers
- **THEN** the matrix SHALL reference the actual contract methods and SHALL note that OR already
  ships an `IReferenceProvider` (`OCA\OpenRegister\Reference\ObjectReferenceProvider`)

### Requirement: REQ-CONV-002 — The change SHALL deliver a go/no-go recommendation

The change SHALL deliver a single explicit recommendation chosen from {converge,
partial-converge, keep-separate-but-align}, with rationale. When the recommendation entails any
follow-up convergence work, the change SHALL include a phased follow-up plan and an enumerated
risk list.

#### Scenario: Recommendation is explicit and singular
- **GIVEN** the decision record
- **WHEN** a reviewer reads the Recommendation section
- **THEN** exactly one of {converge, partial-converge, keep-separate-but-align} SHALL be stated
  as the headline recommendation
- **AND** the rationale SHALL reference the responsibilities matrix and the ADR-041 boundary

#### Scenario: Phased plan and risks accompany a converging recommendation
- **GIVEN** a recommendation of `converge` or `partial-converge`
- **WHEN** a reviewer reads the follow-up section
- **THEN** the record SHALL list ordered phases for the follow-up implementation
- **AND** the record SHALL enumerate the risks of executing them

### Requirement: REQ-CONV-003 — The change SHALL enumerate the migration blast radius

The change SHALL enumerate the migration blast radius of any convergence, naming at minimum:
the manifest `referenceType` markers, the 22 built-in providers, the frontend single-entity
widgets / `useIntegrationRegistry` consumers, and the ADR-019 / ADR-036 surface that depends on
the registry contract.

#### Scenario: Blast radius names the load-bearing dependents
- **GIVEN** the decision record
- **WHEN** a reviewer reads the blast-radius section
- **THEN** it SHALL name the manifest `referenceType` markers, the count of built-in providers,
  the frontend single-entity widget surface, and ADR-019 + ADR-036
- **AND** for each named dependent it SHALL state whether a convergence would break, preserve,
  or be transparent to it

### Requirement: REQ-CONV-004 — The change SHALL NOT modify production registry code

As a spike, the change SHALL deliver only documentation and spec artifacts. The change SHALL NOT
modify any file under `lib/Service/Integration/`, `lib/Reference/`, `lib/AppInfo/Application.php`,
`appinfo/routes.php`, or any frontend integration code. A read-only PoC snippet embedded in the
decision record is permitted, but no production wiring SHALL be added.

#### Scenario: No production code is touched by the change
- **GIVEN** the change's git diff against its base
- **WHEN** a reviewer inspects the changed files
- **THEN** the only changed files SHALL be under `openspec/` and `docs/`
- **AND** no file under `lib/` or the frontend `src/` SHALL be modified

