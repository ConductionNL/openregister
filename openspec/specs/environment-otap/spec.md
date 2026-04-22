# Environment OTAP

## Purpose
Define environment type tagging (Ontwikkeling/Test/Acceptatie/Productie) for Organisation entities, enabling environment-aware configuration, behavior differentiation, and configuration promotion between environments. This supports the standard Dutch government OTAP deployment model where changes flow from development through test and acceptance to production.

**Source**: Dutch government OTAP requirements; BIO mandates separation of environments; 73% of tenders require DTAP/OTAP environment management.

## Requirements

### Requirement: Organisation entities MUST have an environment type field
Each Organisation MUST have an `environment` field that identifies which OTAP stage it represents. Valid values are `development`, `test`, `acceptance`, `production`. The default MUST be `production` for backward compatibility.

#### Scenario: New organisation defaults to production environment
- **WHEN** an Organisation is created without specifying an environment
- **THEN** the `environment` field MUST default to `production`

#### Scenario: Organisation can be tagged with a specific environment
- **WHEN** an administrator creates an Organisation with `environment: "test"`
- **THEN** the Organisation MUST be stored with `environment: "test"`
- **AND** the environment MUST be included in all API responses for this Organisation

#### Scenario: Environment field is immutable after activation
- **WHEN** an administrator attempts to change the environment of an `active` Organisation from `test` to `production`
- **THEN** the API MUST return HTTP 409 Conflict with message "Environment cannot be changed after activation. Create a new organisation for the target environment."

### Requirement: Environment-aware behavior MUST differ between OTAP stages
The system MUST adjust its behavior based on the Organisation's environment type to support safe development and testing workflows.

#### Scenario: Development environment has relaxed quota limits
- **WHEN** an API request is processed for an Organisation with `environment: "development"`
- **THEN** request quota limits MUST be multiplied by 10x compared to production defaults
- **AND** bandwidth quota limits MUST be multiplied by 5x compared to production defaults

#### Scenario: Production environment enforces strict audit logging
- **WHEN** any write operation (create/update/delete) occurs in an Organisation with `environment: "production"`
- **THEN** an audit trail entry MUST be created with full before/after state
- **AND** the audit trail entry MUST include the authenticated user identity

#### Scenario: Test environment allows bulk data reset
- **WHEN** an administrator calls `POST /api/organisations/{uuid}/reset-data` for an Organisation with `environment: "test"`
- **THEN** all objects in the Organisation MUST be deleted
- **AND** schemas and configuration MUST be preserved
- **AND** this endpoint MUST return HTTP 403 for `production` and `acceptance` environments

### Requirement: Configuration promotion MUST transfer settings between OTAP environments
Administrators MUST be able to promote configuration (schemas, mappings, sources, webhooks, endpoints) from one environment to another within the same parent organisation hierarchy.

#### Scenario: Promote configuration from test to acceptance
- **WHEN** an administrator calls `POST /api/organisations/{sourceUuid}/promote` with `targetOrganisation: "{targetUuid}"`
- **AND** the source Organisation has `environment: "test"` and the target has `environment: "acceptance"`
- **THEN** the system MUST export all schemas, mappings, sources, webhooks, and endpoints from the source
- **AND** the system MUST import them into the target Organisation with UUID remapping
- **AND** a promotion audit trail entry MUST be created in both organisations

#### Scenario: Promotion validates environment ordering
- **WHEN** an administrator attempts to promote from `production` to `development`
- **THEN** the API MUST return HTTP 400 Bad Request with message "Promotion must follow OTAP order: development -> test -> acceptance -> production"

#### Scenario: Promotion creates a rollback snapshot
- **WHEN** a promotion is executed to the target Organisation
- **THEN** the system MUST create a configuration snapshot of the target Organisation before applying changes
- **AND** the snapshot MUST be retrievable for 30 days for rollback purposes

### Requirement: Database migration MUST add environment field to Organisation entity

#### Scenario: Migration adds environment column
- **WHEN** the database migration runs
- **THEN** the `openregister_organisations` table MUST have column `environment` added (varchar(20), default 'production')
- **AND** all existing organisations MUST have `environment` set to `production`
