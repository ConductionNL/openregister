# zoeken-filteren

## ADDED Requirements

### Requirement: Object search ignores accents where the database supports it

When accent-insensitive search is enabled, object search SHALL match a term
regardless of accents as well as case, on PostgreSQL through the `unaccent`
extension and on MariaDB and MySQL through an accent-insensitive collation.
This SHALL apply to the plain search, to every leaf of a boolean search, to
fuzzy matching and to the search condition behind facet counts, so a facet
count equals the number of results it stands for.

#### Scenario: a reader finds an accented name without typing the accent

- **GIVEN** a decision object titled "Reünie oud-raadsleden" and a location object named "Café de Flore"
- **WHEN** a council clerk calls `GET /api/objects/{register}/{schema}?_search=reunie` and `?_search=cafe`
- **THEN** the first response contains the decision and the second contains the location
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/search-accents.spec.ts}

#### Scenario: the facet count matches the results

- **GIVEN** three objects with `Café` in their name and a facet on their status
- **WHEN** the clerk searches `cafe` with that facet
- **THEN** the facet buckets add up to the three results returned
- @e2e exclude {specified only; task 1.3 adds the integration test, task 3.1 adds tests/e2e/ci/search-accents.spec.ts}

### Requirement: Accent folding is visible to administrators and can be bypassed

The search settings SHALL report whether accent-insensitive search is enabled
and available, with the reason when it is not. It SHALL be enabled by default
where available. Enabling it where it is unavailable SHALL be refused naming
the reason. A caller SHALL be able to request an exact comparison for one
search with `_accents=exact`.

#### Scenario: an administrator sees that the extension is missing

- **GIVEN** a PostgreSQL instance where the `unaccent` extension could not be created
- **WHEN** an administrator calls `GET /api/settings/search-backend`
- **THEN** `accentInsensitive.available` is false and `reason` names the extension
- **AND** `PATCH /api/settings/search-backend` with `accentInsensitive: true` answers 400 naming the same reason
- @e2e exclude {specified only; task 2.1 adds SettingsControllerTest, task 3.1 adds tests/e2e/ci/search-accents.spec.ts}

#### Scenario: an integration asks for an exact match

- **GIVEN** accent-insensitive search enabled and an object named "Café de Flore"
- **WHEN** an integration calls `GET /api/objects/{register}/{schema}?_search=cafe&_accents=exact`
- **THEN** the object is not in the results
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/search-accents.spec.ts}
