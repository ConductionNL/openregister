---
status: in-progress
---

# RBAC Scopes

**OpenSpec changes**
- `unify-rbac-condition-matching` (active) — collapses `PermissionHandler::evaluateMatchConditions` and `MagicRbacHandler`'s private PHP-side condition matcher onto the shared `ConditionMatcher` service, so schema-level RBAC honours the full operator set (`$eq/$ne/$gt/$gte/$lt/$lte/$in/$nin/$exists`) and dynamic variables (`$organisation/$userId/$now`) that the SQL and property layers already support. Fixes OpenCatalogi `PublicationsController::attachments` throwing on schemas with operator-based `public`-with-match rules.
- `or-delegated-identity` (active) — states the contract for the two scoped operations on ObjectService that shipped without one (`runAs()` narrowing to a named user, `runAsSystem()` elevating to a trusted userless principal), moves the narrowing one off `IUserSession::setUser()` so an acting identity is never written into the session, gives the elevating one a reachability boundary (code-initiated only), and records that delegation is never expressed as a permission verb (ADR-010, ADR-099).

- `or-delegation-grants` (active) — turns a DECLARED acting identity into an AUTHORIZED one: a delegation grant record with a consent lifecycle, refusal at save and at every fire when the author holds no grant for the user they named, and an `awaiting_consent` run state deduped on (principal, actingAs, scope) (ADR-099).

## Purpose

@e2e exclude backend RBAC OAS scope builder — covered by PHPUnit
Validate and extend OpenRegister's existing three-level RBAC system. The core RBAC is already implemented via PermissionHandler (schema-level), MagicRbacHandler (row-level SQL filtering), and PropertyRbacHandler (field-level). This spec documents the existing behavior as requirements and identifies extensions needed for scope management APIs, caching, and audit. Specifically, it maps the existing hierarchical RBAC model (register, schema, object, property) to standard OAuth2 scopes in the generated OpenAPI Specification, and validates that per-operation security requirements are correctly enforced so that API consumers can discover and request the precise group-based permissions they need. The scope system bridges Nextcloud's native group management with standardised OAuth2/OAS security semantics, enabling external API consumers, ZGW-compliant systems, and MCP clients to understand and negotiate access programmatically.

**Source**: Core OpenRegister capability; 67% of tenders require SSO/identity integration; 86% require RBAC per zaaktype; ZGW Autorisaties API compliance.

## Relationship to Existing Implementation
This spec primarily documents and validates existing functionality, with targeted extensions:

- **Schema-level RBAC (fully implemented)**: `PermissionHandler` with `hasPermission()`, `checkPermission()`, `hasGroupPermission()`, and `getAuthorizedGroups()`. Conditional match evaluation is delegated to the shared `ConditionMatcher` service (previously a local `evaluateMatchConditions()` helper with equality-only / `$organisation`-only support, now removed in favour of the canonical matcher).
- **Property-level RBAC (fully implemented)**: `PropertyRbacHandler` with `canReadProperty()`, `canUpdateProperty()`, `filterReadableProperties()`, and `getUnauthorizedProperties()` with conditional rule evaluation via `ConditionMatcher`.
- **Database-level RBAC (fully implemented)**: `MagicRbacHandler` with `applyRbacFilters()` (QueryBuilder), `buildRbacConditionsSql()` (raw SQL for UNION), dynamic variable resolution (`$organisation`, `$userId`, `$now`), and full operator support.
- **OAS scope generation (fully implemented)**: `OasService::extractSchemaGroups()` extracts groups from authorization blocks, `getScopeDescription()` generates descriptions, `applyRbacToOperation()` adds per-operation security blocks.
- **Scope caching (fully implemented)**: `MagicRbacHandler.$cachedActiveOrg`, `ConditionMatcher.$cachedActiveOrg`, `OasService.$schemaRbacMap`.
- **Consumer identity mapping (fully implemented)**: `Consumer` entity with `userId` field, `AuthorizationService` resolving all auth methods to Nextcloud users.
- **What this spec adds as extensions**: Register-level default authorization cascade, permission matrix UI for administrators, scope migration tooling for group renames, and explicit RBAC policy change audit logging.

## Requirements

### Requirement: Scope Model Hierarchy (Register > Schema > Object > Property)
The RBAC scope model SHALL follow a four-level hierarchy: register-level scopes govern access to an entire register and serve as defaults for schemas without their own authorization, schema-level scopes control CRUD operations per schema (zaaktype/objecttype), object-level scopes apply to individual records via conditional matching, and property-level scopes restrict visibility and mutability of specific fields. Each level MUST be independently configurable via the `authorization` JSON structure. Register-level authorization SHALL cascade to schemas that do not define their own authorization block. Named roles defined at register level SHALL be expandable in authorization blocks at any level.

#### Scenario: Schema-level authorization defines CRUD scopes
- **GIVEN** schema `bezwaarschriften` has authorization: `{ "read": ["juridisch-team"], "create": ["juridisch-team"], "update": ["juridisch-team"], "delete": ["admin"] }`
- **WHEN** OAS is generated for the register containing this schema
- **THEN** the scopes `juridisch-team` and `admin` MUST appear in `components.securitySchemes.oauth2.flows.authorizationCode.scopes`
- **AND** the GET endpoints MUST list `juridisch-team` in their `security` requirements
- **AND** the DELETE endpoint MUST list `admin` in its `security` requirements

#### Scenario: Register authorization cascades to OAS generation for unconfigured schemas
- **GIVEN** a register has authorization `{ "read": ["medewerkers"], "create": ["medewerkers"] }` AND a schema in that register has no authorization block
- **WHEN** OAS is generated for that register
- **THEN** the schema's endpoints SHALL use the register's authorization for scope generation
- **AND** the scopes `medewerkers` and `admin` SHALL appear in the OAS security definitions

#### Scenario: Property-level authorization contributes additional scopes
- **GIVEN** schema `inwoners` has property `bsn` with authorization: `{ "read": [{ "group": "bsn-geautoriseerd" }], "update": [{ "group": "bsn-geautoriseerd" }] }`
- **AND** schema-level authorization allows group `kcc-team` to read
- **WHEN** `OasService::extractSchemaGroups()` processes this schema
- **THEN** `readGroups` MUST include both `kcc-team` and `bsn-geautoriseerd`
- **AND** `updateGroups` MUST include `bsn-geautoriseerd`
- **AND** both groups MUST appear as OAuth2 scopes in the generated OAS

