---
status: done
---

# rbac-zaaktype Specification

## Purpose

@e2e exclude backend RBAC enforcement — covered by PHPUnit
Implement role-based access control (RBAC) at the zaaktype (case type) and objecttype level. Users and groups MUST only access records belonging to types they are authorized for. This covers read, write, and delete permissions scoped to specific register schema combinations, enabling fine-grained data compartmentalization across departments.

**Tender demand**: 86% of analyzed government tenders require RBAC per zaaktype.
## Requirements
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

### Requirement: Authorization policies MUST be configurable per schema (zaaktype)
Each schema in a register MUST support an authorization policy that defines which Nextcloud groups or users may perform CRUD operations on its objects. The authorization block on the schema entity SHALL be the primary mechanism for zaaktype-scoped access control, where each schema maps to a zaaktype or objecttype.

#### Scenario: Define read-only access for a group on a specific zaaktype
- **GIVEN** a register `zaken` with schema `bezwaarschriften` (representing zaaktype "Bezwaarschrift")
- **AND** group `juridisch-team` is granted `read` permission on `bezwaarschriften`
- **WHEN** a user in `juridisch-team` attempts to list bezwaarschriften objects
- **THEN** the system MUST return the objects
- **AND** when the same user attempts to create or update a bezwaarschrift
- **THEN** the system MUST return HTTP 403 Forbidden

#### Scenario: Define full CRUD access for a group on a zaaktype
- **GIVEN** schema `vergunningen` with authorization: `{ "read": ["vth-behandelaars"], "create": ["vth-behandelaars"], "update": ["vth-behandelaars"], "delete": ["vth-behandelaars"] }`
- **WHEN** a user in `vth-behandelaars` creates, reads, updates, or deletes a vergunning object
- **THEN** all operations MUST succeed
- **AND** `PermissionHandler::hasPermission()` MUST return `true` for each action

#### Scenario: Deny access to unauthorized users for a zaaktype
- **GIVEN** schema `bezwaarschriften` with only `juridisch-team` authorized for all CRUD operations
- **WHEN** a user NOT in `juridisch-team` attempts any CRUD operation on bezwaarschriften
- **THEN** the system MUST return HTTP 403 Forbidden
- **AND** `PermissionHandler::checkPermission()` MUST throw an Exception with message containing "does not have permission"
- **AND** the schema MUST NOT appear in the user's schema listing when using RBAC-filtered queries

#### Scenario: Separate read and write permissions per zaaktype
- **GIVEN** schema `meldingen-openbare-ruimte` with authorization: `{ "read": ["kcc-team", "behandelaars"], "create": ["kcc-team"], "update": ["behandelaars"], "delete": ["admin"] }`
- **WHEN** a user in `kcc-team` (but not `behandelaars`) creates a melding
- **THEN** the create operation MUST succeed
- **AND** when the same user attempts to update the melding
- **THEN** the system MUST return HTTP 403 Forbidden (user can create but not update)

#### Scenario: Multiple groups authorized for the same zaaktype action
- **GIVEN** schema `klachten` with authorization: `{ "read": ["kcc-team", "juridisch-team", "management"] }`
- **WHEN** a user in any of those three groups reads klachten
- **THEN** access MUST be granted because `PermissionHandler::hasPermission()` iterates over all user groups and returns `true` on first match

### Requirement: Authorization policies MUST support user-level overrides for delegation
Individual users MUST be grantable permissions independent of group membership to support delegation scenarios such as external advisors, temporary assignments, and escalation paths. User-level overrides SHALL take precedence over group-level denials.

#### Scenario: Delegated access for a single user on a zaaktype
- **GIVEN** schema `personeelszaken` restricted to group `hr-team`
- **AND** user `extern-adviseur` is individually granted `read` on `personeelszaken` via user-level override
- **WHEN** `extern-adviseur` lists personeelszaken objects
- **THEN** the system MUST return the objects
- **AND** `extern-adviseur` MUST NOT be able to write or delete (only explicitly granted permissions apply)

#### Scenario: Temporary delegation with expiry
- **GIVEN** schema `bezwaarschriften` restricted to group `juridisch-team`
- **AND** user `vervanger-1` is granted temporary `read,update` access with expiry date `2026-04-01`
- **WHEN** `vervanger-1` accesses bezwaarschriften before the expiry date
- **THEN** access MUST be granted
- **AND** after `2026-04-01`, the delegation MUST automatically expire and access MUST be denied

