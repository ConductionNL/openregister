# zoeken-filteren

## ADDED Requirements

### Requirement: A like filter matches a substring ignoring case

The system SHALL support a `like` operator on property filters and on `@self`
metadata filters, written `?title[like]=foo` or `?title_like=foo`. It SHALL
return the objects whose value contains the term anywhere, ignoring case, on
every condition builder (QueryBuilder path and raw UNION path) and on the DBAL
object source listing. The column SHALL be compared as text, so the operator
also works on numeric, date and JSON columns. A list of terms SHALL match any
of them, and an empty term SHALL add no condition.

#### Scenario: a table header filter finds a client by part of its name

- **GIVEN** a client schema with objects named "Gemeente Demo" and "Meridiaan Advies B.V."
- **WHEN** the client calls `GET /api/objects/{register}/client?name[like]=demo`
- **THEN** the result holds "Gemeente Demo" and not "Meridiaan Advies B.V."
- @e2e exclude {query-layer operator; covered by MagicSearchHandlerLikeOperatorTest over both builders and by LikeOperatorTest against a real SQLite table; the header filter UI lives in nextcloud-vue}

#### Scenario: the suffix spelling means the same filter

- **WHEN** the client calls `?name_like=demo`
- **THEN** `buildSearchQuery()` rebuilds it into `name => ['like' => 'demo']` and the result equals `?name[like]=demo`
- @e2e exclude {query-layer normalisation, no browser surface}

#### Scenario: a cleared filter shows everything

- **WHEN** the client calls `?name[like]=`
- **THEN** no condition is added for `name`
- @e2e exclude {covered by MagicSearchHandlerLikeOperatorTest::testEmptyTermAddsNoCondition}

### Requirement: Like matches percent, underscore and backslash literally

The system SHALL escape `%`, `_` and `\` in a `like` term so they match
themselves, and SHALL pass the pattern as a bound parameter on every path that
has a query builder. The raw UNION path, which joins SQL text, SHALL pass it
through the platform's `quote()`.

#### Scenario: a percent sign is not a wildcard

- **GIVEN** objects named "100% zeker" and "1000 zeker"
- **WHEN** the client filters `?name[like]=0%`
- **THEN** only "100% zeker" is returned
- @e2e exclude {covered by LikeOperatorTest on SQLite and verified on PostgreSQL with a prepared statement}

#### Scenario: an underscore is not a wildcard

- **GIVEN** objects named "a_b" and "axb"
- **WHEN** the client filters `?name[like]=a_b`
- **THEN** only "a_b" is returned
- @e2e exclude {covered by LikeOperatorTest and DbalObjectSourceProviderTest on SQLite}