#### Scenario: Object-level conditional scopes produce group entries without match details
- **GIVEN** schema `meldingen` has authorization: `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **WHEN** `OasService::extractGroupFromRule()` processes this conditional rule
- **THEN** the extracted group MUST be `behandelaars` (the `match` conditions are not reflected in the OAS scope, only in runtime enforcement)
- **AND** `behandelaars` MUST appear as an OAuth2 scope with description `Access for behandelaars group`

#### Scenario: Schema with no authorization produces no extra scopes
- **GIVEN** schema `tags` has no `authorization` block (null or empty) AND its parent register also has no authorization
- **WHEN** `OasService::extractSchemaGroups()` processes this schema
- **THEN** `createGroups`, `readGroups`, `updateGroups`, and `deleteGroups` MUST all be empty arrays
- **AND** the schema's endpoints MUST NOT have operation-level `security` overrides

#### Scenario: Scope hierarchy is flattened for OAS (no nesting)
- **GIVEN** a register with 3 schemas, each having different group rules at schema-level and property-level
- **WHEN** OAS is generated
- **THEN** all unique group names across all schemas and properties MUST be collected into a single flat `scopes` object in `components.securitySchemes.oauth2.flows.authorizationCode.scopes`
- **AND** duplicate group names MUST be deduplicated (each group appears only once)

### Requirement: Register-level authorization cascade
The system SHALL support authorization configuration on Register entities. When a Schema has no `authorization` block (null or empty), the system SHALL fall back to the parent Register's `authorization` block for permission evaluation. If neither Register nor Schema has authorization configured, all authenticated users SHALL have full CRUD access (preserving current behavior).

#### Scenario: Schema without authorization inherits register authorization
- **WHEN** a schema has no `authorization` block AND its parent register has `authorization`: `{ "read": ["public"], "create": ["behandelaars"], "update": ["behandelaars"], "delete": ["admin"] }`
- **THEN** permission checks on the schema SHALL use the register's authorization rules
- **AND** a user in group `behandelaars` SHALL be able to create objects in that schema
- **AND** an unauthenticated user SHALL be able to read objects in that schema

#### Scenario: Schema authorization overrides register authorization
- **WHEN** a schema has its own `authorization` block AND the parent register also has authorization
- **THEN** the schema's authorization SHALL be used exclusively
- **AND** the register's authorization SHALL NOT be merged or combined with the schema's

#### Scenario: Neither schema nor register has authorization
- **WHEN** a schema has no `authorization` AND its parent register has no `authorization`
- **THEN** all authenticated users SHALL have full CRUD access (current behavior preserved)
- **AND** unauthenticated users SHALL NOT have access unless `public` group is explicitly configured

### Requirement: Named role definitions on registers
The system SHALL support named role definitions stored in the Register entity's `configuration` field under a `roles` key. Each role SHALL have a `name`, `description`, and `actions` array listing permitted CRUD actions. Role names MAY be used in authorization blocks as shorthand for action groups.

#### Scenario: Define roles on a register
- **WHEN** a register's configuration contains `{ "roles": [{ "name": "viewer", "description": "Read-only access", "actions": ["read"] }, { "name": "editor", "description": "Full edit access", "actions": ["read", "create", "update"] }] }`
- **THEN** the roles SHALL be retrievable via the Register API
- **AND** role names SHALL be usable in authorization blocks

#### Scenario: Role-based authorization in schema
- **WHEN** a schema has authorization: `{ "roles": { "viewer": ["public"], "editor": ["behandelaars"] } }`
- **THEN** group `public` SHALL have `read` permission (from viewer role)
- **AND** group `behandelaars` SHALL have `read`, `create`, and `update` permissions (from editor role)
- **AND** permissions not covered by any assigned role SHALL be denied

#### Scenario: Role expansion coexists with direct action authorization
- **WHEN** a schema has both `roles` and direct action entries: `{ "roles": { "viewer": ["public"] }, "read": ["extra-groep"] }`
- **THEN** group `public` SHALL have `read` permission (from role)
- **AND** group `extra-groep` SHALL also have `read` permission (from direct entry)
- **AND** both formats SHALL be evaluated together

### Requirement: Delegation via manage action
The system SHALL support a `manage` action type in authorization blocks. Users with `manage` permission on a register SHALL be able to edit authorization configuration for schemas within that register. Users with `manage` permission on a schema SHALL be able to assign groups to existing roles on that schema.

#### Scenario: Register manager edits schema authorization
- **WHEN** user is in group `register-beheerders` AND the register authorization grants `manage` to `register-beheerders`
- **THEN** the user SHALL be able to update the `authorization` field on any schema within that register
- **AND** the user SHALL NOT need to be a Nextcloud admin

#### Scenario: Schema manager assigns groups to roles
- **WHEN** user has `manage` permission on a schema
- **THEN** the user SHALL be able to modify which groups are assigned to roles in that schema's authorization
- **AND** the user SHALL NOT be able to create new roles (roles are defined at register level)

#### Scenario: User without manage permission cannot edit authorization
- **WHEN** user does NOT have `manage` permission on the register or schema
- **THEN** attempts to update the `authorization` field SHALL be rejected with a 403 response
- **AND** the rejection SHALL include a descriptive error message

### Requirement: Register authorization cache
The system SHALL cache register authorization lookups within a single request to avoid repeated database queries when checking permissions for multiple schemas in the same register.

#### Scenario: Multiple schema checks within same register use cached authorization
- **WHEN** permission checks are performed for 10 schemas in the same register within a single API request
- **THEN** the register SHALL be loaded from the database at most once
- **AND** subsequent checks SHALL use the cached authorization data

### Requirement: Permission Types (read, create, update, delete, list)
The system MUST support six distinct permission types in authorization rules: `read` (get a single object), `create` (post a new object), `update` (put/patch an existing object), `delete` (remove an object), `list` (query a collection, currently treated as `read`), and `manage` (edit authorization configuration). The `manage` type controls delegation of authorization management. Each permission type except `manage` MUST map to the corresponding HTTP method in the generated OAS security requirements. The `manage` type SHALL be enforced only on authorization-editing API endpoints.

#### Scenario: GET operations use read groups
- **GIVEN** a schema where read authorization references groups `public` and `behandelaars`
- **WHEN** OAS is generated for the GET collection and GET single-item endpoints
- **THEN** both operations MUST have a `security` array including `{ "oauth2": ["public", "behandelaars", "admin"] }`
- **AND** both MUST include `{ "basicAuth": [] }` as an alternative authentication method

#### Scenario: POST operations use create groups
- **GIVEN** a schema where create authorization references group `intake-medewerkers`
- **WHEN** OAS is generated for the POST endpoint
- **THEN** the operation `security` MUST include `{ "oauth2": ["intake-medewerkers", "admin"] }`
- **AND** the `admin` group MUST always be included even if not explicitly listed in the schema authorization

#### Scenario: PUT/PATCH operations use update groups
- **GIVEN** a schema where update authorization references groups `behandelaars` and `redacteuren`
- **WHEN** OAS is generated for the PUT endpoint
- **THEN** the operation `security` MUST include `{ "oauth2": ["behandelaars", "redacteuren", "admin"] }`

#### Scenario: DELETE operations use delete groups (falling back to update groups)
- **GIVEN** a schema with explicit delete authorization: `{ "delete": ["admin"] }`
- **WHEN** OAS is generated for the DELETE endpoint
- **THEN** the operation `security` MUST include `{ "oauth2": ["admin"] }`

#### Scenario: Manage permission controls authorization editing
- **WHEN** a user with `manage` permission attempts to update a schema's authorization
- **THEN** the update SHALL be permitted
- **AND** the `manage` permission SHALL NOT grant any CRUD access to objects

#### Scenario: Manage permission in OAS generation
- **WHEN** OAS is generated for a schema with `manage` in its authorization
- **THEN** the `manage` groups SHALL NOT appear in CRUD endpoint security blocks
- **AND** the `manage` groups SHALL appear only on authorization-management endpoints if they are defined in the OAS

#### Scenario: List and single-get share read permission
- **GIVEN** schema `producten` with `read: ["public"]`
- **WHEN** a user queries GET `/api/objects/{register}/{schema}` (list) or GET `/api/objects/{register}/{schema}/{id}` (single)
- **THEN** both endpoints MUST enforce the same `read` authorization groups
- **AND** `MagicRbacHandler::applyRbacFilters()` MUST be called with action `read` for list queries
- **AND** `PermissionHandler::hasPermission()` MUST be called with action `read` for single-get operations

### Requirement: Role Definitions and Hierarchy
The system MUST enforce a clear role hierarchy: `admin` > object owner > named Nextcloud groups > `authenticated` pseudo-group > `public` pseudo-group. Each level in the hierarchy MUST be consistently evaluated across `PermissionHandler`, `PropertyRbacHandler`, `MagicRbacHandler`, and `OasService`.

#### Scenario: Admin group always has full access and is always included in scopes
- **GIVEN** a register where schemas do NOT explicitly mention `admin` in their authorization rules
- **WHEN** OAS is generated
- **THEN** `admin` MUST still appear in `components.securitySchemes.oauth2.flows.authorizationCode.scopes` with description `Full administrative access`
- **AND** `admin` MUST be included in the OAuth2 scopes for POST, PUT, and DELETE operation security requirements
- **AND** at runtime, `PermissionHandler::hasPermission()` MUST return `true` immediately when `in_array('admin', $userGroups)` is true

#### Scenario: Object owner bypasses schema-level RBAC
- **GIVEN** user `jan` created object `melding-1` (owner = `jan`)
- **AND** schema `meldingen` restricts update to group `beheerders`
- **AND** `jan` is NOT in group `beheerders`
- **WHEN** `jan` updates `melding-1`
- **THEN** `PermissionHandler::hasGroupPermission()` MUST return `true` because `$objectOwner === $userId`
- **AND** owner bypass is NOT reflected in OAS scopes (it is a runtime policy, not an API scope)

#### Scenario: Public pseudo-group grants unauthenticated access
- **GIVEN** schema `producten` has `read: ["public"]`
- **WHEN** an unauthenticated HTTP request reads producten objects
- **THEN** `PermissionHandler::hasPermission()` MUST detect `$user === null` and check the `public` group
- **AND** `MagicRbacHandler::processSimpleRule('public')` MUST return `true`
- **AND** the OAS scope for `public` MUST have description `Public (unauthenticated) access`

#### Scenario: Authenticated pseudo-group grants access to any logged-in user
- **GIVEN** schema `feedback` has authorization: `{ "create": ["authenticated"] }`
- **WHEN** any logged-in Nextcloud user creates a feedback object
- **THEN** `MagicRbacHandler::processSimpleRule('authenticated')` MUST return `true` when `$userId !== null`
- **AND** `authenticated` MUST appear as an OAuth2 scope in the OAS with description `Access for authenticated group`

#### Scenario: Logged-in users inherit public permissions
- **GIVEN** schema `producten` has `read: ["public"]`
- **AND** user `jan` is logged in but not in any special group
- **WHEN** `jan` reads producten
- **THEN** `PermissionHandler::hasPermission()` MUST check the `public` group as a fallback after evaluating the user's actual groups
- **AND** access MUST be granted because logged-in users have at least public-level access

### Requirement: Scope Inheritance (Register Permissions Cascade to Schemas)
When a register defines default authorization rules, those defaults SHALL cascade to all schemas that do not define their own authorization. Schema-level authorization, when present, MUST override the register defaults entirely (most-specific-wins principle).

#### Scenario: Schema without authorization inherits register defaults
- **GIVEN** register `catalogi` has a default authorization: `{ "read": ["public"], "create": ["beheerders"], "update": ["beheerders"], "delete": ["admin"] }`
- **AND** schema `producten` has NO authorization block
- **WHEN** `PermissionHandler::hasPermission()` evaluates access for `producten`
- **THEN** the register's default authorization SHOULD be used as the effective authorization
- **AND** the OAS endpoints for `producten` SHOULD reflect the register's default groups

#### Scenario: Schema with explicit authorization overrides register defaults
- **GIVEN** register `catalogi` has default authorization allowing `public` read
- **AND** schema `interne-notities` has explicit authorization: `{ "read": ["redacteuren"] }`
- **WHEN** OAS is generated and RBAC is enforced
- **THEN** `interne-notities` MUST use its own authorization rules, NOT the register defaults
- **AND** only `redacteuren` (and `admin`) MUST appear in the read scopes for `interne-notities` endpoints

#### Scenario: Mixed register with inherited and explicit schemas
- **GIVEN** register `catalogi` with default auth and 3 schemas: `producten` (no auth), `diensten` (no auth), `interne-notities` (explicit auth)
- **WHEN** OAS is generated
- **THEN** `producten` and `diensten` operations MUST use register-level scopes
- **AND** `interne-notities` operations MUST use its own explicit scopes
- **AND** all unique groups from both sources MUST appear in the global OAuth2 scopes

### Requirement: Conditional Scopes with Dynamic Variables
Authorization rules MUST support conditional matching where access depends on both group membership AND runtime conditions evaluated against the object's data. The system MUST resolve dynamic variables `$organisation`, `$userId`/`$user`, and `$now` at query time via `MagicRbacHandler::resolveDynamicValue()` and `ConditionMatcher::resolveDynamicValue()`.

#### Scenario: Organisation-scoped access via $organisation variable
- **GIVEN** schema `zaken` has authorization: `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **AND** user `jan` is in group `behandelaars` with active organisation UUID `abc-123`
- **WHEN** `jan` queries zaken
- **THEN** `MagicRbacHandler::resolveDynamicValue('$organisation')` MUST return `abc-123` via `OrganisationService::getActiveOrganisation()`
- **AND** the SQL condition MUST be `t._organisation = 'abc-123'`
- **AND** the OAS scope MUST show `behandelaars` (the conditional match is enforced at runtime, not in the OAS)

#### Scenario: User-scoped access via $userId variable
- **GIVEN** schema `taken` has authorization: `{ "read": [{ "group": "medewerkers", "match": { "assignedTo": "$userId" } }] }`
- **AND** user `jan` (UID: `jan`) is in group `medewerkers`
- **WHEN** `jan` queries taken
- **THEN** `MagicRbacHandler::resolveDynamicValue('$userId')` MUST return `jan`
- **AND** only taken where `assigned_to = 'jan'` MUST be returned
- **AND** the OAS scope MUST list `medewerkers` without exposing the `$userId` match

#### Scenario: Time-based conditional access via $now variable
- **GIVEN** schema `publicaties` has authorization: `{ "read": [{ "group": "public", "match": { "publishDate": { "$lte": "$now" } } }] }`
- **WHEN** an unauthenticated user queries publicaties
- **THEN** `MagicRbacHandler::resolveDynamicValue('$now')` MUST return the current datetime in `Y-m-d H:i:s` format
- **AND** only publicaties with `publish_date <= NOW()` MUST be returned
- **AND** the OAS scope MUST list `public` for the GET operation

#### Scenario: Multiple match conditions require AND logic
- **GIVEN** a rule: `{ "group": "behandelaars", "match": { "_organisation": "$organisation", "status": "open" } }`
- **WHEN** a user in `behandelaars` queries objects
- **THEN** `MagicRbacHandler::buildMatchConditions()` MUST combine both conditions with SQL AND logic
- **AND** both `_organisation` and `status` conditions MUST be satisfied for an object to be returned

#### Scenario: Conditional rule on create skips organisation matching
- **GIVEN** property `interneAantekening` has authorization: `{ "update": [{ "group": "public", "match": { "_organisation": "$organisation" } }] }`
- **WHEN** a user creates a new object (no existing object data yet)
- **THEN** `ConditionMatcher::filterOrganisationMatchForCreate()` MUST remove `_organisation` from match conditions
- **AND** if the remaining match is empty, access MUST be granted

### Requirement: Nextcloud Group Mapping
Every RBAC scope MUST map directly to a Nextcloud group managed via `OCP\IGroupManager`. The system SHALL NOT maintain a separate group/role database. Group membership changes in Nextcloud (including LDAP/SAML/OIDC-synced groups) MUST take effect immediately for subsequent RBAC evaluations without requiring any OpenRegister-specific synchronisation.

#### Scenario: Nextcloud group becomes an OAuth2 scope
- **GIVEN** Nextcloud has groups: `admin`, `kcc-team`, `juridisch-team`, `redacteuren`
- **AND** schema `bezwaarschriften` uses `juridisch-team` in its authorization
- **WHEN** OAS is generated
- **THEN** `juridisch-team` MUST appear in the OAuth2 scopes
- **AND** the scope description MUST be `Access for juridisch-team group`

