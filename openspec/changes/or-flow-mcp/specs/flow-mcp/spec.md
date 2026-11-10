## ADDED Requirements

### Requirement: Flows are runnable by an agent over MCP (REQ-FMCP-001)

OpenRegister SHALL offer built-in MCP tools that let an agent run a flow and
read a run's status. `openregister.runFlow` SHALL QUEUE a run (not execute it
inline) and return the run uuid; `openregister.flowRunStatus` SHALL return a
run's status, log and items by uuid, or a not-found marker rather than throwing.

Every tool id SHALL be namespaced with the app id.

#### Scenario: runFlow queues and returns a uuid

- **GIVEN** a flow id
- **WHEN** `openregister.runFlow` is invoked
- **THEN** a run is queued and its uuid is returned

#### Scenario: A missing flow id is refused

- **WHEN** `openregister.runFlow` is invoked with no flowId
- **THEN** it throws

#### Scenario: Status of an unknown run is not-found, not an error

- **WHEN** `openregister.flowRunStatus` is invoked with an unknown uuid
- **THEN** it returns `found: false`, not an exception

### Requirement: The MCP Client step is not built (REQ-FMCP-002)

This change SHALL NOT add a flow node that calls an external MCP server. The
agentic case is covered by an agent node reaching MCP; a deterministic external
call is better served by a generic HTTP step, a separate change.

@e2e exclude MCP tool behaviour is backend-only — covered by PHPUnit and a live
catalogue + invoke check
