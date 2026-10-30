## ADDED Requirements

### Requirement: A materialised calculation may be a fallback an app overrides

A materialised calculation MAY declare `fallback: true`. Rematerialising SHALL re-save a row with such a calculation, and SHALL NOT count a value the save left as stored as a failure. A calculation without the declaration whose value the save did not write SHALL still be a failure.

#### Scenario: a case deadline that follows its term
- **GIVEN** a case whose deadline calculation is a fallback, and whose statutory term puts the deadline back on save
- **WHEN** `occ openregister:rematerialise-calculations dossiq case` runs
- **THEN** the case counts as touched, not failed