#### Scenario: LDAP-synced group is immediately usable in RBAC
- **GIVEN** Nextcloud syncs group `vth-behandelaars` from LDAP
- **AND** user `jan` is added to `vth-behandelaars` in LDAP
- **WHEN** `jan` authenticates and `IGroupManager::getUserGroupIds()` is called
- **THEN** `vth-behandelaars` MUST be in the returned group list
- **AND** `PermissionHandler::hasPermission()` MUST grant access to schemas authorising `vth-behandelaars`

#### Scenario: SAML group assertion maps to RBAC scope
- **GIVEN** Nextcloud's `user_saml` app maps SAML group assertion `urn:gov:team:juridisch` to Nextcloud group `juridisch-team`
- **WHEN** user authenticates via SAML and accesses OpenRegister
- **THEN** the user's group memberships (including `juridisch-team`) MUST be used for all RBAC checks
- **AND** no OpenRegister-specific group synchronisation MUST be required

### Requirement: Scope Resolution Algorithm (Most Specific Wins)
When multiple authorization levels apply to the same request, the system MUST resolve them using a "most specific wins" algorithm: property-level authorization overrides schema-level for that property, schema-level overrides register-level, and conditional rules (with `match`) are more specific than unconditional rules. The `admin` group and object ownership bypass all resolution.

#### Scenario: Property-level auth restricts access within an otherwise-permitted schema
- **GIVEN** schema `dossiers` allows group `behandelaars` to read (schema-level)
- **AND** property `interneAantekening` restricts read to group `redacteuren` (property-level)
- **AND** user `jan` is in `behandelaars` but NOT in `redacteuren`
- **WHEN** `jan` reads a dossier object
- **THEN** schema-level check via `PermissionHandler::hasPermission()` MUST pass
- **AND** `PropertyRbacHandler::filterReadableProperties()` MUST remove `interneAantekening` from the response
- **AND** all other fields MUST still be returned

#### Scenario: Unconditional group rule grants broader access than conditional rule
- **GIVEN** schema `meldingen` has authorization: `{ "read": ["public", { "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **WHEN** an unauthenticated user queries meldingen
- **THEN** `MagicRbacHandler::processSimpleRule('public')` MUST return `true` (unconditional access)
- **AND** the conditional `behandelaars` rule MUST NOT restrict the public access

#### Scenario: Admin bypasses all resolution levels
- **GIVEN** a user in the `admin` group
- **WHEN** they access any schema, property, or object
- **THEN** `PermissionHandler::hasPermission()` MUST return `true` immediately
- **AND** `PropertyRbacHandler::isAdmin()` MUST return `true`, skipping all property filtering
- **AND** `MagicRbacHandler::applyRbacFilters()` MUST return without adding WHERE clauses

### Requirement: OAS Scope Generation from RBAC Configuration
`OasService` MUST dynamically generate OAuth2 scopes from the RBAC configuration of all schemas in a register. The `BaseOas.json` template MUST NOT contain hardcoded `read`/`write` scopes; scopes SHALL be populated entirely from schema and property authorization rules at generation time.

#### Scenario: Extract and deduplicate groups across all schemas
- **GIVEN** register `zaken` with 3 schemas, each referencing overlapping groups
- **WHEN** `OasService::createOas()` iterates schemas and calls `extractSchemaGroups()` for each
- **THEN** `$allGroups` MUST be the union of all `createGroups`, `readGroups`, `updateGroups`, and `deleteGroups` across schemas
- **AND** `admin` MUST always be appended to `$allGroups`
- **AND** `array_unique()` MUST deduplicate the combined list

#### Scenario: Scope descriptions follow naming conventions
- **GIVEN** extracted groups: `admin`, `public`, `behandelaars`, `juridisch-team`
- **WHEN** `OasService::getScopeDescription()` generates descriptions
- **THEN** `admin` MUST have description `Full administrative access`
- **AND** `public` MUST have description `Public (unauthenticated) access`
- **AND** `behandelaars` MUST have description `Access for behandelaars group`
- **AND** `juridisch-team` MUST have description `Access for juridisch-team group`

#### Scenario: Per-operation security requirements applied via applyRbacToOperation
- **GIVEN** schema `meldingen` has `readGroups: ["public", "behandelaars"]` and `updateGroups: ["behandelaars"]`
- **WHEN** `OasService::addCrudPaths()` generates path operations
- **THEN** the GET operation MUST have `security: [{ "oauth2": ["admin", "public", "behandelaars"] }, { "basicAuth": [] }]`
- **AND** the PUT operation MUST have `security: [{ "oauth2": ["admin", "behandelaars"] }, { "basicAuth": [] }]`
- **AND** the 403 Forbidden response MUST be added to operations with RBAC restrictions

#### Scenario: BaseOas.json has empty scopes placeholder
- **GIVEN** the base template file `BaseOas.json`
- **WHEN** it is loaded before RBAC processing
- **THEN** `components.securitySchemes.oauth2.flows.authorizationCode.scopes` MUST be an empty object `{}`
- **AND** the dynamic scope generation in `createOas()` MUST populate it based on schema RBAC

#### Scenario: Register with no RBAC still has valid security schemes
- **GIVEN** a register where no schemas have authorization blocks
- **WHEN** OAS is generated
- **THEN** `components.securitySchemes` MUST still contain `basicAuth` and `oauth2`
- **AND** the OAuth2 scopes object MUST contain at least `{ "admin": "Full administrative access" }`

### Requirement: Scope Caching for Performance
The system MUST cache frequently evaluated permission data to avoid repeated database and LDAP lookups within the same request lifecycle. Active organisation UUID, user group memberships, and schema authorization configurations SHOULD be resolved once per request and reused.

#### Scenario: MagicRbacHandler caches active organisation UUID
- **GIVEN** user `jan` with active organisation `org-uuid-1`
- **WHEN** `MagicRbacHandler::getActiveOrganisationUuid()` is called multiple times within one request (e.g., across multiple schema queries)
- **THEN** the first call MUST resolve via `OrganisationService::getActiveOrganisation()` and store in `$this->cachedActiveOrg`
- **AND** subsequent calls MUST return the cached value without calling OrganisationService again

#### Scenario: ConditionMatcher caches active organisation UUID independently
- **GIVEN** `ConditionMatcher` is used for property-level RBAC within the same request
- **WHEN** `ConditionMatcher::getActiveOrganisationUuid()` is called
- **THEN** it MUST cache the result in its own `$this->cachedActiveOrg` field
- **AND** subsequent calls within the same request MUST return the cached value

#### Scenario: RBAC at SQL level avoids post-fetch filtering
- **GIVEN** schema `meldingen` with conditional RBAC rules
- **WHEN** `MagicRbacHandler::applyRbacFilters()` adds WHERE clauses to the QueryBuilder
- **THEN** filtering MUST happen at the database query level
- **AND** unauthorised objects MUST never be loaded into PHP memory
- **AND** pagination counts MUST reflect only the accessible result set

#### Scenario: OAS generation caches extracted groups per schema
- **GIVEN** `OasService::createOas()` processes 10 schemas
- **WHEN** `extractSchemaGroups()` is called for each schema
- **THEN** the results MUST be stored in `$schemaRbacMap` keyed by schema ID
- **AND** each schema's RBAC groups MUST be reused when generating path operations without re-extraction

### Requirement: Multi-Tenancy Integration with Scopes
RBAC scopes MUST integrate with the multi-tenancy system so that organisation-based data isolation works alongside group-based access control. When RBAC conditional rules match on non-`_organisation` fields, they MUST be able to bypass the default multi-tenancy filter, as determined by `MagicRbacHandler::hasConditionalRulesBypassingMultitenancy()`.

#### Scenario: Organisation filtering combined with RBAC
- **GIVEN** user `jan` has active organisation `org-uuid-1` and is in group `behandelaars`
- **AND** schema `meldingen` has RBAC: `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **WHEN** `jan` lists meldingen
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST add `t._organisation = 'org-uuid-1'` as a SQL condition
- **AND** `MultiTenancyTrait` filtering MUST be coordinated to avoid double-filtering

#### Scenario: Conditional RBAC bypasses multi-tenancy for cross-org field matching
- **GIVEN** schema `catalogi` has RBAC: `{ "read": [{ "group": "catalogus-beheerders", "match": { "aanbieder": "$organisation" } }] }`
- **AND** user `jan` is in `catalogus-beheerders` with active organisation `org-1`
- **WHEN** `MagicRbacHandler::hasConditionalRulesBypassingMultitenancy()` evaluates the rules
- **THEN** it MUST detect `aanbieder` as a non-`_organisation` match field
- **AND** multi-tenancy filtering MUST be bypassed, allowing RBAC's `aanbieder = 'org-1'` condition to handle filtering instead

#### Scenario: Admin users see all organisations
- **GIVEN** a user in the `admin` group
- **WHEN** they query any register
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST return without filtering (admin bypass)
- **AND** multi-tenancy filtering MUST also be bypassed for admin users

### Requirement: Scope Audit (Who Has Access to What)
The system MUST provide mechanisms to determine which groups/users have access to which schemas and properties, supporting compliance auditing and access reviews.

#### Scenario: Extract authorised groups per schema for audit reporting
- **GIVEN** a register with 5 schemas, each with different authorization configurations
- **WHEN** an administrator queries the effective permissions via `PermissionHandler::getAuthorizedGroups()` for each schema and action
- **THEN** the system MUST return the list of group IDs that have permission for each CRUD action
- **AND** an empty array MUST indicate "all groups have permission" (no authorization configured)

#### Scenario: OAS specification serves as a machine-readable access audit
- **GIVEN** the generated OAS for a register
- **WHEN** an auditor examines `components.securitySchemes.oauth2.flows.authorizationCode.scopes`
- **THEN** all groups that have any access to any endpoint MUST be listed
- **AND** each operation's `security` block MUST show exactly which groups can access that endpoint
- **AND** the 403 response in RBAC-protected operations MUST indicate that authorization is enforced

#### Scenario: Property-level audit via schema inspection
- **GIVEN** schema `inwoners` with properties `naam` (no auth), `bsn` (auth: `bsn-geautoriseerd`), `adres` (auth: `adres-geautoriseerd`)
- **WHEN** `Schema::getPropertiesWithAuthorization()` is called
- **THEN** it MUST return `{ "bsn": { "read": [...], "update": [...] }, "adres": { "read": [...], "update": [...] } }`
- **AND** `naam` MUST NOT appear in the result (it has no property-level authorization)

#### Scenario: Security event logging for access decisions
- **GIVEN** `SecurityService` logs authentication events (success, failure, lockout)
- **WHEN** RBAC denies access to a schema or property
- **THEN** `PermissionHandler` MUST log a warning with the user, schema, action, and denial reason
- **AND** the log entry MUST be queryable for compliance reviews

### Requirement: Default Scopes for New Registers and Schemas
When a new register or schema is created without explicit authorization configuration, the system MUST apply sensible defaults that ensure security without blocking legitimate access.

#### Scenario: New schema without authorization allows all authenticated access
- **GIVEN** a user creates a new schema `notities` without setting any `authorization` block
- **WHEN** `PermissionHandler::hasPermission()` evaluates access for `notities`
- **THEN** `$authorization` MUST be `null` or empty
- **AND** `hasGroupPermission()` MUST return `true` (no authorization = open access to all)
- **AND** the generated OAS MUST NOT have per-operation `security` overrides for `notities` endpoints

