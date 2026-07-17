---
status: done
retrofit_extensions:
  - REQ-005
---

# Tenant Lifecycle

## Purpose

@e2e exclude backend Organisation state machine — covered by PHPUnit
Define the provisioning, suspension, and deprovisioning workflow for tenant organisations in a SaaS multi-tenant OpenRegister deployment. Each tenant maps to an Organisation entity with a lifecycle state machine that governs API access, data retention, and administrative operations.

**Source**: SaaS deployment requirements; BIO/ISO 27001 tenant management; 67% of government tenders require demonstrable tenant isolation with controlled provisioning.
## Requirements
### Requirement: Organisation entities MUST have a lifecycle status field with defined state transitions
The Organisation entity MUST include a `status` field representing the tenant lifecycle state. Valid states are: `provisioning`, `active`, `suspended`, `deprovisioning`, `archived`. State transitions MUST follow the defined state machine and MUST be enforced at the service layer.

#### Scenario: New organisation starts in provisioning state
- **WHEN** an administrator creates a new Organisation via the API with `name: "Gemeente Utrecht"`
- **THEN** the Organisation MUST be created with `status: "provisioning"`
- **AND** the Organisation MUST have a `provisionedAt` timestamp set to the current time
- **AND** the Organisation MUST NOT be accessible for regular API operations until status transitions to `active`

#### Scenario: Organisation transitions from provisioning to active
- **WHEN** the provisioning workflow completes (default schemas, groups, and configuration created)
- **THEN** the Organisation status MUST transition to `active`
- **AND** an `OrganisationActivatedEvent` MUST be dispatched
- **AND** the Organisation MUST become accessible for all API operations

#### Scenario: Active organisation can be suspended
- **WHEN** an administrator suspends an active Organisation via `PUT /api/organisations/{uuid}/suspend`
- **THEN** the Organisation status MUST transition to `suspended`
- **AND** a `suspendedAt` timestamp MUST be set
- **AND** all API requests scoped to this Organisation MUST return HTTP 403 with message "Organisation is suspended"
- **AND** an `OrganisationSuspendedEvent` MUST be dispatched

#### Scenario: Suspended organisation can be reactivated
- **WHEN** an administrator reactivates a suspended Organisation via `PUT /api/organisations/{uuid}/activate`
- **THEN** the Organisation status MUST transition to `active`
- **AND** the `suspendedAt` field MUST be cleared
- **AND** all API operations MUST resume normally

#### Scenario: Organisation deprovisioning initiates graceful teardown
- **WHEN** an administrator initiates deprovisioning via `PUT /api/organisations/{uuid}/deprovision`
- **THEN** the Organisation status MUST transition to `deprovisioning`
- **AND** an automatic configuration export MUST be created as a backup
- **AND** all API requests MUST return HTTP 403 with message "Organisation is being deprovisioned"
- **AND** an `OrganisationDeprovisioningEvent` MUST be dispatched

#### Scenario: Invalid state transitions MUST be rejected
- **WHEN** an administrator attempts to transition an `archived` Organisation to `active`
- **THEN** the API MUST return HTTP 409 Conflict
- **AND** the response MUST include the current status and valid transitions

### Requirement: Tenant provisioning MUST create default resources automatically
When an Organisation transitions from `provisioning` to `active`, the system MUST automatically create the configured default resources for the tenant.

#### Scenario: Provisioning creates default configuration
- **WHEN** Organisation "Gemeente Utrecht" completes provisioning
- **THEN** the system MUST create default Nextcloud groups prefixed with the organisation slug (e.g., `gemeente-utrecht-admin`, `gemeente-utrecht-users`)
- **AND** the system MUST assign the creating user to the organisation's admin group
- **AND** the system MUST set the organisation's `authorization` with default RBAC rules

#### Scenario: Provisioning failure rolls back partial resources
- **WHEN** provisioning fails partway through (e.g., group creation fails)
- **THEN** the Organisation MUST remain in `provisioning` state
- **AND** any successfully created resources MUST be preserved for retry
- **AND** an error event MUST be logged with details of the failure

### Requirement: Deprovisioned organisations MUST transition to archived with data retention
After deprovisioning completes, the Organisation MUST transition to `archived` state with configurable data retention.

#### Scenario: Deprovisioning completes and archives the organisation
- **WHEN** the deprovisioning background job completes for Organisation "Gemeente Utrecht"
- **THEN** all objects belonging to the Organisation MUST be soft-deleted (marked as deleted, not physically removed)
- **AND** the Organisation status MUST transition to `archived`
- **AND** the configuration export backup MUST be retained
- **AND** an `OrganisationArchivedEvent` MUST be dispatched

#### Scenario: Archived organisation data is purged after retention period
- **WHEN** an archived Organisation has exceeded the configured retention period (default: 90 days)
- **THEN** a background job MUST permanently delete all objects, schemas, and configuration for that Organisation
- **AND** the Organisation entity itself MUST be permanently deleted
- **AND** an audit trail entry MUST be created recording the permanent deletion

### Requirement: Database migration MUST add lifecycle fields to Organisation entity
The migration MUST add the required fields to support tenant lifecycle management.

