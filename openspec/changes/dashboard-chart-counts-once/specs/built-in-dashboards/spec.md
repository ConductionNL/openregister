# built-in-dashboards

## ADDED Requirements

### Requirement: The object charts read their schemas in one query and count each table once per panels read

The objects-by-register and objects-by-schema charts SHALL load the schemas of
all register/schema pairs they walk in one query. Within one
`GET /api/dashboard/panels` request each pair's table SHALL be counted at most
once for both charts. A pair whose count fails SHALL stay out of both charts.
The chart answers SHALL be the same as before.

#### Scenario: both object charts in one panels read
- **GIVEN** 32 register/schema tables
- **WHEN** an administrator reads `GET /api/dashboard/panels`
- **THEN** each table SHALL be counted once, not twice
- **AND** the objects-by-register and objects-by-schema panels SHALL equal their own endpoints' answers
- @e2e exclude {count statements have no screen of their own; covered by MagicStatisticsHandlerPairWalkTest and the live comparison}

#### Scenario: a table that cannot be counted
- **GIVEN** a pair whose count fails
- **WHEN** both object charts are read in one window
- **THEN** that pair SHALL be in neither chart
- @e2e exclude {covered by MagicStatisticsHandlerPairWalkTest}