#### Scenario: New register inherits no authorization defaults
- **GIVEN** a new register is created
- **WHEN** schemas are added to the register without explicit authorization
- **THEN** each schema MUST independently default to open access (no inherited restrictions)
- **AND** administrators SHOULD be prompted or advised to configure authorization before production use

#### Scenario: Adding authorization to an existing open schema
- **GIVEN** schema `notities` currently has no authorization (open access)
- **WHEN** an administrator adds `{ "read": ["medewerkers"], "create": ["medewerkers"] }`
- **THEN** the new authorization MUST take effect on the next request (after OPcache refresh)
- **AND** previously-open endpoints MUST now enforce the new group requirements
- **AND** the OAS MUST be regenerated to include the new scopes

### Requirement: Scope Migration on Schema Changes
When a schema's authorization configuration changes (groups added, removed, or renamed), the system MUST handle the transition gracefully without orphaning existing objects or breaking active API sessions.

#### Scenario: Adding a new group to a schema's authorization
- **GIVEN** schema `meldingen` currently has `read: ["behandelaars"]`
- **WHEN** `kcc-team` is added: `read: ["behandelaars", "kcc-team"]`
- **THEN** users in `kcc-team` MUST gain immediate read access to meldingen
- **AND** existing `behandelaars` access MUST remain unchanged
- **AND** the next OAS generation MUST include `kcc-team` in the scopes

#### Scenario: Removing a group from a schema's authorization
- **GIVEN** schema `meldingen` has `update: ["behandelaars", "kcc-team"]`
- **WHEN** `kcc-team` is removed: `update: ["behandelaars"]`
- **THEN** users in `kcc-team` (but not `behandelaars`) MUST lose update access immediately
- **AND** the next OAS generation MUST no longer include `kcc-team` in update scopes (unless used by other schemas)

#### Scenario: Renaming a Nextcloud group used in authorization
- **GIVEN** Nextcloud group `vth-team` is used in schema authorization
- **WHEN** the administrator renames the group to `vergunningen-team` in Nextcloud
- **THEN** the schema authorization JSON MUST be manually updated to reference `vergunningen-team`
- **AND** until updated, users in the renamed group MUST lose access (the old group name no longer matches)

### Requirement: API Scope Enforcement Across All Access Methods
RBAC scopes MUST be enforced consistently across all access methods: REST API, GraphQL, MCP tools, search, and data export. The enforcement MUST use the same `PermissionHandler`, `PropertyRbacHandler`, and `MagicRbacHandler` for all methods.

#### Scenario: REST API enforces scopes via PermissionHandler
- **GIVEN** user `medewerker-1` in group `kcc-team`
- **AND** schema `bezwaarschriften` allows only `juridisch-team`
- **WHEN** `medewerker-1` sends GET `/api/objects/{register}/bezwaarschriften`
- **THEN** `PermissionHandler::checkPermission()` MUST throw an Exception
- **AND** the HTTP response MUST be 403 Forbidden

#### Scenario: GraphQL enforces scopes identically to REST
- **GIVEN** the same schema and user as above
- **WHEN** `medewerker-1` sends a GraphQL query for `bezwaarschriften`
- **THEN** `PermissionHandler::checkPermission()` MUST be called with action `read`
- **AND** the same authorization rules MUST be evaluated

#### Scenario: Cross-schema GraphQL queries enforce per-schema scopes
- **GIVEN** user can read `orders` (schema-level) but NOT `klanten` (schema-level)
- **WHEN** they query `order { title klant { naam } }` via GraphQL
- **THEN** `klant` MUST return `null` with a partial error at `["order", "klant"]` with `extensions.code: "FORBIDDEN"`
- **AND** the `title` field MUST still return data (partial success)

#### Scenario: MCP tools enforce scopes via Nextcloud auth
- **GIVEN** an MCP client authenticated via Basic Auth as user `api-user`
- **AND** `api-user` is in group `kcc-team` but not `juridisch-team`
- **WHEN** the MCP client invokes `mcp__openregister__objects` with action `list` on schema `bezwaarschriften`
- **THEN** RBAC MUST be enforced using `api-user`'s group memberships
- **AND** access to `bezwaarschriften` MUST be denied if `kcc-team` is not in the authorization rules

#### Scenario: Search results respect RBAC scopes
- **GIVEN** user `jan` in group `sociale-zaken`
- **AND** schema `meldingen` has conditional RBAC matching on `_organisation`
- **WHEN** `jan` searches for meldingen via the search API
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST filter results at the query level
- **AND** facet counts MUST reflect only the accessible objects

### Requirement: Frontend Scope Checking
The frontend MUST be able to determine the current user's effective permissions for UI rendering decisions (e.g., hiding create buttons, disabling edit fields) without making speculative API calls.

#### Scenario: Frontend checks schema-level permissions via API
- **GIVEN** the frontend needs to know if the current user can create objects in schema `meldingen`
- **WHEN** it queries the schema metadata endpoint or the OAS specification
- **THEN** the response MUST include the authorization configuration for the schema
- **AND** the frontend MUST be able to compare the user's groups (available from Nextcloud session) against the `create` groups

#### Scenario: Frontend hides UI elements based on property-level RBAC
- **GIVEN** the frontend renders an object detail view for schema `dossiers`
- **AND** property `interneAantekening` has property-level read authorization for `redacteuren`
- **WHEN** the current user is NOT in `redacteuren`
- **THEN** the `interneAantekening` field MUST be absent from the API response (filtered by `PropertyRbacHandler::filterReadableProperties()`)
- **AND** the frontend MUST handle the missing field gracefully (not rendering the field rather than showing an empty value)

#### Scenario: Frontend uses OAS security blocks for permission discovery
- **GIVEN** the frontend has loaded the OAS specification for the register
- **WHEN** it inspects the `security` block of the POST operation for schema `meldingen`
- **THEN** it MUST find the OAuth2 scopes required for creating objects
- **AND** it can compare these against the current user's groups to determine if the "Create" button should be shown

### Requirement: Effective-Scope Discovery API

The system MUST expose an effective-scope discovery endpoint so clients
(frontend feature gates, OAuth2 token exchange, downstream apps) can learn which
`(register, schema, action)` tuples the current user may perform without probing
every endpoint. `ScopesController::index()` (`GET /api/scopes`) MUST return an
envelope `{user, isAdmin, groups, scopes}` where `scopes` is a list of
`{register, schema, actions}` entries keyed by slug. For each in-scope
(register, schema) pair the controller MUST probe `PermissionHandler::hasPermission()`
for the five canonical actions (`read`, `create`, `update`, `delete`, `list`)
and include only the granted ones, omitting pairs with no granted actions. Admin
callers MUST short-circuit to the full action vocabulary for every pair,
mirroring the admin-bypass in `PermissionHandler`. Unauthenticated callers MUST
be supported with `user: null`. Optional `register` and `schema` query
parameters (id|uuid|slug) MUST narrow the response, and resolution MUST keep the
multitenancy filter on so the endpoint cannot enumerate across tenants.

#### Scenario: Authenticated user discovers effective scopes
- **GIVEN** an authenticated non-admin user in groups `users` and `hr`
- **WHEN** a GET request is sent to `/api/scopes`
- **THEN** the response MUST include `user`, `isAdmin: false`, `groups`, and a `scopes` list
- **AND** each scope entry MUST list only the actions granted by `PermissionHandler::hasPermission()` for that (register, schema) pair
- **AND** pairs with no granted action MUST be omitted

#### Scenario: Admin receives the full action vocabulary
- **GIVEN** a caller in the `admin` group
- **WHEN** `index()` builds the response
- **THEN** `isAdmin` MUST be `true`
- **AND** `collectActionsForUser()` MUST short-circuit to `["read", "create", "update", "delete", "list"]` for every (register, schema) pair

#### Scenario: Filter discovery by register and schema
- **GIVEN** a GET request to `/api/scopes?register=decidesk&schema=meeting`
- **WHEN** `resolveRegisters()` and `resolveSchemas()` apply the filters
- **THEN** only the matching register/schema MUST be evaluated
- **AND** the multitenancy filter MUST remain on so cross-tenant enumeration is not possible

#### Scenario: Unauthenticated caller is supported
- **GIVEN** no active user session
- **WHEN** a GET request is sent to `/api/scopes`
- **THEN** the response MUST set `user: null` and `isAdmin: false`
- **AND** only scopes reachable by the `public` pseudo-group MUST be returned

### Requirement: RBAC settings configuration API
The system SHALL expose an admin-gated API for reading and writing the RBAC enablement and
configuration dials that govern scope enforcement. `ConfigurationSettingsController`
provides `getRbacSettings` (delegating to `SettingsService::getRbacSettingsOnly()`) and
`updateRbacSettings` (delegating to `SettingsService::updateRbacSettingsOnly()`). Both
return HTTP 500 with an `error` field on service failure.

#### Scenario: Read RBAC settings
- **WHEN** `getRbacSettings` is called
- **THEN** it MUST return the RBAC settings document from `SettingsService::getRbacSettingsOnly()`

#### Scenario: Update RBAC settings
- **GIVEN** an admin toggles RBAC enforcement and posts the change
- **WHEN** `updateRbacSettings` runs
- **THEN** it MUST persist the change via `SettingsService::updateRbacSettingsOnly()` and return the updated settings

### Requirement: Custom (non-canonical) action verbs MUST be resolvable via a voting event pair
When `PermissionHandler` evaluates an action that is NOT one of the canonical five (`read`, `create`, `update`, `delete`, `list`), it MUST dispatch a `CustomScopeEvaluatingEvent` so consuming apps that declare custom action verbs on a register can contribute a verdict. The verdict is first-vote-wins: the first listener to call `allow()` OR `deny()` decides, and subsequent votes are ignored so the outcome is deterministic regardless of listener registration order. When no listener votes, the handler MUST fall through to the standard rule chain. After a listener-driven verdict, a paired telemetry `CustomScopeEvaluatedEvent` MUST be dispatched for observers (audit, dashboards, analytics) without participating in the decision.

#### Scenario: Custom verb dispatches the evaluating event with full context
- **GIVEN** a register declares a custom action verb `approve` and a user `jan` (groups `["behandelaars"]`) attempts it on schema `besluiten`
- **WHEN** `PermissionHandler` evaluates the `approve` action
- **THEN** a `CustomScopeEvaluatingEvent` MUST be dispatched
- **AND** `getSchema()` MUST return the `besluiten` schema, `getAction()` MUST return `"approve"`, `getUserId()` MUST return `"jan"`, and `getUserGroups()` MUST return `["behandelaars"]`
- **AND** `getObject()` MUST return the target `ObjectEntity` when one was supplied, otherwise `null`

#### Scenario: First listener vote wins and short-circuits
- **GIVEN** two listeners are registered for `CustomScopeEvaluatingEvent`
- **WHEN** the first listener calls `allow()` and the second calls `deny()`
- **THEN** `getVerdict()` MUST return `true` (the first vote)
- **AND** `hasVerdict()` MUST return `true`
- **AND** the second listener's `deny()` MUST be ignored

#### Scenario: No listener votes falls through to the standard rule chain
- **GIVEN** no listener casts a verdict on the `CustomScopeEvaluatingEvent`
- **WHEN** evaluation completes
- **THEN** `hasVerdict()` MUST return `false` and `getVerdict()` MUST return `null`
- **AND** `PermissionHandler` MUST evaluate the action against the standard static rule chain
- **AND** no `CustomScopeEvaluatedEvent` MUST be dispatched (the standard rule-chain audit paths capture that outcome)

