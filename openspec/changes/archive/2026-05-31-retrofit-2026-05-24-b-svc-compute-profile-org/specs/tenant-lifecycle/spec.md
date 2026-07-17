## ADDED Requirements

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
