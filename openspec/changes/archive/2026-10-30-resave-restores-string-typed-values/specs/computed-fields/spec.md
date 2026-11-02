## ADDED Requirements

### Requirement: A system re-save hands a string-typed JSON value back as stored

When OpenRegister re-saves a stored row unchanged, it SHALL first restore every `type: string` value the read decoded to its stored JSON string. This covers rematerialising calculations, refreshing referencing calculations and the temporal calculation sweep.

#### Scenario: rematerialising a case with a status history
- **GIVEN** a case schema declaring `statusHistory` as `type: string` and a stored case whose history holds a JSON array
- **WHEN** `occ openregister:rematerialise-calculations dossiq case` runs
- **THEN** the case is re-saved with its history as the stored string, and the save is not refused
