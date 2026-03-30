# row-field-level-security Specification

## Purpose
Implement dynamic per-record access rules based on field values and per-field visibility rules based on user roles. Beyond schema-level RBAC, the system MUST support row-level security (RLS) where access to individual objects depends on the object's own properties (e.g., department, classification level), and field-level security (FLS) where different users see different fields of the same object.

**Source**: Gap identified in cross-platform analysis; two platforms implement row/field-level security.

## ADDED Requirements

### Requirement: Schemas MUST support row-level security rules
Schema definitions MUST accept row-level security rules that filter objects based on the current user's context and the object's field values.

#### Scenario: Restrict access by department field
- GIVEN schema `meldingen` with RLS rule: `user.group CONTAINS object.afdeling`
- AND melding `melding-1` has `afdeling: "sociale-zaken"`
- AND user `jan` is in group `sociale-zaken`
- AND user `pieter` is in group `ruimtelijke-ordening`
- WHEN both users list meldingen
- THEN `jan` MUST see `melding-1`
- AND `pieter` MUST NOT see `melding-1`

#### Scenario: Restrict access by classification level
- GIVEN schema `documenten` with RLS rule: `user.clearanceLevel >= object.vertrouwelijkheid`
- AND document `doc-1` has `vertrouwelijkheid: 3`
- AND user `behandelaar` has `clearanceLevel: 2`
- AND user `manager` has `clearanceLevel: 4`
- WHEN both users query the document
- THEN `behandelaar` MUST receive HTTP 403 for `doc-1`
- AND `manager` MUST be able to access `doc-1`

#### Scenario: Owner-based access
- GIVEN schema `aanvragen` with RLS rule: `user.id == object.eigenaar OR user.group == "admin"`
- AND aanvraag `aanvraag-1` has `eigenaar: "jan"`
- WHEN user `jan` accesses `aanvraag-1`, access MUST be granted
- AND when user `pieter` (non-admin) accesses `aanvraag-1`, access MUST be denied
- AND when user `admin-1` (in admin group) accesses `aanvraag-1`, access MUST be granted

### Requirement: RLS rules MUST apply to all access methods
Row-level security MUST be enforced on REST API, GraphQL, search results, exports, and the UI.

#### Scenario: RLS in search results
- **WHEN** user `jan` (sociale-zaken) searches for meldingen
- **THEN** only meldingen where `afdeling: "sociale-zaken"` MUST appear in results
- **AND** facet counts MUST reflect only the accessible objects

#### Scenario: RLS in data export
- **WHEN** user `jan` exports meldingen to CSV
- **THEN** the export MUST only contain objects passing the RLS rules
- **AND** the export MUST NOT include objects from other departments

#### Scenario: RLS in GraphQL queries
- **WHEN** user `jan` (sociale-zaken) queries `meldingen { title afdeling }` via GraphQL
- **THEN** only meldingen where `afdeling: "sociale-zaken"` MUST be returned
- **AND** the RLS filter MUST be applied at the MagicRbacHandler query level before GraphQL resolvers execute
- **AND** facets requested in the GraphQL connection MUST reflect only RLS-accessible objects

#### Scenario: RLS in GraphQL mutations
- **WHEN** user `pieter` (ruimtelijke-ordening) attempts `updateMelding(id: "melding-1")` on a melding with `afdeling: "sociale-zaken"`
- **THEN** the mutation MUST be rejected with `extensions.code: "FORBIDDEN"`
- **AND** the RLS denial MUST be logged to the audit trail

#### Scenario: RLS in GraphQL nested resolution
- **WHEN** user `jan` queries `dossier { meldingen { title } }` and some nested meldingen fail RLS
- **THEN** only RLS-passing meldingen MUST appear in the nested array
- **AND** no error MUST be raised for filtered-out items (silently excluded, matching list behavior)

### Requirement: Schemas MUST support field-level security
Individual properties MUST be configurable with visibility rules based on user roles.

