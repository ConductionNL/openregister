# Tenant Lifecycle

## Purpose
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
