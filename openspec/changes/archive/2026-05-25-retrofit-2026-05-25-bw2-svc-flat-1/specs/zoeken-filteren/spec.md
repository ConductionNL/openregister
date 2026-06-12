## ADDED Requirements

### Requirement: Search-Trail Analytics Statistics Surface

The system MUST expose a search-trail analytics surface through `SearchTrailService` that
enriches raw aggregations from `SearchTrailMapper` into report-ready structures, each
accepting an optional `from`/`to` datetime window. `getPopularSearchTerms()` MUST return
the most-used search terms annotated with each term's share-of-total percentage and an
effectiveness rating derived from its average result count. `getRegisterSchemaStatistics()`
MUST return per-register/schema search counts annotated with percentage and a performance
rating, sorted by percentage descending. `getSearchActivity()` MUST return search counts
bucketed by a time interval (e.g. day) together with computed activity insights.
`getSearchStatistics()` MUST return aggregate totals enriched with searches-with-results /
without-results splits, a success rate, unique-term and unique-user counts, and
per-session averages. `getUserAgentStatistics()` MUST return top user agents with parsed
browser info plus an aggregated browser distribution.

#### Scenario: Popular terms include percentage and effectiveness
- **GIVEN** persisted search trails within the requested window
- **WHEN** `getPopularSearchTerms()` is called
- **THEN** each returned term MUST include its share-of-total percentage and an effectiveness rating

#### Scenario: Aggregate statistics include success rate and uniqueness counts
- **GIVEN** persisted search trails
- **WHEN** `getSearchStatistics()` is called
- **THEN** the result MUST include searches-with-results, searches-without-results, a success rate, unique-term and unique-user counts, and per-session averages

#### Scenario: User-agent statistics aggregate by browser
- **GIVEN** persisted search trails carrying user-agent strings
- **WHEN** `getUserAgentStatistics()` is called
- **THEN** each user agent MUST be annotated with parsed browser info
- **AND** the result MUST include an aggregated browser distribution
