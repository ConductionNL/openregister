# agent-tool-governance Specification (delta)

## ADDED Requirements

### Requirement: REQ-AGLIM-001 An agent is held to its limits on every path

An agent SHALL only call the tools and read the registers and views its grant lists, in chat and over MCP alike, and an administrator SHALL set that grant on an agents screen.

#### Scenario: an MCP call outside the grant is refused

- **GIVEN** an agent allowed only the tool `objects.search`
- **WHEN** the agent calls `objects.delete` over MCP
- **THEN** the call is refused with a message naming `objects.delete`
- @e2e exclude {specified only; task 1 adds the test}

#### Scenario: an administrator narrows an agent

- **GIVEN** the agents screen
- **WHEN** an administrator removes a register from an agent
- **THEN** the agent no longer finds objects of that register
- @e2e exclude {specified only; task 1 adds the test}
