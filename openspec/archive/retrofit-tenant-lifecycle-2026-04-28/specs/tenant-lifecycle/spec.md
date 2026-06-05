---
retrofit_extensions: [REQ-005]
---

### REQ-005: The system MUST validate OTAP environment values and enforce unidirectional promotion order

The `TenantLifecycleService` MUST expose utility methods for validating OTAP (Development, Test, Acceptance, Production) environments in multi-environment SaaS deployments. Validation MUST confirm that a given environment name is one of the four recognised OTAP stages, and that promotions only flow in the canonical upward direction (development → test → acceptance → production). Reverse promotions or same-environment promotions MUST be rejected.

#### Scenario: Valid OTAP environment names are accepted
- **GIVEN** the system knows four OTAP stages: `development`, `test`, `acceptance`, `production`
- **WHEN** `isValidEnvironment("acceptance")` is called
- **THEN** the method MUST return `true`
- **AND** `isValidEnvironment("staging")` MUST return `false`
- **AND** `isValidEnvironment("")` MUST return `false`

#### Scenario: Unidirectional promotion is enforced
- **GIVEN** OTAP order is development (0) < test (1) < acceptance (2) < production (3)
- **WHEN** `isValidPromotionOrder("test", "acceptance")` is called
- **THEN** the method MUST return `true` (upward promotion)
- **AND** `isValidPromotionOrder("production", "test")` MUST return `false` (reverse — not allowed)
- **AND** `isValidPromotionOrder("test", "test")` MUST return `false` (same-stage — not allowed)

#### Scenario: Invalid environment names are rejected in promotion checks
- **GIVEN** an unknown environment string (e.g. `"staging"`) is passed as source or target
- **WHEN** `isValidPromotionOrder("staging", "production")` is called
- **THEN** the method MUST return `false`
