---
status: done
---

# integration-openproject Specification

## Purpose
Links OpenProject work packages to OpenRegister objects, routing all CRUD through the ExternalIntegrationRouter to an OpenConnector `openproject` OAuth2 source rather than a local link table. The provider appears only when that source exists, surfaces an explicit "Reconnect" banner when the token expires, and exposes its auth status through OCS capabilities. Per-work-package visibility is governed transitively by OpenProject's own ACLs.
## Requirements
### Requirement: OpenProject Provider Registration

The system SHALL register `OpenProjectProvider` with id='openproject', group='external', requiredApp=null, storage='external', `getOpenConnectorSource()='openproject'`.

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

#### Scenario: Auth requirements returned

- **WHEN** `authRequirements()` is called on the OpenProject provider
- **THEN** the system MUST return `{type: 'oauth2', configSchema: {...}}` with the OpenProject-specific fields

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

The system SHALL render the standard four surfaces; the dashboard SHALL show open WPs assigned to the user; single-entity SHALL be a WP chip with status badge.

#### Scenario: Surfaces rendered

- **WHEN** the OpenProject integration renders
- **THEN** the system MUST provide the standard four surfaces, show open WPs assigned to the user on the dashboard, and render single-entity as a WP chip with status badge

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'openproject'` SHALL render WP chip.

#### Scenario: Reference property renders WP chip

- **WHEN** a property with `referenceType: 'openproject'` is rendered
- **THEN** the system MUST render the WP chip

### Requirement: OCS Capabilities Includes Auth Status

Capabilities response SHALL include `authStatus` in the `openproject` integrations entry.

#### Scenario: Auth status present in capabilities

- **WHEN** the OCS capabilities response is generated
- **THEN** the system MUST include `authStatus` in the `openproject` integrations entry

### Requirement: Permission Inheritance

The system SHALL expose `requiresPermission() === null`; OpenProject's own ACLs govern per-WP visibility transitively.

#### Scenario: Permission inherited from OpenProject

- **WHEN** `requiresPermission()` is evaluated for the OpenProject provider
- **THEN** it MUST return `null` so that OpenProject's own ACLs govern per-WP visibility transitively