#### Scenario: Delegation does not affect group permissions
- **GIVEN** user `jan` is in group `kcc-team` which has `read` on schema `meldingen`
- **AND** `jan` is individually granted `update` on `meldingen` via delegation
- **WHEN** `jan` reads or updates a melding
- **THEN** both operations MUST succeed (group `read` + delegated `update` are combined)
- **AND** revoking the delegation MUST NOT affect `jan`'s group-based `read` permission

### Requirement: Role-to-zaaktype mapping MUST support per-zaaktype role differentiation
The system MUST support a model where a user can have different roles for different zaaktypes, analogous to ZGW's per-zaaktype autorisatie model and Dimpact ZAC's PABC architecture. This SHALL be achieved through Nextcloud group naming conventions that encode both the role and the zaaktype scope.

#### Scenario: User has different roles for different zaaktypes
- **GIVEN** user `behandelaar-1` is in groups `vergunningen-behandelaar` and `klachten-raadpleger`
- **AND** schema `vergunningen` has authorization: `{ "read": ["vergunningen-behandelaar", "vergunningen-raadpleger"], "update": ["vergunningen-behandelaar"] }`
- **AND** schema `klachten` has authorization: `{ "read": ["klachten-raadpleger", "klachten-behandelaar"], "update": ["klachten-behandelaar"] }`
- **WHEN** `behandelaar-1` reads klachten
- **THEN** access MUST be granted (via `klachten-raadpleger` group)
- **AND** when `behandelaar-1` updates a klacht, access MUST be denied (not in `klachten-behandelaar`)
- **AND** when `behandelaar-1` updates a vergunning, access MUST be granted (in `vergunningen-behandelaar`)

#### Scenario: Wildcard domain group grants access to all zaaktypes of a role level
- **GIVEN** group `elk-zaaktype-raadpleger` is referenced in multiple schema authorization rules via a shared group pattern
- **AND** user `manager-1` is in group `elk-zaaktype-raadpleger`
- **WHEN** `manager-1` reads objects from any schema that includes `elk-zaaktype-raadpleger` in its `read` authorization
- **THEN** access MUST be granted across all those schemas

#### Scenario: Role hierarchy through group composition
- **GIVEN** the role hierarchy: raadpleger (read-only) < behandelaar (read+write) < coordinator (read+write+assign) < beheerder (all)
- **AND** user `coordinator-1` is in groups `vergunningen-coordinator`, `vergunningen-behandelaar`, `vergunningen-raadpleger`
- **WHEN** `coordinator-1` performs any operation on vergunningen
- **THEN** the cumulative permissions from all groups MUST be combined (union of permissions)

### Requirement: The system MUST enforce a zaaktype x operation x role permission matrix
Administrators MUST be able to configure and view a permission matrix that maps (zaaktype/schema) x (CRUD operation) x (role/group) combinations. This matrix SHALL be the canonical representation of all zaaktype-scoped access control rules.

#### Scenario: View permission matrix for a register
- **GIVEN** a register `zaakregistratie` with 5 schemas (zaaktypen) and 4 groups
- **WHEN** the admin navigates to the register's authorization settings
- **THEN** a matrix MUST be displayed with schemas as rows and groups as columns
- **AND** each cell MUST show read/create/update/delete checkboxes reflecting current permissions from each schema's `authorization` block

#### Scenario: Edit permissions via the matrix view
- **GIVEN** the permission matrix is displayed for register `zaakregistratie`
- **WHEN** the admin checks the `update` checkbox for schema `klachten` and group `kcc-team`
- **THEN** the schema's `authorization.update` array MUST be updated to include `kcc-team`
- **AND** the change MUST take effect immediately for subsequent API requests

