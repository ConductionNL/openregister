# zoeken-filteren

## ADDED Requirements

### Requirement: The object query filters on rows of a related schema

The object query SHALL accept `_related[<schema>][<fk>]` blocks holding one
or more field conditions with the existing operators, and SHALL return the
objects for which at least one row of `<schema>` whose `<fk>` references the
object satisfies every condition in the block. Two blocks SHALL be
independent existence tests. The related schema's read predicate SHALL be
applied inside the subquery. The clause SHALL be one `EXISTS` subquery per
block on both supported databases.

#### Scenario: a case is found by a property row's value

- **GIVEN** a `case` object with a `caseProperty` row (`case` = the object, `propertyDefinition` = P, `value` = 120) and another case whose row for P has value 80
- **WHEN** the case list is queried with `_related[caseProperty][case][propertyDefinition]=P` and `_related[caseProperty][case][value][gte]=100`
- **THEN** only the first case is returned
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/ci/query-related-schema-rows.spec.ts when the parser ships}

#### Scenario: an unreadable related row cannot be probed

- **GIVEN** a user with no read scope on `caseProperty`
- **WHEN** they query cases with a `_related[caseProperty]` block
- **THEN** no case is returned by that block, and no error reveals the row's existence
- @e2e exclude {the predicate injection is covered by unit tests on both query builders}

### Requirement: Facets over a related schema's field

The facet request SHALL accept the same `_related` addressing so that an
index page can show the value distribution of a related field across the
matching objects, bounded by the facet limit.

#### Scenario: chips from property definitions

- **GIVEN** cases whose `caseProperty` rows for P hold values `A`, `A` and `B`
- **WHEN** facets are requested for `_related[caseProperty][case][value]`
- **THEN** the facet lists `A` with count 2 and `B` with count 1
- @e2e exclude {facet counts are covered by unit tests on the facet builder}

### Requirement: The search backend translates or falls back

With a search backend configured the `_related` block SHALL be translated to
the backend's join query when the related schema is mirrored, and SHALL
otherwise fall back to the database path for that request and name the path
taken in the response.

#### Scenario: an unmirrored related schema falls back

- **GIVEN** a Solr backend and a related schema that is not mirrored
- **WHEN** a query with a `_related` block on it runs
- **THEN** the results come from the database path and the response names it
- @e2e exclude {backend selection is a fixture condition covered by unit tests}
