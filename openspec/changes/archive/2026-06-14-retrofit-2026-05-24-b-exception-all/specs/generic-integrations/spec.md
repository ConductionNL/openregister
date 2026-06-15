# generic-integrations (delta)

## ADDED Requirements

### Requirement: Provider-unavailable errors MUST carry an AD-23 cause classification
A raised `ProviderUnavailableException` MUST classify the failure with one of a fixed set of cause values so the UI can render the correct actionable message (per AD-23) when an external-integration call fails because the upstream service or the OpenConnector source itself is unreachable: connector-down ("Reconfigure connector") vs upstream-down ("Service offline") vs source-missing vs provider-auth. The cause MUST be exposed both directly and inside a structured `details` payload that the frontend consumes.

#### Scenario: Permitted cause vocabulary

- GIVEN the `ProviderUnavailableException` class
- THEN it MUST define exactly these four cause constants and string values:
  - `CAUSE_OPENCONNECTOR_DOWN` = `"openconnector-down"`
  - `CAUSE_OPENCONNECTOR_SOURCE_MISSING` = `"openconnector-source-missing"`
  - `CAUSE_UPSTREAM_SERVICE_DOWN` = `"upstream-service-down"`
  - `CAUSE_PROVIDER_AUTH` = `"provider-auth"`

#### Scenario: getCause returns the constructor cause

- GIVEN a `ProviderUnavailableException` constructed with
  `cause = ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN`
- WHEN `getCause()` is called
- THEN it MUST return `"openconnector-down"` unchanged

#### Scenario: getDetails returns the cause payload

- GIVEN a `ProviderUnavailableException` constructed with
  `cause = "upstream-service-down"`
- WHEN `getDetails()` is called
- THEN it MUST return `{"cause": "upstream-service-down"}`
- AND this payload MUST be the `details.cause` shape the UI renders for the
  actionable error