#### Scenario: Matrix reflects conditional authorization rules
- **GIVEN** schema `meldingen` has authorization: `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **WHEN** the permission matrix is displayed
- **THEN** the `read` cell for `behandelaars` on `meldingen` MUST show a conditional indicator (e.g., icon or tooltip)
- **AND** hovering/clicking MUST reveal the match condition: `_organisation = $organisation`

#### Scenario: Export permission matrix as CSV
- **GIVEN** a register with 20 schemas and 10 groups
- **WHEN** the admin exports the permission matrix
- **THEN** a CSV file MUST be generated with columns: schema, group, read, create, update, delete, conditions
- **AND** each row MUST represent one schema-group combination

### Requirement: The system MUST support vertrouwelijkheidaanduiding (confidentiality levels) per zaaktype
The ZGW standard defines 8 confidentiality levels (vertrouwelijkheidaanduiding) that MUST be enforceable per zaaktype. Each role/group MUST have a maximum vertrouwelijkheidaanduiding (maxVertrouwelijkheidaanduiding) that limits which objects they can access within a zaaktype based on the object's confidentiality level.

#### Scenario: Object filtered by vertrouwelijkheidaanduiding
- **GIVEN** schema `zaken` has a property `vertrouwelijkheidaanduiding` with type `string` and enum values: `openbaar`, `beperkt_openbaar`, `intern`, `zaakvertrouwelijk`, `vertrouwelijk`, `confidentieel`, `geheim`, `zeer_geheim`
- **AND** the authorization rule for group `kcc-team` includes a conditional match: `{ "group": "kcc-team", "match": { "vertrouwelijkheidaanduiding": { "$in": ["openbaar", "beperkt_openbaar", "intern"] } } }`
- **WHEN** a user in `kcc-team` lists zaken
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST add a SQL WHERE clause filtering on the vertrouwelijkheidaanduiding field
- **AND** only zaken with vertrouwelijkheidaanduiding `openbaar`, `beperkt_openbaar`, or `intern` MUST be returned
- **AND** zaken with `vertrouwelijk` or higher MUST NOT be visible

#### Scenario: Higher clearance group sees more confidential objects
- **GIVEN** group `management` has authorization with match: `{ "vertrouwelijkheidaanduiding": { "$in": ["openbaar", "beperkt_openbaar", "intern", "zaakvertrouwelijk", "vertrouwelijk", "confidentieel"] } }`
- **AND** group `kcc-team` has match: `{ "vertrouwelijkheidaanduiding": { "$in": ["openbaar", "beperkt_openbaar", "intern"] } }`
- **WHEN** a user in `management` and a user in `kcc-team` both list the same schema
- **THEN** the management user MUST see objects up to `confidentieel`
- **AND** the kcc-team user MUST only see objects up to `intern`

#### Scenario: Admin bypasses vertrouwelijkheidaanduiding filtering
- **GIVEN** a user in the `admin` group
- **WHEN** they list objects from any schema regardless of vertrouwelijkheidaanduiding
- **THEN** all objects MUST be returned because `PermissionHandler::hasPermission()` returns `true` immediately for admin group members

#### Scenario: Vertrouwelijkheidaanduiding enforcement on single object access
- **GIVEN** a user in `kcc-team` with maxVertrouwelijkheidaanduiding `intern`
- **AND** object `zaak-123` has `vertrouwelijkheidaanduiding: "vertrouwelijk"`
- **WHEN** the user sends GET `/api/objects/{register}/{schema}/zaak-123`
- **THEN** the system MUST return HTTP 403 Forbidden
- **AND** the response MUST NOT leak the object's data

#### Scenario: Confidentiality level hierarchy ordering
- **GIVEN** the ZGW vertrouwelijkheidaanduiding enum with ordering: `openbaar` (1) < `beperkt_openbaar` (2) < `intern` (3) < `zaakvertrouwelijk` (4) < `vertrouwelijk` (5) < `confidentieel` (6) < `geheim` (7) < `zeer_geheim` (8)
- **WHEN** comparing confidentiality levels for access decisions
- **THEN** the system MUST use ordinal comparison (lower number = less restrictive)
- **AND** a user with maxVertrouwelijkheidaanduiding at level N MUST be able to access objects at level N or below

### Requirement: Cross-zaaktype access MUST be supported for coordinator and management roles
Users with coordinator or management roles MUST be able to access objects across multiple zaaktypes for work distribution, reporting, and oversight purposes, without requiring individual zaaktype-level permissions for each schema.

#### Scenario: Coordinator with cross-zaaktype read access
- **GIVEN** user `coordinator-1` is in group `alle-zaken-coordinator`
- **AND** schemas `vergunningen`, `klachten`, `meldingen` all include `alle-zaken-coordinator` in their `read` authorization
- **WHEN** `coordinator-1` lists objects from any of those schemas
- **THEN** access MUST be granted for all three schemas

#### Scenario: Management dashboard aggregates across zaaktypes
- **GIVEN** user `manager-1` is in group `management` which has `read` on all zaaktype schemas
- **WHEN** `manager-1` queries a cross-schema aggregation endpoint (e.g., GraphQL query spanning multiple schemas)
- **THEN** objects from all authorized schemas MUST be returned
- **AND** schemas where `management` is NOT in the `read` authorization MUST be excluded

#### Scenario: Coordinator can reassign across zaaktypes
- **GIVEN** user `coordinator-1` has `update` permission on both `vergunningen` and `klachten` schemas
- **WHEN** `coordinator-1` updates a vergunning object's `assignedTo` field
- **THEN** the update MUST succeed
- **AND** `coordinator-1` MUST also be able to update a klacht object's `assignedTo` field in the same session

### Requirement: Permission checks MUST apply to all API endpoints consistently
All REST API endpoints (list, get, create, update, delete), GraphQL queries and mutations, MCP tool invocations, and public endpoints MUST enforce the zaaktype-scoped authorization policy via `PermissionHandler::checkPermission()` before processing the request.

#### Scenario: REST API request without zaaktype permission
- **GIVEN** an authenticated API consumer mapped to user `api-user`
- **AND** `api-user` has no permissions on schema `vertrouwelijk-zaaktype`
- **WHEN** the consumer sends GET `/api/objects/{register}/vertrouwelijk-zaaktype`
- **THEN** the system MUST return HTTP 403 Forbidden
- **AND** the response body MUST include a clear error message about missing permission

#### Scenario: REST API request with read-only zaaktype permission
- **GIVEN** `api-user` has `read` on schema `meldingen`
- **WHEN** the consumer sends POST `/api/objects/{register}/meldingen`
- **THEN** the system MUST return HTTP 403 Forbidden
- **AND** GET requests MUST succeed
- **AND** the error message MUST indicate that `create` permission is required

#### Scenario: GraphQL query enforces zaaktype RBAC
- **GIVEN** user `medewerker-1` has `read` on schema `vergunningen` but NOT on `bezwaarschriften`
- **WHEN** `medewerker-1` executes a GraphQL query: `{ vergunningen { edges { node { title } } } bezwaarschriften { edges { node { title } } } }`
- **THEN** `vergunningen` data MUST be returned
- **AND** `bezwaarschriften` MUST return a partial error with `extensions.code: "FORBIDDEN"`

#### Scenario: MCP tool invocation enforces zaaktype RBAC
- **GIVEN** an MCP client authenticated as user `mcp-user`
- **AND** `mcp-user` has `read` on schema `meldingen` but NOT `create`
- **WHEN** the MCP client calls `mcp__openregister__objects` with action `create` on the `meldingen` schema
- **THEN** the MCP response MUST contain an error indicating insufficient permissions

#### Scenario: Bulk operations enforce per-object zaaktype permission
- **GIVEN** a user submits a bulk update request affecting 50 objects across 3 schemas
- **AND** the user has `update` on 2 of the 3 schemas
- **THEN** objects in authorized schemas MUST be updated
- **AND** objects in the unauthorized schema MUST be rejected with individual error entries
- **AND** a partial success response MUST be returned

### Requirement: The frontend MUST render permission-aware UI components
The frontend application MUST adapt its UI based on the current user's zaaktype permissions, hiding or disabling actions the user cannot perform and omitting schemas the user cannot access.

#### Scenario: Schema list filters based on user permissions
- **GIVEN** a register with 10 schemas (zaaktypen)
- **AND** the current user has `read` permission on 6 of them
- **WHEN** the user views the register's schema list in the UI
- **THEN** only the 6 accessible schemas MUST be displayed
- **AND** the 4 inaccessible schemas MUST NOT appear in navigation or listing

#### Scenario: CRUD buttons disabled based on zaaktype permissions
- **GIVEN** a user has `read` on schema `vergunningen` but NOT `create` or `delete`
- **WHEN** the user views the vergunningen object list
- **THEN** the "New" / "Create" button MUST be hidden or disabled
- **AND** the "Delete" action on individual objects MUST be hidden or disabled
- **AND** the "Edit" action MUST be hidden or disabled (no `update` permission)

#### Scenario: Form fields reflect property-level RBAC within a zaaktype
- **GIVEN** schema `zaken` has property `interneAantekening` with authorization: `{ "read": [{ "group": "redacteuren" }], "update": [{ "group": "redacteuren" }] }`
- **AND** the user is NOT in group `redacteuren`
- **WHEN** the user views a zaak object detail page
- **THEN** the `interneAantekening` field MUST NOT be rendered in the form
- **AND** `PropertyRbacHandler::filterReadableProperties()` MUST have omitted it from the API response

#### Scenario: Confidentiality badge displayed for restricted objects
- **GIVEN** objects with varying `vertrouwelijkheidaanduiding` levels are displayed in a list
- **WHEN** the user views the list
- **THEN** each object MUST display a visual indicator of its confidentiality level (e.g., badge or icon)
- **AND** objects near the user's maximum clearance SHOULD display a warning indicator

### Requirement: All zaaktype access decisions MUST be logged in the audit trail
Every access attempt (granted or denied) against a zaaktype-scoped schema MUST produce an audit trail entry for compliance with BIO (Baseline Informatiebeveiliging Overheid) and AVG requirements.

#### Scenario: Permission grant event logged
- **GIVEN** admin grants `read,write` on schema `meldingen` to group `kcc-team`
- **THEN** an audit trail entry MUST be created with action `rbac.permission_granted`
- **AND** the entry MUST record the schema UUID, schema title, group name, permissions granted, and the admin user who made the change
- **AND** the entry MUST include a timestamp in ISO 8601 format

#### Scenario: Permission revocation event logged
- **GIVEN** admin revokes `write` from group `kcc-team` on schema `meldingen`
- **THEN** an audit trail entry MUST be created with action `rbac.permission_revoked`
- **AND** existing sessions of affected users SHOULD have their cached permissions invalidated
- **AND** the audit entry MUST record the previous and new permission states

#### Scenario: Access denied event logged
- **GIVEN** user `ongeautoriseerd` attempts to read objects from schema `vertrouwelijk`
- **AND** `ongeautoriseerd` has no permissions on `vertrouwelijk`
- **WHEN** the request is denied with HTTP 403
- **THEN** an audit trail entry MUST be created with action `rbac.access_denied`
- **AND** the entry MUST record: user ID, schema, attempted action, timestamp, IP address
- **AND** the `confidentiality` field on the AuditTrail entity MUST reflect the schema's sensitivity

#### Scenario: Bulk permission change produces individual audit entries
- **GIVEN** admin assigns permissions on 5 schemas to group `nieuwe-afdeling` in one bulk operation
- **THEN** 5 individual audit trail entries MUST be created (one per schema)
- **AND** each entry MUST be independently queryable

#### Scenario: Audit trail for vertrouwelijkheidaanduiding-based denial
- **GIVEN** user `kcc-1` with maxVertrouwelijkheidaanduiding `intern` attempts to access object with `vertrouwelijkheidaanduiding: "vertrouwelijk"`
- **WHEN** the request is denied
- **THEN** the audit entry MUST record both the user's max level and the object's actual level
- **AND** the audit entry MUST indicate the denial reason as `confidentiality_level_exceeded`

### Requirement: Bulk permission assignment MUST be supported for efficient onboarding
Administrators MUST be able to assign a permission template (a set of zaaktype permissions) to a group or user in a single operation, supporting department onboarding and role provisioning.

#### Scenario: Assign permission template to a new department group
- **GIVEN** a permission template `kcc-standaard` defines: `{ "meldingen": ["read", "create"], "klachten": ["read", "create"], "producten": ["read"] }`
- **AND** a new group `kcc-den-haag` is created
- **WHEN** admin applies template `kcc-standaard` to group `kcc-den-haag`
- **THEN** the authorization blocks of schemas `meldingen`, `klachten`, and `producten` MUST be updated to include `kcc-den-haag` with the specified permissions
- **AND** a single bulk audit trail entry MUST be created referencing all affected schemas

#### Scenario: Copy permissions from existing group
- **GIVEN** group `kcc-amsterdam` has permissions on 8 schemas
- **WHEN** admin copies all permissions from `kcc-amsterdam` to new group `kcc-rotterdam`
- **THEN** the authorization blocks of all 8 schemas MUST be updated to include `kcc-rotterdam`
- **AND** the permissions MUST be identical to `kcc-amsterdam`'s permissions on each schema

#### Scenario: Revoke all permissions for a group across all schemas
- **GIVEN** group `vertrekkende-afdeling` has permissions on 12 schemas
- **WHEN** admin revokes all permissions for `vertrekkende-afdeling`
- **THEN** the authorization blocks of all 12 schemas MUST be updated to remove `vertrekkende-afdeling`
- **AND** 12 individual `rbac.permission_revoked` audit entries MUST be created

### Requirement: Delegation and escalation patterns MUST be supported within zaaktype authorization
The system MUST support delegation (granting temporary access to another user) and escalation (elevating access for a specific case) within the zaaktype authorization framework.

#### Scenario: Case-specific delegation to another user
- **GIVEN** user `behandelaar-1` is handling case `zaak-456` in schema `vergunningen`
- **AND** `behandelaar-1` delegates `zaak-456` to `collega-2` by updating the object's `_owner` or `assignedTo` field
- **WHEN** `collega-2` accesses `zaak-456`
- **THEN** access MUST be granted via the owner-based access rule in `PermissionHandler::hasGroupPermission()` (where `$objectOwner === $userId`)
- **AND** `collega-2` MUST still require `read` permission on the zaaktype schema to list other objects

#### Scenario: Escalation to supervisor within same zaaktype
- **GIVEN** case `zaak-789` in schema `bezwaarschriften` needs supervisor review
- **AND** user `supervisor-1` is in group `bezwaarschriften-coordinator`
- **WHEN** `supervisor-1` accesses `zaak-789`
- **THEN** access MUST be granted via the coordinator group's schema-level authorization
- **AND** `supervisor-1` MUST be able to update the case status

#### Scenario: Cross-zaaktype escalation with temporary delegation
- **GIVEN** case `zaak-101` in schema `vergunningen` requires legal review
- **AND** user `jurist-1` is in group `juridisch-team` which has permissions only on `bezwaarschriften`
- **WHEN** admin grants `jurist-1` temporary individual access to schema `vergunningen` with `read` permission
- **THEN** `jurist-1` MUST be able to read objects in `vergunningen`
- **AND** the delegation MUST NOT affect other users in `juridisch-team`

### Requirement: ZGW Autorisaties API concepts MUST be mapped to OpenRegister primitives
The system MUST provide a clear mapping from ZGW Autorisaties API concepts (Applicatie, scope, maxVertrouwelijkheidaanduiding, heeftAlleAutorisaties) to OpenRegister's group-based RBAC model, ensuring compliance with VNG standards.

#### Scenario: ZGW Applicatie maps to Consumer + Nextcloud user
- **GIVEN** a ZGW Applicatie with `clientIds: ["zaaksysteem-1"]` and `heeftAlleAutorisaties: false`
- **WHEN** configured in OpenRegister
- **THEN** a Consumer entity MUST be created with `authorizationType: jwt` and `userId` pointing to a dedicated Nextcloud user
- **AND** the Nextcloud user's group memberships MUST define the Applicatie's effective scopes

#### Scenario: ZGW scope maps to Nextcloud group
- **GIVEN** the ZGW scopes: `zaken.lezen`, `zaken.aanmaken`, `zaken.bijwerken`, `zaken.verwijderen`
- **WHEN** configuring equivalent access in OpenRegister
- **THEN** Nextcloud groups SHALL be named to match the scope pattern (e.g., `zaken-lezen`, `zaken-aanmaken`)
- **AND** schema authorization blocks SHALL reference these groups: `{ "read": ["zaken-lezen"], "create": ["zaken-aanmaken"], "update": ["zaken-bijwerken"], "delete": ["zaken-verwijderen"] }`

#### Scenario: ZGW heeftAlleAutorisaties maps to admin group
- **GIVEN** a ZGW Applicatie with `heeftAlleAutorisaties: true`
- **WHEN** the corresponding Nextcloud user is added to the `admin` group
- **THEN** `PermissionHandler::hasPermission()` MUST return `true` immediately for all schemas and actions
- **AND** `MagicRbacHandler::applyRbacFilters()` MUST return without adding WHERE clauses

#### Scenario: ZGW maxVertrouwelijkheidaanduiding maps to conditional authorization
- **GIVEN** a ZGW Applicatie with autorisatie: `{ "zaaktype": "https://catalogi.nl/zaaktypen/uuid-1", "scopes": ["zaken.lezen"], "maxVertrouwelijkheidaanduiding": "zaakvertrouwelijk" }`
- **WHEN** configured in OpenRegister
- **THEN** the corresponding schema authorization MUST include a conditional match: `{ "group": "zaaksysteem-1-lezen", "match": { "vertrouwelijkheidaanduiding": { "$in": ["openbaar", "beperkt_openbaar", "intern", "zaakvertrouwelijk"] } } }`
- **AND** objects with vertrouwelijkheidaanduiding higher than `zaakvertrouwelijk` MUST be filtered at the database level

#### Scenario: ZGW Autorisaties API compatibility endpoint
- **GIVEN** the system exposes ZGW-compatible API endpoints via the zgw-api-mapping spec
- **WHEN** an external system queries the equivalent of `/autorisaties/v1/applicaties`
- **THEN** the response MUST be translatable to ZGW Autorisaties API format via Twig mapping templates
- **AND** each Applicatie's scopes MUST reflect the Nextcloud user's effective group-based permissions

### Requirement: Zaakcatalogus inheritance MUST be supported for zaaktype authorization defaults
When a register models a zaakcatalogus (catalog of zaaktypen), schemas (zaaktypen) within that catalogus SHALL be able to inherit default authorization rules from the catalogus level, with per-zaaktype overrides.

#### Scenario: Schema inherits default authorization from register
- **GIVEN** register `zaakregistratie` has a default authorization policy: `{ "read": ["alle-medewerkers"], "create": ["behandelaars"] }`
- **AND** schema `standaard-zaak` has no explicit authorization block
- **WHEN** a user in `alle-medewerkers` reads `standaard-zaak` objects
- **THEN** the system MUST fall back to the register's default authorization
- **AND** access MUST be granted

#### Scenario: Schema-level authorization overrides register defaults
- **GIVEN** register `zaakregistratie` has default authorization allowing `alle-medewerkers` to read
- **AND** schema `vertrouwelijk-zaaktype` has explicit authorization: `{ "read": ["directie"] }`
- **WHEN** a user in `alle-medewerkers` (but NOT `directie`) reads `vertrouwelijk-zaaktype`
- **THEN** the schema-level authorization MUST take precedence
- **AND** access MUST be denied with HTTP 403

#### Scenario: New zaaktype automatically inherits catalogus permissions
- **GIVEN** register `zaakregistratie` has default authorization rules
- **WHEN** a new schema is created in `zaakregistratie` without specifying authorization
- **THEN** the new schema MUST inherit the register's default authorization
- **AND** the inherited rules MUST be visible in the schema's authorization configuration

### Requirement: Multi-tenant zaaktype isolation MUST restrict cross-tenant visibility
In multi-tenant deployments, zaaktype authorization MUST be combined with organisation-level isolation so that users can only access objects belonging to their active organisation AND matching their zaaktype permissions.

#### Scenario: Same zaaktype, different organisations
- **GIVEN** schema `vergunningen` is used by organisations `gemeente-a` and `gemeente-b`
- **AND** user `behandelaar-a` (active org: `gemeente-a`) is in group `vergunningen-behandelaar`
- **AND** user `behandelaar-b` (active org: `gemeente-b`) is in group `vergunningen-behandelaar`
- **WHEN** `behandelaar-a` lists vergunningen
- **THEN** only vergunningen with `_organisation = gemeente-a` MUST be returned
- **AND** vergunningen from `gemeente-b` MUST NOT be visible

#### Scenario: Cross-tenant zaaktype access for SaaS administrators
- **GIVEN** user `saas-admin` is in the `admin` group
- **WHEN** `saas-admin` lists vergunningen
- **THEN** vergunningen from ALL organisations MUST be returned
- **AND** `MultiTenancyTrait` MUST be bypassed for admin users

#### Scenario: RBAC conditional rule with organisation scoping
- **GIVEN** schema `meldingen` has authorization: `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **AND** user `jan` is in `behandelaars` with active organisation `org-uuid-1`
- **WHEN** `jan` queries meldingen
- **THEN** `MagicRbacHandler::resolveDynamicValue('$organisation')` MUST return `org-uuid-1`
- **AND** the SQL condition MUST include `t._organisation = 'org-uuid-1'`
- **AND** multi-tenancy filtering and RBAC filtering MUST work together additively

