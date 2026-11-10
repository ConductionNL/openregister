# mdm-merge

## ADDED Requirements

### Requirement: A merge relinks every reference to the losing record

Executing a merge SHALL move every reference to a losing record, found through
the relation index across the instance, onto the survivor: scalar references,
array references without creating a duplicate entry, and relation rows. Each
move SHALL be written through the save path as the merging user. A reference
the user may not update SHALL be left and reported. Each move SHALL be kept in
the merge snapshot, and reversing the merge SHALL restore every moved
reference whose field still holds the survivor.

#### Scenario: a steward merges two application records

- **GIVEN** a data steward who may update the catalogue, modules "Zaaksysteem" and "Zaaksysteem (kopie)", and suite "Basis" whose `applications` list holds both
- **WHEN** the steward merges "Zaaksysteem (kopie)" into "Zaaksysteem" through `POST /api/objects/merge/execute`
- **THEN** suite "Basis" lists "Zaaksysteem" once, and every connection that pointed at the copy points at "Zaaksysteem"
- **AND** the merge result lists the moved references by schema and count
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/merge-relinks-references.spec.ts}

#### Scenario: reversing the merge puts the references back

- **GIVEN** the merge above, still inside the reversal window
- **WHEN** the steward reverses it through `POST /api/objects/merge/{id}/reverse`
- **THEN** suite "Basis" lists both modules again, and each connection points where it pointed before
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/merge-relinks-references.spec.ts}

### Requirement: The duplicates page opens on a register and schema from a link

The page `/duplicates` SHALL accept `register` and `schema` query parameters,
as ids or slugs, and SHALL open with that pair selected and its candidate
pairs loaded.

#### Scenario: an app sends the steward to the duplicates of one schema

- **GIVEN** a steward on stackiq's Applications page
- **WHEN** the steward chooses Find duplicates, which opens `/apps/openregister/duplicates?register=catalogus&schema=module`
- **THEN** the duplicates page shows the candidate pairs for `module` without asking for a register or schema
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/merge-relinks-references.spec.ts}
