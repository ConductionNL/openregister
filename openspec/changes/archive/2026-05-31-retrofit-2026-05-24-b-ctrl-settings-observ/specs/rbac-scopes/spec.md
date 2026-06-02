## ADDED Requirements

### Requirement: RBAC settings configuration API
The system SHALL expose an admin-gated API for reading and writing the RBAC enablement and
configuration dials that govern scope enforcement. `ConfigurationSettingsController`
provides `getRbacSettings` (delegating to `SettingsService::getRbacSettingsOnly()`) and
`updateRbacSettings` (delegating to `SettingsService::updateRbacSettingsOnly()`). Both
return HTTP 500 with an `error` field on service failure.

#### Scenario: Read RBAC settings
- **WHEN** `getRbacSettings` is called
- **THEN** it MUST return the RBAC settings document from `SettingsService::getRbacSettingsOnly()`

#### Scenario: Update RBAC settings
- **GIVEN** an admin toggles RBAC enforcement and posts the change
- **WHEN** `updateRbacSettings` runs
- **THEN** it MUST persist the change via `SettingsService::updateRbacSettingsOnly()` and return the updated settings
