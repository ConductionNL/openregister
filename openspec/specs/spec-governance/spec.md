# spec-governance Specification

## Purpose
TBD - created by archiving change sync-specs-and-archive-completed. Update Purpose after archive.
## Requirements
### Requirement: Every implemented capability has a canonical spec

Every implemented capability SHALL have a canonical spec under `openspec/specs/`,
and completed changes SHALL be archived once their code is merged. A capability
whose code is live but whose requirements exist only under `openspec/changes/`
(un-synced) or whose canonical spec undercounts the implementation is a
documentation defect to reconcile.

#### Scenario: Implemented capability is discoverable in specs

- **WHEN** a reviewer looks up a shipped capability (e.g. the credential broker)
- **THEN** its canonical requirements are present under `openspec/specs/`
- **AND** not only under an un-archived `openspec/changes/` folder

#### Scenario: Completed changes are archived

- **WHEN** a change's code is merged to the development branch
- **THEN** the change is archived rather than left indefinitely under
  `openspec/changes/`

