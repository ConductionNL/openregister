## ADDED Requirements

### Requirement: The page and the endpoint report the same setup status

The setup status document an administrator's page carries as `setup_status`
SHALL be identical to the answer of `GET /api/setup/status` for the same
configuration, so the wizard behaves the same whether it read the status from
the page or from the endpoint.

#### Scenario: Example data was declined

- **GIVEN** an administrator declined the example data
- **WHEN** they open an OpenRegister page
- **THEN** `setup_status.steps["demo-data"].done` is true, as `GET /api/setup/status` answers
