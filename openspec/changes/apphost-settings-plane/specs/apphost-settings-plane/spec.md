# apphost-settings-plane

## ADDED Requirements

### Requirement: Generic settings surface
The AppHost SHALL provide a generic settings service and controller base
exposing exactly `index` (read merged config), `update` (write), and
`load` (import register configuration with a boolean `force` parameter)
for any consuming app, using the ADR-050 response envelope.

#### Scenario: Canonical settings round-trip
- **WHEN** a consuming app's settings are read via `index`, modified via `update`, and re-read
- **THEN** the returned envelope reflects the persisted values and no other endpoint shape is required by the consumer

#### Scenario: Foundation missing is explicit
- **WHEN** OpenRegister services are unavailable to a consumer of the generic settings service
- **THEN** the response is an explicit error status with a machine-readable reason, never a silent null or empty-success

### Requirement: Generic per-user preferences
The AppHost SHALL provide a generic preferences controller
(getPreference/setPreference) usable by any app without copying controller
code.

#### Scenario: Preference persists per user
- **WHEN** a user sets a preference key through the generic controller
- **THEN** a subsequent get for that user returns the value and other users are unaffected

### Requirement: Register configuration resolution
The AppHost SHALL provide a register/schema configuration resolver that
resolves an app's configured register and schema identifiers, treating
empty or missing configuration as an explicit error.

#### Scenario: Empty configuration fails closed
- **WHEN** an app requests resolution and the stored configuration value is empty
- **THEN** the resolver reports an explicit configuration error rather than returning null or matching zero objects silently

### Requirement: Exception-to-HTTP translation consumable
The AppHost SHALL publish an exception-translation trait mapping typed
exceptions to HTTP statuses with leak-safe response bodies, suitable for
adoption by any controller in the fleet.

#### Scenario: Internal detail is not leaked
- **WHEN** a controller using the trait catches an unexpected throwable
- **THEN** the client receives a generic error body with a 5xx status while the detailed message is written to the server log only