#### Scenario: Telemetry event reports the resolved verdict and its origin
- **GIVEN** a listener resolved a custom-scope evaluation to `true`
- **WHEN** the paired `CustomScopeEvaluatedEvent` is dispatched
- **THEN** `getVerdict()` MUST return `true` and `isFromListener()` MUST return `true`
- **AND** `getSchema()`, `getAction()`, and `getUserId()` MUST mirror the evaluating event's context

### Requirement: Schema and register authorization MUST accept an optional `inheritFromPublic` boolean

Schema and register authorization blocks MUST accept an optional `inheritFromPublic` field, boolean. The default value (when the field is absent or `null`) MUST be resolved via the cascade documented below. Existing schemas and registers that do not set the field MUST behave identically to before this change (default `true`).

#### Scenario: Schema authorization without inheritFromPublic preserves pre-change behaviour

- **GIVEN** a schema whose authorization block has no `inheritFromPublic` field
- **AND** the register's authorization block also has no `inheritFromPublic` field
- **AND** the tenant default IAppConfig key is unset
- **WHEN** RBAC checks run
- **THEN** the effective `inheritFromPublic` value is `true` (the pre-change behaviour)
- **AND** authenticated users qualify for `public` rules as they did before

#### Scenario: Schema sets inheritFromPublic explicitly

- **GIVEN** a schema whose authorization block contains `"inheritFromPublic": false`
- **WHEN** RBAC checks run
- **THEN** the effective value is `false` for that schema
- **AND** authenticated users do NOT qualify for `public` rules on that schema

#### Scenario: Schema authorization round-trip preserves the field

- **GIVEN** a schema saved with `"inheritFromPublic": false` in its authorization block
- **WHEN** the schema is fetched and re-serialised via `Schema::getAuthorization()`
- **THEN** the returned array contains `inheritFromPublic` with value `false`
- **AND** the JSON serialisation includes the field

### Requirement: The effective value of `inheritFromPublic` MUST be resolved via cascade

The cascade order MUST be: schema's authorization → register's authorization → tenant-wide `IAppConfig` key `openregister.rbac.inherit_from_public_default` → hard-coded `true`. The first explicitly-set value wins. `null` MUST be treated as "unset" (cascade falls through to the next level).

#### Scenario: Cascade falls back to register when schema has no value

- **GIVEN** a schema whose authorization has NO `inheritFromPublic` field
- **AND** the parent register's authorization has `"inheritFromPublic": false`
- **WHEN** the resolver is called for that schema
- **THEN** the resolved value is `false`

#### Scenario: Cascade falls back to tenant default when neither schema nor register sets it

- **GIVEN** schema and register without the field
- **AND** IAppConfig `openregister.rbac.inherit_from_public_default` is set to `false`
- **WHEN** the resolver is called
- **THEN** the resolved value is `false`

#### Scenario: Cascade falls back to hard-coded true when nothing is set

- **GIVEN** no schema, register, or tenant default is set
- **WHEN** the resolver is called
- **THEN** the resolved value is `true`

#### Scenario: Schema explicit value wins over register and tenant

- **GIVEN** schema sets `"inheritFromPublic": true`
- **AND** register sets `"inheritFromPublic": false`
- **AND** tenant default is `false`
- **WHEN** the resolver is called
- **THEN** the resolved value is `true` (schema wins)

#### Scenario: null is treated as "unset"

- **GIVEN** schema authorization contains `"inheritFromPublic": null`
- **AND** register sets `"inheritFromPublic": false`
- **WHEN** the resolver is called
- **THEN** the cascade continues past the schema; the resolved value is `false` (from register)

### Requirement: When `inheritFromPublic` is `false`, authenticated users MUST NOT qualify for `public` rules

When the resolved `inheritFromPublic` is `false`, the PHP-side `PermissionHandler::hasPermission` MUST NOT fall back to `hasGroupPermission(public, ...)` for authenticated users; it MUST return only the result of evaluating the user's own group memberships (plus owner / admin checks). Anonymous users see no behaviour change — the public-fallback path was never used for them in the first place.

The SQL-side filter (`MagicRbacHandler::applyRbacFilters` and `buildRbacConditionsSql`) MUST equivalently exclude `public`-grouped rules from contributing conditions for authenticated users. The simple-string `'public'` rule MUST NOT grant unconditional access to authenticated users when the flag is `false`. Conditional `{group: "public", match: ...}` rules MUST NOT add their match conditions to the WHERE clause for authenticated users when the flag is `false`.

#### Scenario: Authenticated user is denied when inheritance is off and only public has access

- **GIVEN** a schema with `inheritFromPublic: false` and authorization `read: [{group: "public", match: <some-match>}]`
- **AND** an authenticated user `alice` not a member of any group named in any rule
- **AND** an object that satisfies the public match
- **WHEN** alice attempts to read the object
- **THEN** access is denied
- **AND** the SQL filter excludes the object from listings for alice

#### Scenario: Anonymous user is granted when public match passes (regardless of flag)

- **GIVEN** the same schema as above
- **WHEN** an anonymous (unauthenticated) request reads the object
- **THEN** access is granted (the public match is satisfied)
- **AND** the SQL filter includes the object

#### Scenario: Authenticated user with explicit group membership is still granted

- **GIVEN** a schema with `inheritFromPublic: false` and authorization `read: [{group: "public", match: ...}, "editors"]`
- **AND** an authenticated user `bob` in the `editors` group
- **WHEN** bob attempts to read the object
- **THEN** access is granted (via the explicit `editors` rule)
- **AND** the SQL filter includes the object for bob

#### Scenario: Owner check is unaffected by the flag

- **GIVEN** a schema with `inheritFromPublic: false` and `read: [{group: "public", match: ...}]`
- **AND** an authenticated user `carol` who is the owner of an object
- **WHEN** carol attempts to read the object
- **THEN** access is granted via the owner shortcut, regardless of the flag

#### Scenario: Admin user is unaffected by the flag

- **GIVEN** a schema with `inheritFromPublic: false`
- **AND** an authenticated user in the `admin` group
- **WHEN** the admin reads any object on this schema
- **THEN** access is granted via the admin bypass, regardless of the flag

### Requirement: When `inheritFromPublic` is `true` (or unset), behaviour MUST be identical to before this change

For any schema, register, or tenant where the resolved `inheritFromPublic` is `true` (the default), authenticated users MUST continue to qualify for `public` rules exactly as they did before this change. The new code paths MUST NOT introduce any behavioural drift for schemas that don't opt out.

#### Scenario: Pre-change schema is unaffected

- **GIVEN** a schema with no `inheritFromPublic` field anywhere in its cascade
- **AND** an authenticated user
- **AND** an object satisfying a public match rule
- **WHEN** the user attempts to read the object
- **THEN** access is granted
- **AND** the result is identical to pre-change behaviour

### Requirement: The simple-string `'authenticated'` rule MUST be unaffected by the flag

The existing `'authenticated'` simple-rule string (recognised by `MagicRbacHandler::processSimpleRule` line 274-276) grants unconditional access to any logged-in user. This behaviour MUST be unchanged by `inheritFromPublic`. The flag concerns the `public` group only.

#### Scenario: 'authenticated' rule still grants access when public inheritance is disabled

- **GIVEN** a schema with `inheritFromPublic: false` and `read: ["authenticated"]`
- **AND** an authenticated user
- **WHEN** the user attempts to read
- **THEN** access is granted (via the `authenticated` rule, independent of the flag)

### Requirement: PHP-side and SQL-side enforcement MUST be identical

For any combination of (user state, flag value, authorization rules, object data), the result of `PermissionHandler::hasPermission` (per-object check) MUST agree with whether the SQL filter (`MagicRbacHandler::applyRbacFilters`) would include the object in a listing. The two layers MUST NOT diverge.

#### Scenario: Per-object check and listing filter agree across the four-state matrix

- **GIVEN** a schema with `read: [{group: "public", match: <m>}]`
- **AND** the four states: (anon, authenticated) × (inheritFromPublic true, false)
- **AND** an object satisfying the public match
- **WHEN** the per-object `hasPermission` check runs AND the listing endpoint runs
- **THEN** for each of the four states, the per-object check's boolean result matches the listing's include/exclude decision for that object

### Requirement: A declared group MUST exist as a Nextcloud group

Every group id named in a register, schema or property `authorization` block, and every group id declared in a configuration's `components.securitySchemes.oauth2.flows.authorizationCode.scopes` map, SHALL be created as a Nextcloud group if it does not already exist.

This closes a silent-denial gap. `PermissionHandler::hasGroupPermission()` resolves access by membership test alone, so a group that was never created and a group nobody belongs to are indistinguishable: both deny every caller, with no error raised or logged. A typo in an `authorization` block is therefore invisible and reads exactly like a working access control.

Group ids are free-form and SHALL NOT be prefixed or namespaced. Two apps declaring the same id converge on one Nextcloud group by design; a declaring app therefore MUST NOT assume it owns a group it declared.

Provisioning SHALL be create-only: it MUST NOT delete a group, and MUST NOT add or remove members. `admin` and `public` SHALL never be provisioned — `admin` is Nextcloud's built-in group and is short-circuited before any group test, and `public` is a pseudo-principal for anonymous access rather than a membership-bearing group.

#### Scenario: A group named only in a property rule is created

- **GIVEN** a schema whose property `bsn` declares `authorization.read: ["privacy-officers"]` and whose schema-level block never mentions that group
- **AND** no Nextcloud group `privacy-officers` exists
- **WHEN** the configuration is imported
- **THEN** the Nextcloud group `privacy-officers` exists
- **AND** it has no members, so the rule still denies every caller until an administrator populates it

#### Scenario: Reserved principals are never created as groups

- **GIVEN** an authorization block granting `read` to `public` and `delete` to `admin`
- **WHEN** the configuration is imported
- **THEN** no Nextcloud group named `public` is created
- **AND** no attempt is made to create `admin`

#### Scenario: Match conditions are not mistaken for principals

- **GIVEN** a rule `{ "group": "behandelaars", "match": { "status": "open" } }`
- **WHEN** declared groups are collected
- **THEN** only `behandelaars` is provisioned
- **AND** no group named `status` or `open` is created

#### Scenario: Role assignments name groups in their values, not their keys

- **GIVEN** an authorization block with `roles: { "behandelaar": "groep-a", "beheerder": ["groep-b", "groep-c"] }`
- **WHEN** declared groups are collected
- **THEN** `groep-a`, `groep-b` and `groep-c` are provisioned
- **AND** no group named `behandelaar` or `beheerder` is created

#### Scenario: Provisioning survives a refusing group backend

- **GIVEN** three declared groups, of which the second is refused by a read-only group backend
- **WHEN** provisioning runs
- **THEN** the first and third groups are created
- **AND** the refusal is logged against the declaring app rather than aborting the import

### Requirement: Declared groups MUST be provisioned before the import skip check

Provisioning SHALL run on every configuration import, including one that the content-hash check would skip.

The skip means "importing would write exactly what is already stored" — a statement about stored data, which says nothing about whether the Nextcloud groups those authorization blocks name still exist. Provisioning after the skip would mean a group deleted by an administrator is never restored, because the very configuration that declares it is the one being skipped.

#### Scenario: An unchanged re-import restores a hand-deleted group

