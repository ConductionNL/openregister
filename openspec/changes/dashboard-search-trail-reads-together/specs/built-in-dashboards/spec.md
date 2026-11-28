# built-in-dashboards

## ADDED Requirements

### Requirement: The dashboard's search-trail reads go out together

The OR Dashboard SHALL request the search-trail statistics and the popular search
terms at the same time, not one after the other. When either read fails, the
search-trail widgets SHALL show the empty defaults, set only after both reads have
settled.

#### Scenario: a full load of the OR Dashboard
- **GIVEN** a user opens the OR Dashboard
- **WHEN** the page loads its search-trail widgets
- **THEN** `GET /api/search-trails/popular-terms` SHALL be sent before `GET /api/search-trails/statistics` has answered
- @e2e exclude {request order has no screen of its own; covered by DashboardIndex.methods.spec.js and the live request timeline}

#### Scenario: the statistics read fails
- **GIVEN** the statistics read fails and the popular-terms read is still running
- **WHEN** the popular-terms read answers
- **THEN** the widgets SHALL show the empty search-trail defaults
- @e2e exclude {covered by DashboardIndex.methods.spec.js}