#### Scenario: Hide sensitive field from basic users
- **WHEN** schema `inwoners` has property `bsn` visible only to group `bsn-geautoriseerd`
- **AND** user `medewerker-1` is NOT in `bsn-geautoriseerd`
- **THEN** the `bsn` field MUST be omitted from REST responses
- **AND** in GraphQL, `bsn` MUST resolve to `null` with a partial error at path `["inwoner", "bsn"]` with `extensions.code: "FIELD_FORBIDDEN"`

#### Scenario: Show sensitive field to authorized users
- **WHEN** user `specialist` IS in `bsn-geautoriseerd`
- **THEN** the `bsn` field MUST be included in both REST and GraphQL responses

#### Scenario: Field-level security in list views
- **WHEN** user `medewerker-1` cannot read `bsn`
- **THEN** the `bsn` column MUST NOT appear in REST list responses
- **AND** in GraphQL list queries, `bsn` MUST resolve to `null` on each edge node with partial errors

#### Scenario: Field-level write protection in GraphQL mutations
- **WHEN** user `medewerker-1` is NOT in group `redacteuren`
- **AND** they attempt `updateInwoner(id: "...", input: { interneAantekening: "text" })`
- **THEN** the mutation MUST be rejected with `extensions.code: "FIELD_FORBIDDEN"`
- **AND** `PropertyRbacHandler::getUnauthorizedProperties()` MUST be called to determine the blocked fields

### Requirement: RLS rules MUST support the $CURRENT_USER context variable
Rules MUST be able to reference the current user's properties (ID, groups, custom attributes).

#### Scenario: Use $CURRENT_USER in rule
- GIVEN an RLS rule: `object.assignedTo == $CURRENT_USER.id`
- WHEN user `jan` (ID: `jan`) queries objects
- THEN only objects where `assignedTo` equals `jan` MUST be returned

### Requirement: RLS and FLS MUST be combinable with schema-level RBAC
Row and field-level security MUST be additive to (not replacing) schema-level RBAC.

#### Scenario: Combined RBAC + RLS
- GIVEN schema `meldingen` with RBAC allowing group `behandelaars` to read
- AND RLS rule: `object.afdeling IN user.groups`
- WHEN user `jan` (in `behandelaars` and `sociale-zaken`) queries
- THEN RBAC check MUST pass (jan is in behandelaars)
- AND RLS MUST further filter to only sociale-zaken meldingen

### Requirement: Security rules MUST be auditable
All access decisions (grant/deny) based on RLS/FLS MUST be loggable for compliance.

#### Scenario: Log RLS denial
- GIVEN RLS denies user `pieter` access to `melding-1`
- WHEN logging is enabled for access decisions
- THEN a log entry MUST record: user, object, rule that denied access, timestamp

### Current Implementation Status

**Partially implemented.** Schema-level RBAC and some row/field-level security foundations exist:

**Implemented (RBAC foundation):**
- `lib/Db/MagicMapper/MagicRbacHandler.php` -- RBAC handler for magic table queries, applies authorization rules as SQL WHERE clauses
- `lib/Db/Schema.php` -- Schema entity supports `authorization` JSON property with per-action rules (read, create, update, delete)
- `lib/Db/ObjectEntity.php` -- Objects support per-object `authorization` override (line ~216: `protected ?array $authorization = []`)
- `lib/Service/Object/SaveObject.php` -- RBAC checks during save operations
- RBAC rules support `$CURRENT_USER`-like context via dynamic variable resolution (e.g., `$now` in `MagicRbacHandler`)
- Condition matching with operators (`$lte`, `$gte`, `$in`, etc.) for field-value comparisons
- Group-based access control (user groups matched against schema authorization rules)

**Partially implemented (row-level):**
- Object-level `authorization` field allows per-object access rules (a form of RLS)
- `MagicRbacHandler` can filter queries based on field values matching user context (basic RLS)
- `MagicOrganizationHandler` provides organisation-based row filtering (multi-tenancy)

