---
status: draft
---
# RBAC Scopes

## Purpose
Validate and extend OpenRegister's existing three-level RBAC system. The core RBAC is already implemented via PermissionHandler (schema-level), MagicRbacHandler (row-level SQL filtering), and PropertyRbacHandler (field-level).

## ADDED Requirements


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

