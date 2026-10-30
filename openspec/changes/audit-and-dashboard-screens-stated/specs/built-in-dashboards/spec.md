# built-in-dashboards (delta)

## ADDED Requirements

### Requirement: The app opens on a dashboard of registers, counts and activity (REQ-ADS-002)

Opening OpenRegister SHALL show a dashboard at the app's root route. It SHALL show the number of objects with the number of registers they span, the number of registers, the number of schemas and the number of searches with their success rate, and a chart of object events (create, update, delete) over a date range the user can change. The counts SHALL cover only what the user may read. A refresh action SHALL reload the figures, and a failed figure SHALL show as unavailable without blanking the rest.

#### Scenario: A user opens the app
- **GIVEN** a user who may read two registers holding 120 objects
- **WHEN** the user opens `/apps/openregister/`
- **THEN** the dashboard shows 120 objects across 2 registers, the register and schema counts and the activity chart
- @e2e exclude {task 2.2 adds tests/e2e/ci/dashboard.spec.ts}

#### Scenario: The user changes the date range
- **GIVEN** the dashboard open
- **WHEN** the user picks the last 30 days
- **THEN** the activity chart shows events of the last 30 days
- @e2e exclude {task 2.2}