#### Scenario: Migration adds status and timestamp fields
- **WHEN** the database migration `Version1Date20260322000000` runs
- **THEN** the `openregister_organisations` table MUST have columns added: `status` (varchar(20), default 'active'), `provisioned_at` (datetime, nullable), `suspended_at` (datetime, nullable), `deprovisioned_at` (datetime, nullable)
- **AND** all existing organisations MUST have `status` set to `active`
- **AND** the migration MUST be reversible (columns can be dropped without data loss)

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

### Requirement: OrganisationService MUST resolve per-user organisation membership and active context

`OrganisationService` MUST provide the runtime membership-resolution layer that sits
beneath the tenant provisioning state machine. The system MUST always guarantee a user
has at least one organisation: a user with no memberships MUST be auto-added to the
default organisation, which MUST itself be auto-created on first demand. Every user MUST
have an active organisation; when none is explicitly set the system MUST auto-select the
oldest organisation the user belongs to, falling back to the default organisation. Joining
and leaving MUST be guarded — a user MUST NOT be able to leave their last organisation, and
setting an active organisation the user does not belong to MUST be rejected.

#### Scenario: User with no organisations is added to the default
- **WHEN** `getUserOrganisations()` is called for a user with no memberships
- **THEN** the default organisation MUST be ensured (created if absent) and the user added to it
- **AND** the returned list MUST contain that default organisation

#### Scenario: Active organisation auto-selects the oldest membership
- **GIVEN** a user with two organisations and no explicitly-set active organisation
- **WHEN** `getActiveOrganisation()` resolves from the database
- **THEN** the oldest organisation (by created date) MUST be selected and persisted as active
- **AND** when the user belongs to none, the default organisation MUST be used as the fallback

#### Scenario: Setting an active organisation requires membership
- **WHEN** `setActiveOrganisation($uuid)` is called
- **THEN** an organisation the user does not belong to MUST raise an exception
- **AND** a non-existent organisation UUID MUST raise an exception
- **AND** on success the choice MUST be persisted to user config so it survives across sessions

#### Scenario: Leaving the last organisation is forbidden
- **WHEN** `leaveOrganisation($uuid)` would remove the user's only remaining organisation
- **THEN** an exception MUST be raised preventing the user from being orphaned
- **AND** when the left organisation was the active one, the active selection MUST be cleared and re-resolved

#### Scenario: Admin users bypass membership for access checks
- **WHEN** `hasAccessToOrganisation($uuid)` is called for a user in the `admin` group
- **THEN** access MUST be granted regardless of explicit membership
- **AND** for non-admin users access MUST require explicit membership

#### Scenario: Active-organisation resolution includes the parent chain
- **WHEN** `getUserActiveOrganisations()` is called with a hierarchical organisation active
- **THEN** the result MUST contain the active organisation UUID followed by every ancestor UUID resolved via the parent chain
- **AND** this list is what multi-tenancy query filtering uses to grant children visibility of parent resources

#### Scenario: Creating an organisation recovers from a slug collision
- **WHEN** `createOrganisation($name, ...)` produces a slug that already exists
- **THEN** the unique-constraint violation MUST be caught and the existing organisation with that slug returned instead of crashing
- **AND** when a specific UUID was requested it MUST be reconciled onto the existing entity
- **AND** a newly-created organisation MUST seed the `admin` group into its RBAC authorization and add all admin-group users as members

### Requirement: Active and default organisation lookups MUST be cached with bounded staleness

`OrganisationService` MUST cache organisation lookups to keep RBAC-path resolution cheap.
The active organisation MUST be cached in the session (per user) and the default
organisation in static (cross-instance) memory, both with a 15-minute TTL. Cached
organisations MUST be stored as plain arrays and reconstructed into `Organisation` entities
on read to avoid serialization issues. The cache MUST self-invalidate when the underlying
membership becomes stale: a cached active organisation the user no longer belongs to, or
that no longer exists, MUST be cleared and re-resolved.

#### Scenario: Active organisation is served from session cache within TTL
- **GIVEN** an active organisation cached less than 15 minutes ago
- **WHEN** `getActiveOrganisation()` is called
- **THEN** the organisation MUST be reconstructed from the cached array without a database query

#### Scenario: Stale active-organisation membership is invalidated
- **GIVEN** a persisted active organisation UUID the user no longer belongs to
- **WHEN** the active organisation is resolved from the database
- **THEN** the stale user-config value and session cache MUST be cleared
- **AND** resolution MUST continue to the oldest-membership / default fallback

#### Scenario: Switching the active organisation clears and re-primes the cache
- **WHEN** `setActiveOrganisation($uuid)` succeeds
- **THEN** the prior session caches MUST be removed and the new active organisation cached immediately

#### Scenario: Cache reconstruction round-trips DateTime fields
- **GIVEN** a cached organisation whose `created`/`updated` were stored as ISO strings
- **WHEN** `reconstructOrganisationFromCache()` runs
- **THEN** the string timestamps MUST be parsed back into `DateTime` instances on the rebuilt entity

