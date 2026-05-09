---
status: proposed
---

# Integration: OpenProject

## Purpose

Link OpenProject work packages to OR objects through the registry via OpenConnector external routing. First external-service integration proving the umbrella's `storage='external'` path.

**Standards**: OpenProject REST API v3, OAuth 2.0, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md), [integration-xwiki](../../../integration-xwiki/specs/integration-xwiki/spec.md)

---

## Requirements

### Requirement: OpenProject Provider Registration

`OpenProjectProvider` registered with id='openproject', group='external', requiredApp=null, storage='external', `getOpenConnectorSource()='openproject'`.

#### Scenario: Provider present when OpenConnector source exists

- **GIVEN** OpenConnector has an `openproject` source with valid OAuth2 credentials
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the provider MUST be included

#### Scenario: Provider hidden when source missing

- **GIVEN** no OpenConnector source named `openproject`
- **WHEN** `IntegrationRegistry::getEnabled()` is called
- **THEN** the provider MUST NOT be included

### Requirement: Auth Requirements Declaration

`authRequirements()` SHALL return `{type: 'oauth2', configSchema: {...}}` with the OpenProject-specific fields.

### Requirement: External Routing

All CRUD SHALL route through `ExternalIntegrationRouter` to OpenConnector. No local link table SHALL store WP metadata beyond request-scope cache.

#### Scenario: List linked WPs routes through OpenConnector

- **WHEN** `GET /api/objects/{register}/{schema}/{id}/openproject` is called
- **THEN** `ExternalIntegrationRouter` MUST resolve the `openproject` OpenConnector source
- **AND** MUST invoke OpenConnector's list operation with object context
- **AND** the response MUST be returned to the caller unchanged

### Requirement: Auth Expiry Surfaces Clearly

When OpenConnector reports `authStatus: 'expired'`, the tab SHALL display an explicit "Reconnect" banner, not silently 401.

#### Scenario: Expired token surfaces banner

- **GIVEN** OAuth token expired
- **WHEN** the tab loads
- **THEN** a banner with "Authorisation expired — reconnect" MUST be shown
- **AND** clicking MUST link to OpenConnector's credential management for the source

### Requirement: Widget Surfaces

Standard four; dashboard shows open WPs assigned to user; single-entity is WP chip with status badge.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'openproject'` SHALL render WP chip.

### Requirement: OCS Capabilities Includes Auth Status

Capabilities response SHALL include `authStatus` in the `openproject` integrations entry.

### Requirement: Permission Inheritance

`requiresPermission() === null`; OpenProject's own ACLs govern per-WP visibility transitively.
