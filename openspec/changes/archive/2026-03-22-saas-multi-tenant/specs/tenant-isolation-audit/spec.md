# Tenant Isolation Audit

## Purpose
Define cross-tenant access audit logging, isolation verification, and automated isolation testing to ensure that tenant boundaries are never breached in a SaaS deployment. This provides the evidence trail required for BIO/ISO 27001 compliance and builds confidence that the shared-database multi-tenancy model provides adequate isolation.

**Source**: BIO/ISO 27001 audit requirements; government procurement requirement for demonstrable tenant isolation; ISAE 3402 evidence trail.

## ADDED Requirements

### Requirement: Cross-tenant access attempts MUST be logged to the audit trail
Any attempt to access data belonging to a different organisation (whether successful due to admin override or blocked) MUST be recorded in the audit trail with full context.

#### Scenario: Blocked cross-tenant access is logged
- **WHEN** user `jan` with active Organisation `org-A` attempts to access an object belonging to Organisation `org-B`
- **AND** the `MultiTenancyTrait::verifyOrganisationAccess()` blocks the request
- **THEN** an audit trail entry MUST be created with type `cross_tenant_access_denied`
- **AND** the entry MUST include: `userId`, `sourceOrganisation` (org-A), `targetOrganisation` (org-B), `entityType`, `entityId`, `action`, `timestamp`, `ipAddress`

#### Scenario: Admin cross-tenant access (when override enabled) is logged
- **WHEN** an admin user accesses data from a different Organisation via admin override
- **THEN** an audit trail entry MUST be created with type `cross_tenant_access_admin_override`
- **AND** the entry MUST include the same fields as denied access plus `adminOverrideJustification` if provided

#### Scenario: Audit entries are immutable
- **WHEN** a cross-tenant audit trail entry is created
- **THEN** it MUST NOT be modifiable or deletable via any API
- **AND** it MUST be stored with a SHA-256 hash of the entry content for tamper detection

### Requirement: Tenant isolation MUST be verifiable via automated checks
The system MUST provide an API endpoint for administrators to run isolation verification checks that confirm no data leakage exists between tenants.

#### Scenario: Isolation verification confirms no cross-tenant data
- **WHEN** an administrator calls `POST /api/admin/isolation-verify`
- **THEN** the system MUST query every schema table and verify that all rows have a valid `_organisation` value matching an existing Organisation
- **AND** the system MUST verify that no Organisation's query filter returns objects belonging to another Organisation
- **AND** the response MUST include a verification report with pass/fail per schema and total object counts per organisation

#### Scenario: Isolation verification detects orphaned data
- **WHEN** the verification finds objects with `_organisation` values that do not match any existing Organisation
- **THEN** the report MUST flag these as `orphaned_data` with the count and affected schemas
- **AND** the report MUST include remediation guidance

### Requirement: Suspended and deprovisioning organisations MUST have API access blocked at middleware level
The `TenantQuotaMiddleware` MUST check the Organisation status and block all API access for non-active organisations.

#### Scenario: Suspended organisation API access is blocked
- **WHEN** an API request is scoped to an Organisation with `status: "suspended"`
- **THEN** the middleware MUST return HTTP 403 Forbidden
- **AND** the response MUST include `{"error": "Organisation is suspended", "status": "suspended"}`
- **AND** an audit trail entry MUST be created for the blocked access attempt

#### Scenario: Deprovisioning organisation API access is blocked
- **WHEN** an API request is scoped to an Organisation with `status: "deprovisioning"`
- **THEN** the middleware MUST return HTTP 403 Forbidden
- **AND** the response MUST include `{"error": "Organisation is being deprovisioned", "status": "deprovisioning"}`

#### Scenario: Provisioning organisation only allows admin API access
- **WHEN** a non-admin API request is scoped to an Organisation with `status: "provisioning"`
- **THEN** the middleware MUST return HTTP 403 Forbidden
- **AND** admin users MUST still be able to access the Organisation for setup purposes

### Requirement: Tenant isolation metrics MUST be available for monitoring
The system MUST expose tenant isolation health metrics for monitoring systems.

#### Scenario: Isolation metrics endpoint returns current state
- **WHEN** an administrator calls `GET /api/admin/isolation-metrics`
- **THEN** the response MUST include: total number of organisations, number per status (active/suspended/archived), cross-tenant access denial count (last 24h), last isolation verification timestamp and result
