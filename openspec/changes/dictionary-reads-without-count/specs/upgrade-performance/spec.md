# upgrade-performance

## ADDED Requirements

### Requirement: The search-term dictionary reads its rows without a total

Loading the administered search-term dictionary (the synonym and stopword
schemes and their concepts) SHALL read rows only and SHALL NOT run a count
statement for any of its reads. Its reads SHALL stay unscoped (`_rbac` and
`_multitenancy` off) and capped by `_limit`, and the dictionary built from
them SHALL be the same as before.

#### Scenario: a cross-table search loads the dictionary
- **GIVEN** a vocabulary register with a synonym scheme and a stopword scheme
- **WHEN** a caller searches `GET /api/objects?_search=e`
- **THEN** the dictionary load SHALL issue four row reads and no count
- **AND** the expansion of the search term SHALL be unchanged
- @e2e exclude {the count has no screen of its own; covered by SearchDictionaryProviderTest::testTheDictionaryReadsWithoutCounting and the live query count}