- **GIVEN** a configuration whose stored content hash matches the incoming document, so the import is skipped
- **AND** a group it declares has since been deleted by an administrator
- **WHEN** the configuration is imported again
- **THEN** the declared group exists again
- **AND** no configuration entities are re-written

### Requirement: Provisioning MUST NOT depend on a leaf app's repair-step wiring

Declared groups SHALL be reconciled by an OpenRegister-owned background sweep in addition to the import path, and that sweep SHALL read the live registers and schemas rather than the shipped configuration files.

Import-time provisioning alone inherits each leaf app's `<repair-steps>` declaration, which is frequently wrong. Nextcloud runs `migrateSchemaOnly()` on a first install (`\OC\Installer::installAppLastSteps`): `<pre-migration>` and `<post-migration>` steps are both skipped, and `<install>` is the only unconditional hook. An app that declares its register import only under `<post-migration>` never imports on a fresh instance — precisely the case where no declared group exists yet.

Reading live entities rather than files additionally covers virtual apps that ship no `register.json`, and restores a group an administrator deleted by hand.

#### Scenario: A fresh install with no `<install>` hook still gets its groups

- **GIVEN** an app whose register import is declared only under `<post-migration>`
- **AND** the app is installed for the first time, so that step never runs
- **WHEN** the reconciliation sweep next runs
- **THEN** every group declared by the live registers and schemas exists

#### Scenario: The sweep reads unfiltered rows

- **GIVEN** the sweep runs from cron, with no logged-in user and no active organisation
- **WHEN** it enumerates registers and schemas
- **THEN** it does so with RBAC and multi-tenancy filtering disabled
- **AND** it therefore does not report a clean pass over rows it never read

### Requirement: An exported configuration MUST declare the groups it depends on

`ExportHandler` SHALL emit `components.securitySchemes.oauth2.flows.authorizationCode.scopes` into the exported configuration document, covering every group named by the exported registers and schemas, at parity with the map `OasService` generates for API consumers.

The scope map is derived from the definitions the document already carries, so it does not recover data the importer could not otherwise derive; what it adds is an explicit, self-describing declaration at the OAS-native location.

#### Scenario: The scope map alone carries every group

- **GIVEN** a configuration with a register-level group, a schema-level group and a property-level group
- **WHEN** it is exported
- **THEN** reading the scope map ALONE — without walking any authorization block — yields all three group ids
- **AND** `admin` is present as a scope
- **AND** `public` is absent unless the configuration grants anonymous access

### Requirement: A declared group with no members MUST be visible

The system SHALL expose, per declared group, whether it exists and how many members it has, so that a declared-but-unpopulated group is discoverable rather than silently denying every caller.

Where the group backend cannot report a count, the member count SHALL be reported as UNKNOWN rather than zero. `OCP\IGroup::count()` returns `int|bool` and yields `false` on backends that cannot count; reporting that as `0` would present a fully populated group as empty and raise the exact false alarm this surface exists to prevent.

#### Scenario: An uncountable backend reports unknown, not empty

- **GIVEN** a declared group on a backend whose `count()` returns `false`
- **WHEN** the declared-group inventory is read
- **THEN** the group's member count is reported as unknown
- **AND** it is NOT reported as having zero members

### Requirement: A userless read inside runAsSystem is a system read
A read made without a user inside `ObjectService::runAsSystem()` (SystemOperationContext) SHALL be treated as a system read by the magic-table access filters, the same way a userless read on the command line already is: RBAC filtering (query-builder and raw-SQL paths) and the organisation boundary SHALL NOT clamp it. This SHALL NOT open any user-facing read: a logged-in user inside the scope MUST still be filtered as that user, a forced-anonymous evaluation (AnonymousEvaluationContext) MUST still be filtered as anonymous, and a userless web read outside the scope MUST still be filtered. SaaS mode keeps the organisation boundary.

#### Scenario: A system write's calculation resolves its references in a web request
- **GIVEN** a web request with no user, and a case written inside `runAsSystem()` whose calculations declare references to `caseType` and `statusType`, schemas readable by staff only
- **WHEN** ReferenceResolver reads those objects with RBAC and multitenancy on
- **THEN** the referenced objects MUST be found, so the case gets its deadline and status label

#### Scenario: A logged-in user inside the scope is still that user
- **GIVEN** a logged-in non-admin user inside `runAsSystem()`
- **WHEN** a staff-only schema is read
- **THEN** the read MUST be filtered by that user's rights

#### Scenario: Outside the scope nothing changes
- **GIVEN** a web request with no user, outside `runAsSystem()`
- **WHEN** a staff-only schema is read
- **THEN** the read MUST be filtered as anonymous

### Requirement: Related schemas are a catalog read
`GET /api/schemas/{id}/related` SHALL resolve the schema and scan the other schemas without the multitenancy filter (a metadata read, like `GET /api/schemas/{id}`), so a caller who may see the schema gets its related schemas whatever their active organisation.

#### Scenario: A non-admin in another organisation gets related schemas
- **GIVEN** a non-admin whose active organisation does not own schema `module`
- **WHEN** they request `GET /api/schemas/module/related`
- **THEN** the response MUST be HTTP 200 with the incoming and outgoing related schemas, not "Schema not found"

### Requirement: The scoped acting-user and trusted-system operations MUST have a stated contract

Two scoped operations exist on the object service and have shipped without a
spec-level contract: one narrows the caller to a named user for the duration of a
callable, the other elevates to a trusted userless principal. Both are relied on
by flow nodes and background jobs to decide access, so their guarantees are
observable behaviour rather than implementation detail.

The narrowing operation MUST behave as `delegated-identity` requires: it
establishes the named user as the acting identity for the callable only, restores
the previously acting identity when the callable ends — including on a throw —
and grants nothing the named user does not already hold.

The elevating operation MUST be reachable only from code shipped with the
application, per `delegated-identity`.

#### Scenario: The narrowing operation grants nothing

- **WHEN** a callable runs narrowed to a user who lacks a permission
- **THEN** an action requiring that permission is refused inside the callable
- **AND** the refusal names the narrowed user, not the ambient caller

#### Scenario: The elevating operation is not reachable from request handling

- **WHEN** handling of an inbound request attempts a trusted userless operation
- **THEN** the attempt is refused
- **AND** the refusal is reported rather than downgraded to a silent no-op

### Requirement: Authorization decisions MUST answer for the acting identity, not the ambient session

Every predicate that decides access — the row-level authorization predicate and
the tenancy predicate — MUST resolve the subject from the acting identity in
force. A caller holding an explicit identity MUST NOT have that identity ignored
in favour of whatever the ambient session carries.

Where no acting identity is in force, an access decision MUST fail closed. A read
MUST NOT silently drop its authorization predicate and return more than the
subject may see; a write MUST NOT be attributed to a named owner while being
decided against a different subject.

#### Scenario: A read and the write it feeds decide against one identity

- **WHEN** a lookup selects the object that a subsequent write or delete acts on,
  both within one scoped identity
- **THEN** both the lookup and the action decide against that same identity
- **AND** an object the identity may not act on is not selected by the lookup

#### Scenario: A sessionless read does not widen

- **WHEN** a read runs with no acting identity in force
- **THEN** the read is refused
- **AND** it does not return rows that an authorization predicate would have
  excluded

### Requirement: Delegation MUST NOT be expressed as a permission verb

Acting as another user is a property of the caller's identity, not an action
performed on an object. It MUST NOT be added to the permission vocabulary, and
MUST NOT be expressed as a scope, role or verb on a register, schema or object.

This preserves ADR-010's rule that the core verb set is core's bitmask and that
extensions are enforced at the endpoint performing the action rather than by
widening the RBAC vocabulary.

#### Scenario: No delegation verb appears in the permission model

- **WHEN** the permission model for a register or schema is read
- **THEN** it contains no verb, scope or role expressing "may act as another
  user"

### Requirement: The delegation store MUST sit outside the access control it informs

Grant lookups MUST NOT pass through the object-level authorization path. A read
that decides authorization cannot itself depend on the decision it is making, and
routing it through `ObjectService` would make it do exactly that.

It MUST NOT be resolved by elevating to a trusted userless principal either. That
would put a security-critical read behind the one escape hatch
`delegated-identity` forbids on request paths, and make every grant check a
reason to reach for it.

#### Scenario: Resolving a grant needs neither a subject nor elevation

- **WHEN** an access decision resolves whether a grant exists
- **THEN** the lookup performs no object-level permission check
- **AND** it does not enter a trusted userless scope

### Requirement: Delegation MUST remain outside the permission vocabulary

Consistent with ADR-010 and with `or-delegated-identity`: acting as another user
is a property of the caller's identity, not an action performed on an object. The
grant store MUST NOT be expressed as a permission verb, scope or role.

#### Scenario: No delegation verb is introduced

- **WHEN** the permission model is read after this change
- **THEN** it contains no verb, scope or role expressing delegation

### Requirement: The set of grantable permissions is published (REQ-PPD-001)

The system SHALL expose every permission that may be granted in this
instance through a discovery endpoint. Each entry SHALL name the verb,
the app that declared it, the scope levels it may be granted at and a
description a human can read. The canonical verbs (`read`, `create`,
`update`, `delete`, `list`, `manage`) SHALL always be present. A custom
verb SHALL appear only when an app has declared it, and a verb that is
not in the catalogue SHALL NOT be grantable: a role's `actions` array or
an authorization block naming an unknown verb SHALL fail to save with
HTTP 422 naming the verb.

#### Scenario: an administrator reads what can be granted

- **GIVEN** an instance where one app has declared the custom verb `publish`
- **WHEN** a client requests the permission catalogue
- **THEN** the response includes the six canonical verbs and `publish`
- **AND** `publish` names the app that declared it and a description
- @e2e exclude {covered by the catalogue unit tests and the e2e in task 4.1}

#### Scenario: an unknown verb is refused at save

- **GIVEN** a register whose `roles` configuration names the action `approve`, which no app declares
- **WHEN** the register is saved
- **THEN** the save fails with HTTP 422 and the message names `approve`
- @e2e exclude {validator, covered by unit tests}

#### Scenario: a declared verb without an evaluator fails closed

- **GIVEN** a declared custom verb whose app registers no listener for `CustomScopeEvaluatingEvent`
- **WHEN** the verb is evaluated
- **THEN** the answer is a refusal
- **AND** the refusal names the app that owes the listener
- @e2e exclude {resolver behaviour, covered by unit tests}

### Requirement: A rule may deny a verb, and a deny is not overridden (REQ-PPD-002)

An authorization entry SHALL be able to name a verb as denied for a
group, a role or an object. A deny SHALL remove that verb inside its
scope and SHALL NOT be overridden by a broader grant, including a grant
inherited from an ancestor object under `x-openregister-hierarchy`. A
grant and a deny written for the same principal at the same level SHALL
fail to save with both rules named. The object list SHALL return the same
set the per-object check allows, so a denied object SHALL NOT appear in a
list.

#### Scenario: a deny on a child beats an inherited grant

- **GIVEN** a user holding a per-object `read` grant on a root object
- **AND** a deny of `read` on one child of that root
- **WHEN** the user reads that child
- **THEN** the read is refused

#### Scenario: the denied object is absent from the list

- **GIVEN** the same user, the same grant and the same deny
- **WHEN** the user lists the schema's objects
- **THEN** the root and its other descendants are returned
- **AND** the denied child is not in the list

#### Scenario: a broader grant does not restore a denied verb

