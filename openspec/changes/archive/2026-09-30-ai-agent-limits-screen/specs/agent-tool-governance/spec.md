# agent-tool-governance Specification (delta)

## ADDED Requirements

### Requirement: An agent is held to its tool grant on every path

An agent SHALL only call the tools its grant lists, in chat and over MCP alike. An MCP client names the agent it acts as with the `agent` parameter of `initialize` (or the `X-OpenRegister-Agent` header); from then on `tools/list` shows only the granted tools and `tools/call` refuses any other tool, or an argument an argument-scoped grant does not allow, before the tool runs. The grant is read on every call, so narrowing an agent narrows its open sessions. An agent that does not exist, is inactive or is not available to the signed-in user SHALL be refused at `initialize`, never ignored. A session that names no agent keeps the signed-in user's rights. The agent's owner SHALL set the grant on the agents screen.

#### Scenario: an MCP call outside the grant is refused

- **GIVEN** an agent allowed only the tool `openregister.objects.search`, and an MCP session initialised with that agent
- **WHEN** the session calls `openregister.objects.delete`
- **THEN** the call is refused with a message naming `openregister.objects.delete`, and the tool does not run
- @e2e exclude {covered by tests/Unit/Controller/McpAgentLimitsTest.php, which drives the real MCP controller; there is no browser surface}

#### Scenario: an unavailable agent is refused at initialize

- **GIVEN** an agent that is unknown, inactive or private to another user
- **WHEN** an MCP client initialises with that agent
- **THEN** initialize is refused and no session is created
- @e2e exclude {covered by tests/Unit/Controller/McpAgentLimitsTest.php; there is no browser surface}

#### Scenario: the owner narrows an agent on the agents screen

- **GIVEN** the agents screen and an agent the signed-in user owns
- **WHEN** the owner removes a tool and saves
- **THEN** the agent's grant no longer lists that tool, and an MCP session of that agent is refused it on its next call
- @e2e exclude {covered by src/views/agents/AgentsIndex.spec.js (save) and McpAgentLimitsTest::testANarrowedGrantAppliesToAnOpenSession (refusal)}

