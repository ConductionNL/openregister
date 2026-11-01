# mdm-merge

## ADDED Requirements

### Requirement: Parties merge through the existing merge primitive (REQ-PRM-005)

Two parties that turn out to be one person SHALL merge through the
entity-type-agnostic merge, with a declared party vocabulary naming which
properties survive, how addresses combine and how roles on objects are
carried over. The merge SHALL keep every role both parties held, on every
object, and the reversal window SHALL restore both parties and their roles.

#### Scenario: two party records become one, keeping both case histories

- **GIVEN** party A holding a role on two objects and party B holding a role on one
- **WHEN** B is merged into A
- **THEN** A holds all three roles and the merge is on the merge audit register

#### Scenario: a merged party is restored within the window

- **GIVEN** the merge above, inside the reversal window
- **WHEN** the merge is reversed
- **THEN** both parties exist again and each holds the roles it held before
- @e2e exclude {reversal path, covered by the merge unit tests}