#### Scenario: Organisation switch changes effective zaaktype access
- **GIVEN** user `jan` is a member of two organisations: `gemeente-a` and `gemeente-b`
- **AND** `jan` has `vergunningen-behandelaar` permissions in both
- **WHEN** `jan` switches active organisation from `gemeente-a` to `gemeente-b`
- **THEN** subsequent queries MUST filter on `_organisation = gemeente-b`
- **AND** no data from `gemeente-a` MUST be returned

### Requirement: Admin users MUST bypass all zaaktype authorization policies
Users with Nextcloud admin or OpenRegister admin role MUST have unrestricted access to all schemas and objects regardless of zaaktype-level authorization configuration.

#### Scenario: Admin bypasses zaaktype RBAC
- **GIVEN** schema `vertrouwelijk` with access restricted to `directie` group
- **WHEN** a Nextcloud admin user accesses `vertrouwelijk` objects
- **THEN** all CRUD operations MUST succeed regardless of group membership
- **AND** `PermissionHandler::hasPermission()` MUST detect `in_array('admin', $userGroups)` and return `true` immediately

#### Scenario: Admin sees all zaaktypen in schema listing
- **GIVEN** a register with 15 schemas, each with different authorization groups
- **WHEN** an admin user views the schema listing
- **THEN** all 15 schemas MUST be visible
- **AND** no RBAC filtering MUST be applied to the schema list

