## ADDED Requirements

### Requirement: Organisation and multitenancy configuration API
The system SHALL expose an admin-gated API for reading and writing organisation settings
and multitenancy configuration that govern tenant isolation. `ConfigurationSettingsController`
provides `getOrganisationSettings`/`updateOrganisationSettings` (delegating to
`SettingsService::getOrganisationSettingsOnly()` / `updateOrganisationSettingsOnly()`) and
`getMultitenancySettings`/`updateMultitenancySettings` (delegating to
`getMultitenancySettingsOnly()` / `updateMultitenancySettingsOnly()`). All four return HTTP
500 with an `error` field on service failure.

#### Scenario: Read multitenancy settings
- **WHEN** `getMultitenancySettings` is called
- **THEN** it MUST return the multitenancy settings from `SettingsService::getMultitenancySettingsOnly()`

#### Scenario: Update organisation settings
- **GIVEN** an admin posts updated organisation defaults
- **WHEN** `updateOrganisationSettings` runs
- **THEN** it MUST persist them via `SettingsService::updateOrganisationSettingsOnly()` and return the result
