# Spec: zoeken-filteren (delta)

## MODIFIED Requirements

### Requirement: Field-level filtering with comparison operators

`isnull` becomes a real operator, and the requirement now names the mechanism that
actually implements operators rather than a method nothing calls.

The operator vocabulary is `MagicSearchHandler::COMPARISON_OPERATORS`, and it is
exhaustive: `gte`, `lte`, `gt`, `lt`, `in`, `notIn`, `ne`, `isnull`. A suffix outside that
list contributes no condition and is silently ignored, so an operator MUST be added there
AND in each of the four condition builders — the QueryBuilder and raw-SQL paths, for
object fields and for `@self` metadata.

`isnull` MUST be read with boolean coercion, because a query string delivers only strings.
A strict `=== true` comparison MUST NOT be used.

#### Scenario: The null-check operator filters
@e2e exclude query-layer operator with no browser surface — both halves and every spelling are pinned by tests/Unit/Db/MagicSearchHandlerIsNullOperatorTest, and the HTTP behaviour was measured on a live instance (11 of 13 rows for true, 2 for false)

- **WHEN** the user filters with `?assignee_isnull=true`
- **THEN** only objects with no `assignee` value MUST be returned
- **AND** `?assignee_isnull=false` MUST return exactly the complement
- **AND** the two result sets together MUST be the unfiltered set

#### Scenario: An operator bag carrying only isnull is not a bare IN list
@e2e exclude query-layer operator with no browser surface — pinned by the same unit test

- **WHEN** a filter resolves to `assignee => ['isnull' => 'true']`
- **THEN** it MUST emit a null check
- **AND** it MUST NOT be treated as the historical bare-list `IN ('true')`, which matches
  nothing and reads as a correct empty result

#### Scenario: Both filter paths agree
@e2e exclude query-layer operator with no browser surface — pinned by the same unit test

- **WHEN** the same `isnull` filter runs through the QueryBuilder path and the raw-SQL
  UNION path
- **THEN** both MUST emit the same predicate

## REMOVED Requirements

### Requirement: Legacy ordering parameter

**Reason**: `?ordering=-aanmaakdatum` was only ever implemented in
`SearchQueryHandler::cleanQuery()`, which no production code called. Over HTTP `ordering`
is read as a filter on a property no schema declares, so it adds `1 = 0` and returns zero
rows. Measured on a live instance: `?ordering=title` returned 0 of 13.

**Migration**: Use `?_order[<field>]=ASC|DESC`, which is the mechanism the search actually
implements and which is verified working on the same instance.