#### Scenario: Admin bypasses vertrouwelijkheidaanduiding restrictions
- **GIVEN** objects with `vertrouwelijkheidaanduiding: "zeer_geheim"`
- **WHEN** an admin user queries these objects
- **THEN** all objects MUST be returned regardless of confidentiality level
- **AND** no SQL WHERE clause for confidentiality MUST be added

### Requirement: VNG compliance testing MUST validate zaaktype authorization behavior
Automated tests MUST verify that the zaaktype-scoped RBAC implementation complies with ZGW Autorisaties API patterns, ensuring interoperability with other VNG-compliant systems.

#### Scenario: Test zaaktype-scoped read filtering
- **GIVEN** a test register with 3 schemas and 3 groups with varying permissions
- **WHEN** the VNG compliance test suite runs
- **THEN** each user MUST only see objects from schemas they are authorized for
- **AND** the test MUST verify HTTP 403 for unauthorized schema access
- **AND** the test MUST verify that list endpoints return empty results (not 403) when the user has `read` permission but no objects exist

#### Scenario: Test vertrouwelijkheidaanduiding filtering
- **GIVEN** objects at all 8 confidentiality levels in a single schema
- **AND** a user with maxVertrouwelijkheidaanduiding `intern`
- **WHEN** the compliance test runs
- **THEN** only objects with levels `openbaar`, `beperkt_openbaar`, and `intern` MUST be returned
- **AND** the test MUST verify exact count matches

#### Scenario: Test heeftAlleAutorisaties (admin bypass)
- **GIVEN** a user mapped to the `admin` group
- **WHEN** the compliance test accesses all schemas and all confidentiality levels
- **THEN** all requests MUST succeed with HTTP 200
- **AND** no authorization filtering MUST be applied

#### Scenario: Test cross-zaaktype isolation between API consumers
- **GIVEN** two API consumers (Consumer entities) with different zaaktype permissions
- **WHEN** each consumer authenticates and queries the same register
- **THEN** each MUST only receive objects from their authorized schemas
- **AND** neither consumer MUST be able to infer the existence of unauthorized schemas from API responses

## Analysis

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
