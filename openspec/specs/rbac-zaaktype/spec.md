# rbac-zaaktype Specification

## Purpose
Implement role-based access control (RBAC) at the zaaktype (case type) and objecttype level. Users and groups MUST only access records belonging to types they are authorized for. This covers read, write, and delete permissions scoped to specific register schema combinations, enabling fine-grained data compartmentalization across departments.

**Tender demand**: 86% of analyzed government tenders require RBAC per zaaktype.

## ADDED Requirements

### Requirement: Authorization policies MUST be configurable per schema
Each schema in a register MUST support an authorization policy that defines which Nextcloud groups or users may perform CRUD operations on its objects.

#### Scenario: Define read-only access for a group
- GIVEN a register `zaken` with schema `bezwaarschriften`
- AND group `juridisch-team` is granted `read` permission on `bezwaarschriften`
- WHEN a user in `juridisch-team` attempts to list bezwaarschriften objects
- THEN the system MUST return the objects
- AND when the same user attempts to create or update a bezwaarschrift
- THEN the system MUST return HTTP 403 Forbidden

#### Scenario: Define full access for a group
- GIVEN schema `vergunningen` with group `vth-behandelaars` granted `read,write,delete`
- WHEN a user in `vth-behandelaars` creates, updates, or deletes a vergunning object
- THEN all operations MUST succeed

#### Scenario: Deny access to unauthorized users
- GIVEN schema `bezwaarschriften` with only `juridisch-team` authorized
- WHEN a user NOT in `juridisch-team` attempts any CRUD operation on bezwaarschriften
- THEN the system MUST return HTTP 403 Forbidden
- AND the schema MUST NOT appear in the user's schema listing

### Requirement: Authorization policies MUST support user-level overrides
Individual users MUST be grantable permissions independent of group membership for delegation scenarios.

#### Scenario: Delegated access for a single user
- GIVEN schema `personeelszaken` restricted to group `hr-team`
- AND user `extern-adviseur` is individually granted `read` on `personeelszaken`
- WHEN `extern-adviseur` lists personeelszaken objects
- THEN the system MUST return the objects
- AND `extern-adviseur` MUST NOT be able to write or delete

### Requirement: Permission checks MUST apply to API endpoints
All REST API endpoints (list, get, create, update, delete) MUST enforce the authorization policy before processing the request.

#### Scenario: API request without permission
- GIVEN an authenticated API consumer mapped to user `api-user`
- AND `api-user` has no permissions on schema `vertrouwelijk`
- WHEN the consumer sends GET /api/objects/{register}/{schema}
- THEN the system MUST return HTTP 403 Forbidden

#### Scenario: API request with read-only permission
- GIVEN `api-user` has `read` on schema `meldingen`
- WHEN the consumer sends POST /api/objects/{register}/{schema}
- THEN the system MUST return HTTP 403 Forbidden
- AND GET requests MUST succeed

### Requirement: Admin users MUST bypass authorization policies
Users with Nextcloud admin or OpenRegister admin role MUST have unrestricted access to all schemas and objects.

#### Scenario: Admin bypasses RBAC
- GIVEN schema `vertrouwelijk` with access restricted to `directie` group
- WHEN a Nextcloud admin user accesses `vertrouwelijk` objects
- THEN all CRUD operations MUST succeed regardless of group membership

### Requirement: Authorization changes MUST be logged in the audit trail
Every change to an authorization policy MUST produce an audit trail entry recording who changed what.

#### Scenario: Permission grant logged
- GIVEN admin grants `read,write` on schema `meldingen` to group `kcc-team`
- THEN an audit trail entry MUST be created with action `rbac.permission_granted`
- AND the entry MUST record the schema, group, and permissions granted

#### Scenario: Permission revocation logged
- GIVEN admin revokes `write` from group `kcc-team` on schema `meldingen`
- THEN an audit trail entry MUST be created with action `rbac.permission_revoked`
- AND existing sessions of affected users SHOULD have their cached permissions invalidated

### Requirement: The admin UI MUST provide a permission matrix view
Administrators MUST be able to view and edit permissions in a matrix of schemas vs groups/users with CRUD checkboxes.

#### Scenario: View permission matrix
- GIVEN a register with 5 schemas and 3 groups
- WHEN the admin navigates to the register's authorization settings
- THEN a matrix MUST be displayed with schemas as rows and groups as columns
- AND each cell MUST show read/write/delete checkboxes reflecting current permissions

### Current Implementation Status
- **Fully implemented — schema-level RBAC**: `PermissionHandler` (`lib/Service/Object/PermissionHandler.php`) enforces authorization policies per schema. It checks group membership for CRUD operations and returns HTTP 403 for unauthorized access.
- **Fully implemented — property-level RBAC**: `PropertyRbacHandler` (`lib/Service/PropertyRbacHandler.php`) enforces field-level authorization within schemas, supporting read/update restrictions per property.
- **Fully implemented — database-level RBAC filtering**: `MagicRbacHandler` (`lib/Db/MagicMapper/MagicRbacHandler.php`) applies RBAC filters at the SQL query level, ensuring unauthorized objects are never returned in list queries.
- **Fully implemented — admin bypass**: The `PermissionHandler` checks for admin group membership and bypasses all authorization checks for admin users.
- **Fully implemented — conditional authorization**: `ConditionMatcher` (`lib/Service/ConditionMatcher.php`) and `OperatorEvaluator` (`lib/Service/OperatorEvaluator.php`) evaluate conditional RBAC rules with organisation matching, user identity, and custom conditions.
- **Fully implemented — multi-tenancy integration**: `MultiTenancyTrait` (`lib/Db/MultiTenancyTrait.php`) enforces organisation-scoped access alongside RBAC.
- **Fully implemented — schema authorization configuration**: `Schema` entity (`lib/Db/Schema.php`) stores authorization blocks defining group-based access rules per CRUD operation.
- **Partially implemented — audit trail for RBAC changes**: Audit trail exists for object changes (`AuditTrailController`, `lib/Controller/AuditTrailController.php`) but specific `rbac.permission_granted`/`rbac.permission_revoked` events for authorization policy changes are not explicitly logged.
- **Not implemented — user-level overrides**: Individual user permissions independent of group membership are not directly supported. Users must be added to groups for authorization.
- **Not implemented — permission matrix UI**: No admin UI displaying a matrix of schemas vs groups with CRUD checkboxes exists. Schema authorization is configured via the schema editor, not a dedicated matrix view.

### Standards & References
- ZGW Autorisaties API (VNG) for Dutch government zaaktype-based authorization patterns
- Nextcloud Group-based access control (IGroupManager)
- OAuth 2.0 scopes for API consumer authorization
- BIO (Baseline Informatiebeveiliging Overheid) for government security requirements
- AVG/GDPR for data compartmentalization requirements
- Common Ground principles for role-based access in government systems

### Specificity Assessment
- **Specific and largely implemented**: The core RBAC infrastructure (schema-level, property-level, database-level filtering, admin bypass, conditional matching) is fully in place.
- **Well-defined scenarios**: Clear scenarios for read-only access, full access, unauthorized access, delegated access, and API enforcement.
- **Missing implementations**:
  - User-level overrides (delegation without group membership) need a design decision
  - Permission matrix UI needs frontend development
  - RBAC change audit events need explicit logging
- **Open questions**:
  - Should user-level overrides be stored on the schema or as a separate entity?
  - How should the permission matrix UI handle large numbers of schemas and groups?
  - Should RBAC policy changes be versioned for rollback capability?
