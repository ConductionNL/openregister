---
status: partial
---
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
