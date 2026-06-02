## ADDED Requirements

### Requirement: The endpoint dispatcher MUST route execution by target type

`EndpointService` MUST act as the runtime dispatcher behind the dynamic endpoint surface, routing an endpoint to a target-type-specific handler (`view`, `agent`, `webhook`, `register`, `schema`) and returning a uniform result shape `{success, statusCode, response, error?}`. A test entry point MUST allow executing an endpoint with supplied test data without a real inbound request.

#### Scenario: Route by target type

- **GIVEN** an `Endpoint` with a `targetType`
- **WHEN** `executeEndpoint()` runs
- **THEN** it MUST dispatch to the matching handler for `view`, `agent`, `webhook`, `register`, or `schema`
- **AND** an unknown target type MUST return `{success: false, statusCode: 400, error: "Unknown target type: ..."}`

#### Scenario: Test an endpoint with supplied data

- **GIVEN** an endpoint and an optional `testData` array
- **WHEN** `testEndpoint()` runs
- **THEN** it MUST first check access via `canExecuteEndpoint()` and return `statusCode: 403` when denied
- **AND** on success it MUST build a request from the endpoint method/path plus test data, dispatch via `executeEndpoint()`, log the call, and return the handler result
- **AND** any thrown exception MUST be caught, logged, and surfaced as `{success: false, statusCode: 500, error: <message>}`

#### Scenario: Agent endpoint executes an AI agent

- **GIVEN** an endpoint with `targetType: agent` and a `targetId` resolving to an agent
- **WHEN** `executeAgentEndpoint()` runs
- **THEN** a missing agent MUST return `statusCode: 404` and an empty message MUST return `statusCode: 400`
- **AND** on success it MUST resolve the agent's configured tools through `ToolRegistry` and execute the agent with the request message

### Requirement: Endpoint execution MUST enforce group access and log every call

Endpoint execution MUST be gated by group-based access control and MUST persist an execution log entry for audit and debugging.

#### Scenario: Group-based access control

- **GIVEN** an endpoint with a configured `groups` list
- **WHEN** `canExecuteEndpoint()` evaluates the current user
- **THEN** an unauthenticated request MUST be allowed only when the endpoint declares no groups (public)
- **AND** a member of the `admin` group MUST always be allowed
- **AND** an authenticated user MUST be allowed when the endpoint declares no groups, or when the user belongs to at least one of the endpoint's groups; otherwise access MUST be denied

#### Scenario: Execution call is logged with a TTL

- **GIVEN** any endpoint execution
- **WHEN** `logEndpointCall()` runs
- **THEN** it MUST persist an `EndpointLog` with a generated uuid, the endpoint id, the acting user (when present), the request and response payloads, the status code and message, a creation timestamp, and an expiry one week out
- **AND** the entry size MUST be computed before insert