- **GIVEN** a schema rule granting `update` to the group `behandelaars`
- **AND** a deny of `update` for the role `waarnemer`, held by a member of that group
- **WHEN** that member saves a change
- **THEN** the write is refused
- @e2e exclude {resolution precedence, covered by unit tests}

#### Scenario: a grant and a deny at one level is a configuration error

- **GIVEN** an authorization block granting and denying `delete` to the same group at the same level
- **WHEN** the block is saved
- **THEN** the save fails with HTTP 422
- **AND** the message names both rules
- @e2e exclude {validator, covered by unit tests}

### Requirement: Administration cannot be denied away (REQ-PPD-003)

A deny SHALL NOT remove `manage` from the last principal holding it on a
register. The save SHALL be refused, and the refusal SHALL name what
would be left without an administrator.

#### Scenario: the last manager cannot be denied

- **GIVEN** a register where one group holds `manage`
- **WHEN** an administrator saves a deny of `manage` for that group
- **THEN** the save fails
- **AND** the message names the register and the group
- @e2e exclude {validator, covered by unit tests}

### Requirement: Every effective permission names the rule that decided it (REQ-PPD-004)

The effective-scope discovery endpoint SHALL report, for each granted
action, the rule that granted it: the register default, the schema rule,
the named role, the per-object grant, or the ancestor object it was
inherited from. For an action a broader rule would have granted and a
deny removed, it SHALL report that deny. The existing `actions` list
SHALL keep its shape so existing callers are unaffected. The scope audit
SHALL report the same provenance, and a denial log entry SHALL name the
rule and not only the decision.

#### Scenario: a grant says where it came from

- **GIVEN** a user who may `read` a schema through a role on the register
- **WHEN** the client requests the effective scopes
- **THEN** `read` is listed in `actions`
- **AND** its provenance names the role and the register
- @e2e exclude {covered by the scopes endpoint unit tests and the e2e in task 4.1}

#### Scenario: an absence says which rule removed it

- **GIVEN** a user who would hold `update` through a schema rule, with `update` denied for their role
- **WHEN** the client requests the effective scopes
- **THEN** `update` is absent from `actions`
- **AND** the provenance names the deny and the role it was written for
- @e2e exclude {covered by the scopes endpoint unit tests}

#### Scenario: an instance with no deny is unchanged

- **GIVEN** an instance declaring no deny and no custom verb
- **WHEN** any authorization decision is made
- **THEN** the answer is the same as before this change
- @e2e exclude {regression assertion, covered by unit tests}

### Requirement: Access is compiled into the query, not applied to the result (REQ-PPD-005)

Grants, inherited grants and denies SHALL be compiled into the object
query and into the search index query as predicates, so that the returned
page, the total and every facet count are computed over the set the caller
may see. The system SHALL NOT filter a fetched page after the fact. The
list path and the per-object check SHALL agree for every object.

#### Scenario: the total counts only what the caller may see

- **GIVEN** a schema holding 100 objects of which the caller may read 12
- **WHEN** the caller lists the schema with a page size of 10
- **THEN** the total is 12, the first page holds 10 and the second holds 2

#### Scenario: facets count the permitted set

- **GIVEN** the same caller and a facet over a property
- **WHEN** the facet is requested
- **THEN** the counts sum to 12

#### Scenario: search and list agree

- **GIVEN** the same caller and an object they may not read
- **WHEN** they search for a term that object contains
- **THEN** the object is absent from the results and from the result count
- @e2e exclude {index path, covered by unit tests and the search suite}

### Requirement: A record is returned with the actions its reader may take (REQ-PPD-006)

An object read SHALL carry the actions the current user may take on that
object, resolved in the same pass that decided the read. The list SHALL
contain the verbs the caller actually holds, deny included, so a client
does not have to guess and does not discover a refusal by attempting it.

#### Scenario: the page renders only what is allowed

- **GIVEN** a user who may read and update an object but may not delete it
- **WHEN** the object is read
- **THEN** the response lists `read` and `update` and does not list `delete`

#### Scenario: a deny removes the action from the record

- **GIVEN** the same user with `update` denied on that one object
- **WHEN** the object is read
- **THEN** `update` is absent from the actions

### Requirement: An object answers who holds which right on it, and how that changed (REQ-PPD-007)

The system SHALL answer, for a named object, which principals hold which
verbs on it and the rule behind each grant. The system SHALL keep the
history of that set, so it can report who held which right at a past
moment and which rule changed it. Two roles SHALL be readable side by side
against the catalogue, showing which permissions differ.

#### Scenario: an auditor asks who could open a dossier

- **GIVEN** an object reachable by one role grant, one per-object grant and one inherited grant
- **WHEN** the object's effective permissions are read
- **THEN** the three principals are listed, each with its verbs and the rule behind it

#### Scenario: the access history answers a question about last year

- **GIVEN** a grant that was removed three months ago
- **WHEN** the object's access history is read for a date before the removal
- **THEN** the grant is reported as held at that date, with the rule that removed it afterwards
- @e2e exclude {history path, covered by unit tests}

#### Scenario: two roles are compared

- **GIVEN** the roles `behandelaar` and `senior behandelaar`
- **WHEN** the two are compared
- **THEN** the verbs only the senior role holds are listed
- @e2e exclude {catalogue read, covered by unit tests}

### Requirement: Grants may be derived at login, scoped, and given an end (REQ-PPD-008)

A rule SHALL be able to map the claims an identity provider asserts to
roles and scopes when a user signs in, in the same declared shape as any
other authorization rule. A grant MAY carry an end, including an end bound
to the deadline of the workflow step that created it, and an expired grant
SHALL NOT be resolved. When a rule that derives access changes, the system
SHALL re-run the derivation and report how many grants changed. The
`manage` verb SHALL be grantable scoped to a named area, so a person may
administer part of the instance without administering all of it.

#### Scenario: a new employee is authorised without a matrix

- **GIVEN** a rule mapping the claim `department: vergunningen` to the role `behandelaar` in that department
- **WHEN** a user with that claim signs in
- **THEN** they hold the role in that department and no other

#### Scenario: a step's grant dies with the step

- **GIVEN** a workflow step granting `read` on a file to its assignee until its deadline
- **WHEN** the deadline passes and the assignee reads the file
- **THEN** the read is refused
- @e2e exclude {time-dependent, covered by unit tests with a clock fixture}

#### Scenario: changing the rule reports what moved

- **GIVEN** a derivation rule granting access to 240 objects
- **WHEN** the rule is narrowed and saved
- **THEN** the derivation re-runs and the response names how many grants changed

#### Scenario: a delegated administrator cannot administer everything

- **GIVEN** a user holding `manage` scoped to one register
- **WHEN** they try to change the configuration of another register
- **THEN** the write is refused naming the scope of their grant

### Requirement: The deny is recorded before it refuses anything (REQ-PPD-009)

The system SHALL carry a deny enforcement mode with three values: `off`,
`staging` and `enforcing`. The default SHALL be `staging`, and a value the
system does not recognise SHALL be read as `staging`. In `staging` a deny
SHALL be resolved and recorded and SHALL NOT change any answer: the grant
stands, no deny predicate enters the object query, and the recorded entry
SHALL name the rule, the principal it names, the verb and the caller. In
`off` no deny SHALL be resolved and none SHALL be recorded. In `enforcing`
a deny SHALL apply as REQ-PPD-002 describes. Every enforcement path SHALL
read the same mode, so an object read and a list cannot resolve in
different modes within one request. The save-time refusals of REQ-PPD-002
and REQ-PPD-003 SHALL apply in every mode.

#### Scenario: a new deny refuses nobody on the day it is written

- **GIVEN** an instance that has never set the deny enforcement mode
- **AND** a deny of `read` for the group `waarnemers` on a schema they may read
- **WHEN** a member of that group reads an object of the schema
- **THEN** the read succeeds
- **AND** the system records that the deny would have refused it, naming the rule

#### Scenario: the list is unchanged while staging

- **GIVEN** the same instance, the same deny and a schema holding 100 readable objects
- **WHEN** the caller lists the schema
- **THEN** the total is 100

#### Scenario: the administrator turns it on

- **GIVEN** the same instance with the deny enforcement mode set to `enforcing`
- **WHEN** the same member reads the same object
- **THEN** the read is refused
- @e2e exclude {mode is instance configuration, covered by unit tests}

#### Scenario: a contradiction is refused whatever the mode

- **GIVEN** an instance in `staging`
- **WHEN** an administrator saves a block granting and denying `delete` to one group at one level
- **THEN** the save fails with HTTP 422
- @e2e exclude {validator, covered by unit tests}

### Requirement: A schema declares the property that names its parent (REQ-RIC-001)

A schema MAY declare `x-openregister-hierarchy` with a `parent` property
name and a `maxDepth`. The named property MUST be a declared reference to
the same schema; a schema that names anything else SHALL fail to save with
HTTP 422. A schema without the annotation SHALL resolve authorization
exactly as it does today.

#### Scenario: a valid hierarchy declaration is accepted

- **GIVEN** a schema `case` with a `parentCase` property declared as a reference to `case`
- **WHEN** the schema is saved with `x-openregister-hierarchy: {"parent": "parentCase", "maxDepth": 5}`
- **THEN** the save succeeds
- @e2e exclude {annotation validator, covered by unit tests}

#### Scenario: a parent property that points elsewhere is refused

- **GIVEN** a schema `case` whose `assignee` property references the schema `user`
- **WHEN** the schema is saved with `x-openregister-hierarchy: {"parent": "assignee"}`
- **THEN** the save fails with HTTP 422 and the message names the property
- @e2e exclude {annotation validator, covered by unit tests}

#### Scenario: an undeclared hierarchy changes nothing

- **GIVEN** a schema without `x-openregister-hierarchy`
- **WHEN** a per-object grant is evaluated on one of its objects
- **THEN** the answer is the same as before this change
- @e2e exclude {regression assertion, covered by unit tests}

### Requirement: A grant on an ancestor answers for its descendants (REQ-RIC-002)

Where a schema declares its hierarchy, a per-object grant on an object
SHALL grant the same verbs on every descendant reachable through the
declared parent property, without a second grant. The inherited grant
SHALL carry the ancestor's verbs and no others. A grant written directly
on a descendant SHALL be evaluated beside the inherited one under the
existing most-specific-wins resolution. The object list SHALL return the
same set the per-object check allows.

#### Scenario: read on the root reaches the grandchild

- **GIVEN** a root object, a child and a grandchild linked by the declared parent property
- **AND** a user holding a per-object `read` grant on the root only
- **WHEN** the user reads the grandchild
- **THEN** the grandchild is returned

#### Scenario: read does not become write

- **GIVEN** the same user and the same `read` grant on the root
- **WHEN** the user saves a change to the child
- **THEN** the write is refused

#### Scenario: the list agrees with the read

- **GIVEN** the same user and the same grant
- **WHEN** the user lists the schema's objects
- **THEN** the root, the child and the grandchild are all in the result
- **AND** an object in a different tree they hold no grant on is not

#### Scenario: a direct grant on a descendant still applies

- **GIVEN** a user with `read` on the root and a direct `update` grant on the child
- **WHEN** the user saves a change to the child
- **THEN** the write succeeds
- @e2e exclude {resolution order, covered by unit tests}

### Requirement: Resolution is bounded and fails closed (REQ-RIC-003)

Ancestor resolution SHALL stop at the schema's `maxDepth` and SHALL detect
a cycle in the parent chain. In both cases the resolution SHALL return no
grant and SHALL log the refusal with the object and the reason. Resolution
SHALL be a single recursive query on both the object path and the list
path.

