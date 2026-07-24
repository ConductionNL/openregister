## ADDED Requirements

### Requirement: Apps announce MCP providers with a listener (REQ-MCP-D-001)

An app SHALL contribute MCP tool providers by listening for
`RegisterMcpToolProvidersEvent`, the same pattern used for flow nodes and by
core's workflow engine for operations.

The event SHALL be dispatched once, when the tool catalogue is built.

#### Scenario: An announced provider reaches the catalogue

- **GIVEN** an app listening for the event and registering a provider
- **WHEN** the MCP tool service is built
- **THEN** that provider's tools are in the catalogue

### Requirement: Both discovery paths coexist during migration (REQ-MCP-D-002)

The announced path SHALL be collected FIRST, and the legacy container-alias
scan SHALL still run afterwards for one release.

A provider whose `appId` is already present SHALL be skipped, so an app that
has migrated is never collected twice.

Removing the alias scan in the same change would break every app that has not
yet migrated.

#### Scenario: A migrated app is collected once

- **GIVEN** an app that both announces a provider and registers the legacy alias
- **WHEN** discovery runs
- **THEN** exactly one provider for that app is in the catalogue

### Requirement: Discovery never takes the container down (REQ-MCP-D-003)

A failure while collecting announced providers SHALL be logged and swallowed.

An app with a broken listener SHALL cost its own tools and nothing else — the
alternative is one bad app removing MCP from the instance.

#### Scenario: A throwing listener does not break discovery

- **GIVEN** a listener that throws
- **WHEN** discovery runs
- **THEN** the built-in providers are still in the catalogue

@e2e exclude discovery is backend-only — covered by PHPUnit
