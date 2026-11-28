# built-in-dashboards

## ADDED Requirements

### Requirement: The dashboard reads its chart and statistics panels in one request

`GET /api/dashboard/panels` SHALL answer the audit-trail action chart, the
objects-by-register, objects-by-schema and objects-by-size charts, the audit
trail statistics, the action distribution and the most active objects in one
response, keyed by panel, each with the HTTP status and body that panel's own
endpoint answers for the same parameters. A failing panel SHALL answer its own
error status and SHALL NOT fail the other panels. A non-admin caller SHALL be
refused with 403 and nothing SHALL be computed. On load, the OR Dashboard SHALL
read its panels through this one request.

#### Scenario: an administrator opens the OR Dashboard
- **GIVEN** an administrator opens the OR Dashboard
- **WHEN** the page loads its charts and statistics
- **THEN** it SHALL send one `GET /api/dashboard/panels` and no per-panel chart or statistics read
- **AND** every chart and statistics widget SHALL show the same data as from its own endpoint
- @e2e exclude {request count has no screen of its own; covered by dashboard.preload.spec.js, dashboard.watchers.spec.js and the live request timeline}

#### Scenario: one panel fails
- **GIVEN** the objects-by-schema chart fails on the server
- **WHEN** the dashboard reads its panels
- **THEN** that panel SHALL answer 500 with its error
- **AND** the other six panels SHALL answer 200 with their data
- @e2e exclude {covered by DashboardControllerTest::testGetPanelsKeepsAFailingPanelToItself}

#### Scenario: a non-admin asks for the panels
- **GIVEN** a signed-in user who is not an administrator
- **WHEN** they request `GET /api/dashboard/panels`
- **THEN** the answer SHALL be 403 and no panel SHALL be computed
- @e2e exclude {covered by DashboardControllerTest::testAnalyticsEndpointsRejectNonAdmin}