#### Scenario: a cycle grants nothing

- **GIVEN** two objects that name each other as parent
- **AND** a user with a per-object grant on neither
- **WHEN** the user reads one of them
- **THEN** access is refused and the refusal is logged with the cycle
- @e2e exclude {fail-closed path, covered by unit tests}

#### Scenario: a chain longer than maxDepth stops

- **GIVEN** a schema with `maxDepth: 3` and a chain of six objects
- **AND** a user holding a grant on the top object only
- **WHEN** the user reads the sixth
- **THEN** access is refused
- @e2e exclude {depth cap, covered by unit tests}

#### Scenario: a list over a tree stays one query

- **GIVEN** a tree of 500 objects at depth 5
- **WHEN** a user with a grant on the root lists them
- **THEN** the list is answered within the performance budget of openregister ADR-009
- @e2e exclude {performance assertion, covered by the query-count test}

### Requirement: An inherited grant names where it came from (REQ-RIC-004)

`GET /api/scopes` and the scope audit SHALL report an inherited grant with
the ancestor object that carries it, distinguishable from a grant written
on the object itself.

#### Scenario: the audit points at the ancestor

- **GIVEN** a user who may read a grandchild only through a grant on the root
- **WHEN** an administrator asks who has access to the grandchild
- **THEN** the user is listed with the root named as the source of the grant
- @e2e exclude {discovery endpoint, covered by Newman}

## ZGW Autorisaties Mapping Guide

OpenRegister's existing group-based RBAC maps directly to ZGW autorisaties concepts. No additional code is required -- this is a configuration and documentation concern.

### Consumer = Nextcloud User

A ZGW **Applicatie** (consumer application) maps to an OpenRegister **Consumer** entity. Each Consumer has a `userId` field that links it to a Nextcloud user. Authentication is handled via OpenRegister's multi-auth support (JWT, Basic Auth, OAuth2, API Key), and each authenticated request is resolved to a Nextcloud user identity.

| ZGW Concept | OpenRegister Equivalent |
|---|---|
| Applicatie | Consumer entity with `userId` field |
| Applicatie.clientIds | Consumer authentication credentials (JWT subject, API key, etc.) |
| Applicatie.label | Consumer name |

### Scope = Nextcloud Group

A ZGW **scope** (e.g., `zaken.lezen`, `zaken.aanmaken`) maps to a **Nextcloud group**. Schema-level and property-level authorization rules reference groups for CRUD access control.

| ZGW Scope | OpenRegister Configuration |
|---|---|
| `zaken.lezen` | Schema property `authorization.read: [{ "group": "zaken-lezen" }]` |
| `zaken.aanmaken` | Schema property `authorization.create: [{ "group": "zaken-aanmaken" }]` |
| `zaken.bijwerken` | Schema property `authorization.update: [{ "group": "zaken-bijwerken" }]` |
| `zaken.verwijderen` | Schema property `authorization.delete: [{ "group": "zaken-verwijderen" }]` |

To grant a consumer a scope, add the consumer's Nextcloud user to the corresponding Nextcloud group.

### heeftAlleAutorisaties = Admin Group

The ZGW `heeftAlleAutorisaties` flag (superuser access) maps to **admin group membership** in Nextcloud. Users in the admin group bypass all schema-level and property-level authorization checks.

### maxVertrouwelijkheidaanduiding = Property-Level Authorization

ZGW confidentiality levels (`maxVertrouwelijkheidaanduiding`) map to OpenRegister's **property-level authorization** with conditional matching. Properties can be restricted based on group membership with conditions like organisation context (`$organisation`), user identity (`$userId`), or custom conditions via `ConditionMatcher`.

Example: restricting a confidential property to specific groups:
```json
{
  "vertrouwelijkAanduiding": {
    "type": "string",
    "authorization": {
      "read": [{ "group": "vertrouwelijk-lezen", "condition": { "$organisation": "{{ object.bronorganisatie }}" } }],
      "update": [{ "group": "vertrouwelijk-schrijven" }]
    }
  }
}
```

### Query-Time Filtering

OpenRegister's `MagicRbacHandler` automatically filters query results at the database level based on the authenticated user's group memberships. This ensures that API list endpoints only return objects the consumer is authorised to see -- equivalent to ZGW's filtered listing behaviour based on autorisaties.

## Nextcloud Integration Analysis

**Status**: Implemented

**Existing Implementation**: `OasService` (`lib/Service/OasService.php`) extracts RBAC groups from schema property authorization blocks via `extractSchemaGroups()` and generates OAuth2 scopes in `components.securitySchemes.oauth2.flows.authorizationCode.scopes`. The `extractGroupFromRule()` method handles both simple string rules and conditional rule objects. Per-operation security requirements are applied via `applyRbacToOperation()` -- GET uses `readGroups`, POST uses `createGroups`, PUT uses `updateGroups`, DELETE uses `deleteGroups`. `PermissionHandler` (`lib/Service/Object/PermissionHandler.php`) enforces schema-level RBAC with admin bypass, owner privileges, public/authenticated pseudo-groups, and conditional matching with `$organisation` variable resolution. `PropertyRbacHandler` (`lib/Service/PropertyRbacHandler.php`) enforces property-level RBAC with `canReadProperty()`, `canUpdateProperty()`, `filterReadableProperties()`, and `getUnauthorizedProperties()`. `MagicRbacHandler` (`lib/Db/MagicMapper/MagicRbacHandler.php`) applies RBAC as SQL WHERE clauses with dynamic variable resolution (`$organisation`, `$userId`, `$now`), operator conditions (`$eq/$ne/$gt/$gte/$lt/$lte/$in/$nin/$exists`), multi-tenancy bypass detection, and raw SQL generation for UNION queries. `ConditionMatcher` (`lib/Service/ConditionMatcher.php`) evaluates conditional authorization rules with operator delegation to `OperatorEvaluator`. `SecurityService` (`lib/Service/SecurityService.php`) provides rate limiting and security event logging. `AuthorizationService` (`lib/Service/AuthorizationService.php`) handles JWT, Basic Auth, OAuth2, and API key authentication, resolving all methods to Nextcloud users. `Consumer` (`lib/Db/Consumer.php`) maps API consumers to Nextcloud users. `BaseOas.json` (`lib/Service/Resources/BaseOas.json`) provides the foundation with `basicAuth` and `oauth2` security schemes. `Schema` entity (`lib/Db/Schema.php`) provides `getAuthorization()`, `hasPropertyAuthorization()`, `getPropertyAuthorization()`, and `getPropertiesWithAuthorization()` for authorization configuration access.

**Nextcloud Core Integration**: The RBAC scopes system maps Nextcloud group memberships directly to OAuth2 scopes in the generated OpenAPI specification. This creates a bridge between Nextcloud's native group-based access control (managed via `OCP\IGroupManager`) and standard OAuth2 scope semantics understood by external API consumers. When a Consumer entity authenticates via JWT or API key, it is resolved to a Nextcloud user via `Consumer::getUserId()`, and that user's group memberships determine the effective scopes. The MCP discovery endpoint also exposes these scopes, enabling OAuth2 clients to understand available permissions. This approach is consistent with how Nextcloud itself handles app-level permissions through group restrictions. SSO-provisioned groups (SAML, OIDC, LDAP) work immediately without any OpenRegister-specific synchronisation.

**Recommendation**: The RBAC-to-OAuth2 scope mapping is fully implemented and provides excellent interoperability between Nextcloud's group system and standard API authorization patterns. Minor enhancements could include: (1) exposing available scopes in Nextcloud's capabilities API for programmatic discovery, (2) adding a dedicated permission matrix UI for administrators, (3) implementing register-level default authorization that cascades to schemas without explicit authorization, and (4) adding explicit audit log entries for RBAC policy changes (currently only object-level audit trails exist).

### Current Implementation Status
- **Fully implemented -- OAS scope generation**: `OasService::extractSchemaGroups()` extracts groups from both schema-level and property-level authorization blocks. `extractGroupFromRule()` handles simple string and conditional object rules. `getScopeDescription()` generates human-readable descriptions. `createOas()` populates `components.securitySchemes.oauth2.flows.authorizationCode.scopes` dynamically.
- **Fully implemented -- per-operation security**: `OasService::applyRbacToOperation()` adds operation-level `security` blocks mapping HTTP methods to CRUD authorization groups. Admin is always included.
- **Fully implemented -- schema-level RBAC**: `PermissionHandler` with `hasPermission()`, `checkPermission()`, `hasGroupPermission()`, `getAuthorizedGroups()`, and `evaluateMatchConditions()`.
- **Fully implemented -- property-level RBAC**: `PropertyRbacHandler` with `canReadProperty()`, `canUpdateProperty()`, `filterReadableProperties()`, `getUnauthorizedProperties()`, and conditional rule evaluation via `ConditionMatcher`.
- **Fully implemented -- database-level RBAC**: `MagicRbacHandler` with `applyRbacFilters()` (QueryBuilder), `buildRbacConditionsSql()` (raw SQL for UNION), `hasPermission()` (validation), `hasConditionalRulesBypassingMultitenancy()`, and full operator/variable support.
- **Fully implemented -- scope caching**: `MagicRbacHandler.$cachedActiveOrg`, `ConditionMatcher.$cachedActiveOrg`, `OasService.$schemaRbacMap`.
- **Fully implemented -- multi-tenancy integration**: `MagicRbacHandler::hasConditionalRulesBypassingMultitenancy()` detects when RBAC conditionals should override multi-tenancy filtering.
- **Fully implemented -- consumer identity mapping**: `Consumer` entity with `userId` field, `AuthorizationService` resolving all auth methods to Nextcloud users.
- **Partially implemented -- scope audit**: `PermissionHandler::getAuthorizedGroups()` provides per-schema audit; OAS provides machine-readable audit; explicit RBAC policy change audit logging is not implemented.
- **Not implemented -- register-level default authorization**: Schemas without explicit authorization default to open access; no register-level cascade mechanism exists.
- **Not implemented -- permission matrix UI**: No admin UI for visualising schemas vs. groups with CRUD checkboxes.
- **Not implemented -- scope migration tooling**: No automated handling when Nextcloud groups are renamed; manual schema authorization updates required.

### Standards & References
- **OAuth 2.0 (RFC 6749)** -- Authorization framework for scope-based access control
- **OpenAPI Specification 3.1.0** -- Security scheme definitions and per-operation security requirements
- **ZGW Autorisaties API (VNG)** -- Dutch government authorization patterns and scope naming conventions
- **Nextcloud Group-based access control** -- `OCP\IGroupManager` for underlying authorization model
- **ABAC (NIST SP 800-162)** -- Attribute-Based Access Control for conditional rule evaluation
- **BIO (Baseline Informatiebeveiliging Overheid)** -- Dutch government baseline information security requirements
- **RBAC (NIST)** -- Role-Based Access Control model for role hierarchy and permission management

### Cross-References
- **`auth-system`** -- Defines the authentication flow (JWT, Basic Auth, API key, OAuth2, SSO) that resolves identities before RBAC evaluation; the scope model depends on authenticated identity
- **`rbac-zaaktype`** -- Implements schema-level RBAC per zaaktype/objecttype; uses `PermissionHandler` and `MagicRbacHandler` defined here
- **`row-field-level-security`** -- Extends the authorization model with row-level (conditional matching) and field-level (PropertyRbacHandler) security; scopes capture the group requirements but not the runtime conditions
