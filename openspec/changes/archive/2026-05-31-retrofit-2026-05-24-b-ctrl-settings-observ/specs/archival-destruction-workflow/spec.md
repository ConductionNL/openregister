## ADDED Requirements

### Requirement: Archival and destruction-scheduling configuration API
The system SHALL expose an admin-gated API for reading and writing archival settings —
destruction scheduling, selectielijst configuration, and related dials.
`ConfigurationSettingsController` provides `getArchivalSettings` (delegating to
`SettingsService::getArchivalSettingsOnly()`) and `updateArchivalSettings` (delegating to
`SettingsService::updateArchivalSettingsOnly()`). Both return HTTP 500 with an `error`
field on service failure.

#### Scenario: Read archival settings
- **WHEN** `getArchivalSettings` is called
- **THEN** it MUST return the archival/destruction configuration from `SettingsService::getArchivalSettingsOnly()`

#### Scenario: Update archival settings
- **GIVEN** an admin posts updated destruction-scheduling values
- **WHEN** `updateArchivalSettings` runs
- **THEN** it MUST persist them via `SettingsService::updateArchivalSettingsOnly()` and return the result
