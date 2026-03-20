# RBAC Scopes

## Purpose
Validate and extend OpenRegister's existing three-level RBAC system. The core RBAC is already implemented via PermissionHandler (schema-level), MagicRbacHandler (row-level SQL filtering), and PropertyRbacHandler (field-level). This spec documents the existing behavior as requirements and identifies extensions needed for scope management APIs, caching, and audit. Specifically, it maps the existing hierarchical RBAC model (register, schema, object, property) to standard OAuth2 scopes in the generated OpenAPI Specification, and validates that per-operation security requirements are correctly enforced so that API consumers can discover and request the precise group-based permissions they need. The scope system bridges Nextcloud's native group management with standardised OAuth2/OAS security semantics, enabling external API consumers, ZGW-compliant systems, and MCP clients to understand and negotiate access programmatically.

**Source**: Core OpenRegister capability; 67% of tenders require SSO/identity integration; 86% require RBAC per zaaktype; ZGW Autorisaties API compliance.

## Relationship to Existing Implementation
This spec primarily documents and validates existing functionality, with targeted extensions:

- **Schema-level RBAC (fully implemented)**: `PermissionHandler` with `hasPermission()`, `checkPermission()`, `hasGroupPermission()`, `getAuthorizedGroups()`, and `evaluateMatchConditions()` — all requirements in this spec validate existing behavior.
- **Property-level RBAC (fully implemented)**: `PropertyRbacHandler` with `canReadProperty()`, `canUpdateProperty()`, `filterReadableProperties()`, and `getUnauthorizedProperties()` with conditional rule evaluation via `ConditionMatcher`.
- **Database-level RBAC (fully implemented)**: `MagicRbacHandler` with `applyRbacFilters()` (QueryBuilder), `buildRbacConditionsSql()` (raw SQL for UNION), dynamic variable resolution (`$organisation`, `$userId`, `$now`), and full operator support.
- **OAS scope generation (fully implemented)**: `OasService::extractSchemaGroups()` extracts groups from authorization blocks, `getScopeDescription()` generates descriptions, `applyRbacToOperation()` adds per-operation security blocks.
- **Scope caching (fully implemented)**: `MagicRbacHandler.$cachedActiveOrg`, `ConditionMatcher.$cachedActiveOrg`, `OasService.$schemaRbacMap`.
- **Consumer identity mapping (fully implemented)**: `Consumer` entity with `userId` field, `AuthorizationService` resolving all auth methods to Nextcloud users.
- **What this spec adds as extensions**: Register-level default authorization cascade, permission matrix UI for administrators, scope migration tooling for group renames, and explicit RBAC policy change audit logging.

## Requirements

### Requirement: Scope Model Hierarchy (Register > Schema > Object > Property)
The RBAC scope model SHALL follow a four-level hierarchy: register-level scopes govern access to an entire register, schema-level scopes control CRUD operations per schema (zaaktype/objecttype), object-level scopes apply to individual records via conditional matching, and property-level scopes restrict visibility and mutability of specific fields. Each level MUST be independently configurable via the `authorization` JSON structure on the Schema entity.

#### Scenario: Schema-level authorization defines CRUD scopes
- **GIVEN** schema `bezwaarschriften` has authorization: `{ "read": ["juridisch-team"], "create": ["juridisch-team"], "update": ["juridisch-team"], "delete": ["admin"] }`
- **WHEN** OAS is generated for the register containing this schema
- **THEN** the scopes `juridisch-team` and `admin` MUST appear in `components.securitySchemes.oauth2.flows.authorizationCode.scopes`
- **AND** the GET endpoints MUST list `juridisch-team` in their `security` requirements
- **AND** the DELETE endpoint MUST list `admin` in its `security` requirements

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
- **GIVEN** schema `tags` has no `authorization` block (null or empty)
- **WHEN** `OasService::extractSchemaGroups()` processes this schema
- **THEN** `createGroups`, `readGroups`, `updateGroups`, and `deleteGroups` MUST all be empty arrays
- **AND** the schema's endpoints MUST NOT have operation-level `security` overrides
- **AND** the global-level security definition at the OAS document root SHALL apply

#### Scenario: Scope hierarchy is flattened for OAS (no nesting)
- **GIVEN** a register with 3 schemas, each having different group rules at schema-level and property-level
- **WHEN** OAS is generated
- **THEN** all unique group names across all schemas and properties MUST be collected into a single flat `scopes` object in `components.securitySchemes.oauth2.flows.authorizationCode.scopes`
- **AND** duplicate group names MUST be deduplicated (each group appears only once)

### Requirement: Permission Types (read, create, update, delete, list)
The system MUST support five distinct permission types in authorization rules: `read` (get a single object), `create` (post a new object), `update` (put/patch an existing object), `delete` (remove an object), and implicitly `list` (query a collection, treated as `read` in the current implementation). Each permission type MUST map to the corresponding HTTP method in the generated OAS security requirements.

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