**Not implemented:**
- Configurable RLS rules on schema definition (e.g., `user.group CONTAINS object.afdeling`)
- `$CURRENT_USER` context variable with full user properties (ID, groups, custom attributes)
- Field-level security (FLS) -- hiding specific fields from unauthorized users
- FLS in list view column visibility
- RLS in search results with filtered facet counts
- RLS in data exports
- Audit logging of access decisions (grant/deny)
- Combined RBAC + RLS + FLS evaluation chain

### Standards & References
- PostgreSQL Row-Level Security (RLS) model -- conceptual reference for row-level filtering
- ABAC (Attribute-Based Access Control) -- NIST SP 800-162
- Dutch BIO (Baseline Informatiebeveiliging Overheid) -- baseline information security for government
- WCAG 2.1 AA -- accessible display of security-restricted content
- RBAC (Role-Based Access Control) -- NIST RBAC model

### Specificity Assessment
- **Specific enough to implement?** Partially -- the scenarios are clear, but the rule definition language is underspecified.
- **Missing/ambiguous:**
  - No formal grammar for RLS rule expressions (e.g., `user.group CONTAINS object.afdeling` -- is this a custom DSL?)
  - No specification for how `$CURRENT_USER` properties are populated (Nextcloud user vs. OpenRegister profile?)
  - No specification for rule evaluation performance (indexed queries vs. post-fetch filtering)
  - No specification for FLS interaction with API responses (omit field vs. return null vs. return redacted marker)
  - No specification for how RLS/FLS rules are configured in the admin UI
  - No specification for rule conflict resolution (if multiple rules apply, which takes precedence?)
- **Open questions:**
  - Should RLS rules be evaluated in SQL (MagicMapper) or in PHP (post-fetch filtering)?
  - How should FLS interact with GraphQL field selection?
  - Should `clearanceLevel` be a Nextcloud user attribute or an OpenRegister user profile property?

## Nextcloud Integration Analysis

**Status**: Implemented

**Existing Implementation**: PropertyRbacHandler provides field-level security by controlling property visibility based on user group membership. MagicRbacHandler enforces row-level security at the SQL query level, applying authorization rules as WHERE clauses in MagicMapper queries. DataAccessProfile entity defines access profiles that combine property visibility rules with org-scoped access. Schema entities support authorization JSON with per-action rules (read, create, update, delete), and ObjectEntity supports per-object authorization overrides. Condition matching with operators ($lte, $gte, $in, etc.) enables sophisticated field-value comparisons. MagicOrganizationHandler provides organisation-based row filtering for multi-tenancy.

**Nextcloud Core Integration**: The RBAC system is deeply integrated with Nextcloud's group system. User group memberships (managed via OCP\IGroupManager) are the primary mechanism for role mapping. When a user belongs to Nextcloud group "sociale-zaken", the MagicRbacHandler automatically filters query results to only show objects where the authorization rules permit that group. This happens at the database query level, not post-fetch, ensuring performance at scale. The PropertyRbacHandler uses the same group system to determine which fields a user can see, omitting restricted properties from API responses. The admin group receives automatic bypass, consistent with Nextcloud's admin privilege model.

**Recommendation**: The row-level and field-level security implementation is well-integrated with Nextcloud's group infrastructure and enforced at the query level in MagicMapper for performance. The enforcement in MagicRbacHandler ensures that all access methods (REST, GraphQL, search, export) consistently apply the same security rules. To strengthen the integration, ensure that RLS rules support $CURRENT_USER context resolution using IUserSession::getUser() for dynamic user property access beyond group membership. Consider logging access decisions (grant/deny) to Nextcloud's audit log (OCP\Log\ILogFactory) for compliance visibility. The DataAccessProfile entity could be exposed in the Nextcloud admin settings for easier management alongside Nextcloud's native group administration.
