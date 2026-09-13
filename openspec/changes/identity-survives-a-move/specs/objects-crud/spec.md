# objects-crud

## ADDED Requirements

### Requirement: An object can move between registers and schemas without changing identity

The system SHALL let a user with `manage` on an object and `create` on the
target move the object to another register and schema in one transaction,
keeping its uuid, its generated identifiers, its audit trail, versions,
relations, files, notes, watchers, favourites and locks. The target schema
SHALL validate the data, or the move SHALL be refused with the validation
errors. The move SHALL write one `moved` audit entry naming both addresses.

#### Scenario: the number and the history come along

- **GIVEN** an object with generated identifier `2026-0042`, six audit entries and two relations
- **WHEN** it is moved to another schema declaring the same generated property
- **THEN** it keeps `2026-0042`, has seven audit entries and both relations resolve
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/object-move.spec.ts when the endpoint ships}

#### Scenario: a target that does not fit refuses the move

- **GIVEN** a target schema requiring a property the object lacks
- **WHEN** the move is requested
- **THEN** the response is 422 with the missing property and the object is unchanged
- @e2e exclude {validation, covered by MoveObject unit tests}

### Requirement: The old address keeps answering

After a move the old register, schema and id SHALL resolve to the object
for one year with `@self.movedTo` naming the new address, and relation
records carrying the old deep link SHALL be rewritten.

#### Scenario: a bookmark still opens the case

- **GIVEN** a moved object and its old URL
- **WHEN** the old URL is requested
- **THEN** the object is returned with `@self.movedTo`
- @e2e exclude {resolver, covered by unit tests}
