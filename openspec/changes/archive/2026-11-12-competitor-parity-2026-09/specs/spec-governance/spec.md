# spec-governance

## ADDED Requirements

### Requirement: The competitor parity programme is indexed in one change

The openregister half of the dossiq competitor parity programme SHALL be
indexed in `openspec/changes/competitor-parity-2026-09/proposal.md`: every
covered row with its verdict, every change opened with its rows, size,
dependencies and consumers, and the build order. A change opened for the
programme after this umbrella SHALL be added to the index in the same PR.

#### Scenario: a reader finds the change for a ledger row

- **GIVEN** ledger row 13.18
- **WHEN** a reader opens the umbrella's index
- **THEN** the row resolves to `object-watchers` with its size and consumers
- @e2e exclude {documentation index, checked by review}
