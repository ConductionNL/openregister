---
status: done
---

# Row and Field Level Security

## Purpose

@e2e exclude backend RLS/FLS enforcement — covered by PHPUnit
Implement dynamic per-record access rules based on field values (row-level security / RLS) and per-field visibility and editability rules based on user roles (field-level security / FLS). Beyond schema-level RBAC that controls access to entire object types, the system MUST support row-level security where access to individual objects depends on the object's own properties (e.g., department, classification level, owner), and field-level security where different users see different fields of the same object. Both security layers MUST be enforced consistently across REST, GraphQL, search, export, and MCP access methods, evaluated at the database query level where possible for performance, and composable with schema-level RBAC and multi-tenancy isolation.

**Source**: Gap identified in cross-platform analysis; Directus implements comprehensive row/field-level security with filter-based permissions and dynamic variables ($CURRENT_USER, $CURRENT_ROLE, $NOW). NocoDB provides view-level permissions. 86% of analyzed government tenders require RBAC per zaaktype; 67% require SSO/identity integration with fine-grained data compartmentalization.
## Requirements
### Requirement: Schemas MUST support row-level security rules via conditional authorization matching
Schema authorization blocks MUST accept conditional rules that filter objects based on the current user's context (group membership, identity, organisation) and the object's own field values. Conditional rules use the structure `{ "group": "<group>", "match": { "<property>": "<value-or-operator>" } }` where the user must qualify for the group AND the object must satisfy all match conditions.

#### Scenario: Restrict access by department field using group + match
- **GIVEN** schema `meldingen` has authorization: `{ "read": [{ "group": "behandelaars", "match": { "afdeling": "sociale-zaken" } }] }`
- **AND** user `jan` is in group `behandelaars`
- **AND** melding `melding-1` has `afdeling: "sociale-zaken"`
- **AND** melding `melding-2` has `afdeling: "ruimtelijke-ordening"`
- **WHEN** `jan` lists meldingen
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST add a SQL WHERE clause: `t.afdeling = 'sociale-zaken'`
- **AND** `jan` MUST see `melding-1` but NOT `melding-2`
- **AND** filtering MUST happen at the database query level (not post-fetch)

#### Scenario: Restrict access by classification level using operator conditions
- **GIVEN** schema `documenten` has authorization: `{ "read": [{ "group": "medewerkers", "match": { "vertrouwelijkheid": { "$lte": 2 } } }] }`
- **AND** document `doc-1` has `vertrouwelijkheid: 3`
- **AND** document `doc-2` has `vertrouwelijkheid: 1`
- **AND** user `behandelaar` is in group `medewerkers`
- **WHEN** `behandelaar` queries documenten
- **THEN** `MagicRbacHandler::buildOperatorCondition()` MUST generate SQL: `t.vertrouwelijkheid <= 2`
- **AND** `behandelaar` MUST see `doc-2` but NOT `doc-1`

#### Scenario: Owner-based access via $userId dynamic variable
- **GIVEN** schema `aanvragen` has authorization: `{ "read": [{ "group": "authenticated", "match": { "eigenaar": "$userId" } }] }`
- **AND** aanvraag `aanvraag-1` has `eigenaar: "jan"`
- **WHEN** user `jan` (UID: `jan`) queries aanvragen
- **THEN** `MagicRbacHandler::resolveDynamicValue('$userId')` MUST return `jan` via `$this->userSession->getUser()->getUID()`
- **AND** the SQL condition MUST be `t.eigenaar = 'jan'`
- **AND** user `pieter` MUST NOT see `aanvraag-1`

#### Scenario: Object owner always has access regardless of RLS rules
- **GIVEN** schema `meldingen` has authorization with restrictive match conditions
- **AND** user `jan` created object `melding-1` (object owner = `jan`)
- **WHEN** `jan` queries meldingen
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST include `t._owner = 'jan'` as an OR condition alongside the match conditions
- **AND** `jan` MUST see `melding-1` even if the match conditions would otherwise exclude it

#### Scenario: Multiple authorization rules evaluated with OR logic
- **GIVEN** schema `zaken` has authorization: `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }, { "group": "managers", "match": { "status": "escalated" } }] }`
- **AND** user `jan` is in group `behandelaars` with active organisation `org-1`
- **AND** user `jan` is NOT in group `managers`
- **WHEN** `jan` queries zaken
- **THEN** only the first rule MUST apply (group match succeeds for `behandelaars`)
- **AND** the SQL MUST filter on `t._organisation = 'org-1'`
- **AND** escalated zaken from other organisations MUST NOT be visible to `jan`

### Requirement: RLS rules MUST support dynamic variable resolution in match conditions
Match conditions MUST support dynamic variables that resolve at runtime to the current user's context. The system MUST support `$userId` / `$user` (current user UID), `$organisation` / `$activeOrganisation` (current user's active organisation UUID), and `$now` (current datetime). Variables MUST be resolved consistently in both `MagicRbacHandler` (SQL-level) and `ConditionMatcher` (PHP-level) evaluation paths.

#### Scenario: Organisation-scoped access via $organisation variable
- **GIVEN** schema `dossiers` has authorization: `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **AND** user `jan` is in group `behandelaars` with active organisation UUID `abc-123`
- **WHEN** `jan` queries dossiers
- **THEN** `MagicRbacHandler::resolveDynamicValue('$organisation')` MUST return `abc-123` via `OrganisationService::getActiveOrganisation()->getUuid()`
- **AND** the SQL condition MUST be `t._organisation = 'abc-123'`
- **AND** the resolved organisation UUID MUST be cached in `$this->cachedActiveOrg` for subsequent calls within the same request

#### Scenario: Time-based access via $now variable with operator
- **GIVEN** schema `publicaties` has authorization: `{ "read": [{ "group": "public", "match": { "publishDate": { "$lte": "$now" } } }] }`
- **WHEN** an unauthenticated user queries publicaties at `2026-03-19 14:30:00`
- **THEN** `MagicRbacHandler::resolveDynamicValue('$now')` MUST return `2026-03-19 14:30:00` (Y-m-d H:i:s format)
- **AND** `ConditionMatcher::resolveDynamicValue('$now')` MUST return the ISO 8601 equivalent
- **AND** only publicaties with `publish_date <= '2026-03-19 14:30:00'` MUST be returned

#### Scenario: Unresolvable dynamic variable denies access safely
- **GIVEN** a match condition using `$organisation` but the user has no active organisation
- **WHEN** `MagicRbacHandler::resolveDynamicValue('$organisation')` returns `null`
- **THEN** `MagicRbacHandler::buildPropertyCondition()` MUST return `null` for that condition
- **AND** the rule MUST NOT grant access (fail-closed behavior)

#### Scenario: User-scoped access via $userId in ConditionMatcher
- **GIVEN** property `interneAantekening` has authorization: `{ "read": [{ "group": "authenticated", "match": { "_owner": "$userId" } }] }`
- **AND** object `obj-1` has `_owner: "jan"` and user `pieter` reads it
- **WHEN** `ConditionMatcher::objectMatchesConditions()` evaluates the match
- **THEN** `$userId` MUST resolve to `pieter` via `$this->userSession->getUser()->getUID()`
- **AND** the condition `_owner === "pieter"` MUST fail because `_owner` is `jan`
- **AND** `pieter` MUST NOT see the `interneAantekening` field

### Requirement: Schemas MUST support field-level security via property authorization blocks
Individual properties in a schema MUST support authorization rules that control read and update access per field. Property authorization uses the same rule structure as schema-level authorization: group names, `public`, `authenticated`, and conditional rules with match criteria. `PropertyRbacHandler` MUST enforce these rules by filtering outgoing data (`filterReadableProperties`) and validating incoming data (`getUnauthorizedProperties`).

#### Scenario: Hide sensitive field from unauthorized users in REST responses
- **GIVEN** schema `inwoners` has property `bsn` with authorization: `{ "read": [{ "group": "bsn-geautoriseerd" }], "update": [{ "group": "bsn-geautoriseerd" }] }`
- **AND** user `medewerker-1` is NOT in group `bsn-geautoriseerd`
- **WHEN** `medewerker-1` reads an inwoner object via REST API
- **THEN** `PropertyRbacHandler::filterReadableProperties()` MUST be called during `RenderObject` processing
- **AND** the `bsn` field MUST be omitted (via `unset($object[$propertyName])`) from the REST response
- **AND** all other fields without property-level authorization MUST still be returned

#### Scenario: Show sensitive field to authorized users
- **GIVEN** user `specialist` IS in group `bsn-geautoriseerd`
- **WHEN** `specialist` reads the same inwoner object
- **THEN** `PropertyRbacHandler::canReadProperty()` MUST return `true` for `bsn`
- **AND** the `bsn` field MUST be included in both REST and GraphQL responses

#### Scenario: Field-level security in list views
- **GIVEN** user `medewerker-1` cannot read property `bsn`
- **WHEN** `medewerker-1` lists inwoner objects via `GET /api/objects/{register}/{schema}`
- **THEN** `PropertyRbacHandler::filterReadableProperties()` MUST be applied to each object in the list
- **AND** the `bsn` field MUST NOT appear in any object in the response
- **AND** other fields MUST be returned normally for every object

#### Scenario: Field-level write protection blocks unauthorized property updates
- **GIVEN** user `medewerker-1` is NOT in group `redacteuren`
- **AND** property `interneAantekening` has authorization: `{ "update": [{ "group": "redacteuren" }] }`
- **WHEN** `medewerker-1` sends `PUT /api/objects/{register}/{schema}/{id}` with `{ "interneAantekening": "new text" }`
- **THEN** `PropertyRbacHandler::getUnauthorizedProperties()` MUST return `["interneAantekening"]`
- **AND** `SaveObject` MUST reject the request with a validation error listing the unauthorized properties

#### Scenario: Unchanged protected fields in PATCH operations are allowed
- **GIVEN** user `medewerker-1` sends a PATCH with `{ "interneAantekening": "existing-value", "status": "open" }`
- **AND** the existing object already has `interneAantekening: "existing-value"`
- **WHEN** `PropertyRbacHandler::getUnauthorizedProperties()` checks the incoming data
- **THEN** `interneAantekening` MUST be skipped because `$incomingData[$propertyName] === $object[$propertyName]`
- **AND** only `status` MUST be evaluated for update authorization
- **AND** the PATCH MUST succeed if `status` is writable

### Requirement: RLS rules MUST apply consistently to all access methods
Row-level security MUST be enforced identically across REST API, GraphQL queries and mutations, search results, data exports, and MCP operations. The enforcement point SHALL be `MagicRbacHandler::applyRbacFilters()` for database queries and `PermissionHandler::hasPermission()` with object data for individual object access checks.

#### Scenario: RLS in search results with filtered facet counts
- **GIVEN** schema `meldingen` with RLS rule restricting by `_organisation`
- **WHEN** user `jan` (org `org-1`) searches for meldingen
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST add WHERE clauses before `MagicSearchHandler` executes the search query
- **AND** only meldingen matching `_organisation = 'org-1'` MUST appear in search results
- **AND** `MagicFacetHandler` facet counts MUST reflect only the RLS-accessible subset of objects

#### Scenario: RLS in data export
- **GIVEN** user `jan` (org `org-1`) exports meldingen to CSV via `ExportService`
- **WHEN** the export query is built
- **THEN** RLS filters MUST be applied to the export query
- **AND** the CSV MUST only contain objects passing the RLS rules
- **AND** `ExportService` MUST also apply `PropertyRbacHandler::canReadProperty()` to filter columns from export headers

#### Scenario: RLS in GraphQL queries with silent filtering
- **GIVEN** user `jan` (org `org-1`) queries `{ meldingen { edges { node { title afdeling } } } }` via GraphQL
- **WHEN** `GraphQLResolver` builds the query
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST filter results at the SQL level
- **AND** only meldingen from `org-1` MUST appear in the edges
- **AND** no GraphQL error MUST be raised for filtered-out items (silently excluded, matching list behavior)

#### Scenario: RLS in GraphQL mutations rejects unauthorized objects
- **GIVEN** user `pieter` (org `org-2`) attempts `mutation { updateMelding(id: "melding-1", input: { status: "closed" }) { id } }`
- **AND** `melding-1` belongs to `org-1`
- **WHEN** `PermissionHandler::hasPermission()` checks with `objectData` containing `_organisation: "org-1"`
- **THEN** the mutation MUST be rejected because `pieter`'s organisation (`org-2`) does not match
- **AND** GraphQL MUST return an error with `extensions.code: "FORBIDDEN"`

#### Scenario: RLS in GraphQL nested resolution
- **GIVEN** user `jan` queries `{ dossier(id: "d-1") { meldingen { edges { node { title } } } } }`
- **AND** some nested meldingen fail RLS checks
- **WHEN** the nested meldingen are resolved
- **THEN** only RLS-passing meldingen MUST appear in the nested edges array
- **AND** no error MUST be raised for filtered-out nested items (silently excluded)

### Requirement: FLS MUST apply consistently to GraphQL field resolution
Field-level security in GraphQL MUST prevent unauthorized field access in queries and mutations, using `PropertyRbacHandler` as the single source of truth for property access decisions.

#### Scenario: FLS in GraphQL query returns null for restricted fields
- **GIVEN** schema `inwoners` has property `bsn` restricted to group `bsn-geautoriseerd`
- **AND** user `medewerker-1` is NOT in `bsn-geautoriseerd`
- **WHEN** `medewerker-1` queries `{ inwoner(id: "..") { naam bsn } }` via GraphQL
- **THEN** `PropertyRbacHandler::canReadProperty()` MUST return `false` for `bsn`
- **AND** `bsn` MUST resolve to `null` with a partial error at the field path with `extensions.code: "FIELD_FORBIDDEN"`
- **AND** `naam` MUST still return data (partial success)

#### Scenario: FLS in GraphQL mutation rejects writes to restricted fields
- **GIVEN** user `medewerker-1` is NOT in group `redacteuren`
- **AND** property `interneAantekening` requires group `redacteuren` for update
- **WHEN** `medewerker-1` attempts `mutation { updateInwoner(id: "...", input: { interneAantekening: "text" }) { id } }`
- **THEN** `PropertyRbacHandler::getUnauthorizedProperties()` MUST return `["interneAantekening"]`
- **AND** the mutation MUST be rejected with `extensions.code: "FIELD_FORBIDDEN"`

#### Scenario: FLS in GraphQL list queries filters fields on every edge node
- **GIVEN** user `medewerker-1` cannot read property `bsn`
- **WHEN** they query `{ inwoners { edges { node { naam bsn } } } }`
- **THEN** on each edge node, `bsn` MUST resolve to `null` with partial errors
- **AND** `naam` MUST return data on every node

### Requirement: The condition syntax MUST support MongoDB-style operators for match expressions
Match conditions in authorization rules MUST support the following operators via `OperatorEvaluator`: `$eq` (equals), `$ne` (not equals), `$gt` (greater than), `$gte` (greater than or equal), `$lt` (less than), `$lte` (less than or equal), `$in` (in array), `$nin` (not in array), `$exists` (field existence check). Multiple operators on the same property MUST be combined with AND logic. Multiple properties in the same match block MUST also be combined with AND logic.

#### Scenario: Equality operator with simple value
- **GIVEN** match condition `{ "status": "open" }`
- **WHEN** `MagicRbacHandler::buildPropertyCondition()` processes it
- **THEN** the SQL MUST be `t.status = 'open'`
- **AND** `ConditionMatcher::singleConditionMatches()` MUST compare `$objectValue === 'open'`

#### Scenario: Greater-than-or-equal operator for clearance level
- **GIVEN** match condition `{ "vertrouwelijkheid": { "$lte": 3 } }`
- **WHEN** `MagicRbacHandler::buildComparisonOperatorCondition()` processes it
- **THEN** the SQL MUST be `t.vertrouwelijkheid <= 3`
- **AND** `OperatorEvaluator::operatorLessThanOrEqual()` MUST return `$value <= 3`

#### Scenario: In-array operator for multiple allowed values
- **GIVEN** match condition `{ "type": { "$in": ["melding", "klacht", "suggestie"] } }`
- **WHEN** `MagicRbacHandler::buildArrayOperatorCondition()` processes it
- **THEN** the SQL MUST be `t.type IN ('melding', 'klacht', 'suggestie')`
- **AND** `OperatorEvaluator::operatorIn()` MUST check `in_array($value, $operand, true)`

#### Scenario: Existence operator for optional fields
- **GIVEN** match condition `{ "assignedTo": { "$exists": true } }`
- **WHEN** `MagicRbacHandler::buildSingleOperatorCondition()` processes it
- **THEN** the SQL MUST be `t.assigned_to IS NOT NULL`
- **AND** `OperatorEvaluator::operatorExists()` MUST return `false` when value is `null`

#### Scenario: Combined operators with AND logic
- **GIVEN** match condition `{ "_organisation": "$organisation", "status": "open", "priority": { "$gte": 3 } }`
- **WHEN** `MagicRbacHandler::buildMatchConditions()` processes it
- **THEN** all three conditions MUST be combined with AND via `$qb->expr()->andX()`
- **AND** all three conditions MUST be satisfied for an object to match

### Requirement: RLS and FLS MUST be combinable with schema-level RBAC in a layered evaluation chain
Row-level and field-level security MUST be additive to (not replacing) schema-level RBAC. The evaluation order MUST be: (1) schema-level RBAC via `PermissionHandler` checks if the user's group has any access to the schema at all, (2) row-level security via `MagicRbacHandler` filters which objects the user can see based on match conditions, (3) field-level security via `PropertyRbacHandler` filters which properties the user can see or modify within each accessible object.

#### Scenario: Combined schema + row + field-level RBAC
- **GIVEN** schema `dossiers` with:
  - Schema-level auth: `{ "read": ["behandelaars"] }`
  - Row-level match: `{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }`
  - Property-level auth on `interneAantekening`: `{ "read": ["redacteuren"] }`
- **AND** user `jan` is in `behandelaars`, NOT in `redacteuren`, org `org-1`
- **WHEN** `jan` reads dossiers
- **THEN** `PermissionHandler::hasPermission('read')` MUST pass (jan is in behandelaars)
- **AND** `MagicRbacHandler::applyRbacFilters()` MUST filter to org `org-1` objects only
- **AND** `PropertyRbacHandler::filterReadableProperties()` MUST strip `interneAantekening` from each returned object

#### Scenario: Schema-level denial prevents RLS evaluation
- **GIVEN** schema `vertrouwelijk` with schema-level auth: `{ "read": ["directie"] }`
- **AND** user `medewerker-1` is NOT in `directie`
- **WHEN** `medewerker-1` attempts to list objects
- **THEN** `PermissionHandler::checkPermission()` MUST throw an exception with message containing "does not have permission to 'read'"
- **AND** `MagicRbacHandler` MUST NOT be invoked (schema-level denial short-circuits)
- **AND** the HTTP response MUST be 403 Forbidden

#### Scenario: Admin group bypasses all three security layers
- **GIVEN** a user in the Nextcloud `admin` group
- **WHEN** they access any schema with RLS and FLS rules
- **THEN** `PermissionHandler::hasPermission()` MUST return `true` immediately
- **AND** `MagicRbacHandler::applyRbacFilters()` MUST return without adding WHERE clauses
- **AND** `PropertyRbacHandler::filterReadableProperties()` MUST return the object unmodified

### Requirement: RLS condition evaluation MUST happen at the SQL query level for performance
Row-level security conditions MUST be translated to SQL WHERE clauses by `MagicRbacHandler` and applied at the database query level, not as post-fetch PHP filtering. This ensures that unauthorized objects are never loaded into PHP memory, pagination counts reflect only accessible objects, and query performance is O(accessible rows) not O(total rows).

#### Scenario: RLS generates SQL WHERE clauses via QueryBuilder
- **GIVEN** schema `meldingen` with conditional rule `{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }`
- **AND** user `jan` is in `behandelaars` with active org `org-1`
- **WHEN** `MagicRbacHandler::applyRbacFilters()` processes the rule
- **THEN** `processConditionalRule()` MUST detect user qualifies for the group
- **AND** `buildMatchConditions()` MUST build the SQL condition via `$qb->expr()->eq('t._organisation', $qb->createNamedParameter('org-1'))`
- **AND** the condition MUST be applied via `$qb->andWhere($qb->expr()->orX(ownerCondition, matchCondition))`

#### Scenario: RLS generates raw SQL for UNION queries
- **GIVEN** a cross-schema search query using UNION across multiple magic tables
- **WHEN** `MagicRbacHandler::buildRbacConditionsSql()` is called for each schema
- **THEN** it MUST return `['bypass' => false, 'conditions' => ["_organisation = 'org-1'"]]`
- **AND** the conditions MUST be injected as WHERE clauses in the raw SQL UNION
- **AND** values MUST be properly escaped via `quoteValue()` to prevent SQL injection

#### Scenario: Pagination counts reflect only accessible objects
- **GIVEN** 100 meldingen total, 30 belonging to org `org-1`
- **WHEN** user `jan` (org `org-1`) requests page 1 with limit 10
- **THEN** the total count MUST be 30 (not 100)
- **AND** only 10 objects from the accessible 30 MUST be returned
- **AND** the `_pagination` metadata MUST show `total: 30`

#### Scenario: Denial produces impossible SQL condition
- **GIVEN** user `pieter` has no matching rules (not in any authorized group)
- **WHEN** `MagicRbacHandler::applyRbacFilters()` finds no valid conditions
- **THEN** it MUST add the impossible condition `$qb->expr()->eq($qb->createNamedParameter(1), $qb->createNamedParameter(0))`
- **AND** the query MUST return zero results
- **AND** no objects MUST be loaded into PHP memory

### Requirement: RLS MUST interact correctly with multi-tenancy isolation
When both RLS conditional rules and multi-tenancy isolation are active, the system MUST avoid double-filtering on organisation. `MagicRbacHandler::hasConditionalRulesBypassingMultitenancy()` MUST detect when RBAC rules contain conditional matching on non-`_organisation` fields and bypass the separate multi-tenancy filter to prevent conflict.

#### Scenario: RBAC with non-organisation match fields bypasses multi-tenancy
- **GIVEN** schema `catalogi` has RBAC rule: `{ "read": [{ "group": "beheerders", "match": { "aanbieder": "$organisation" } }] }`
- **AND** user `jan` is in `beheerders`
- **WHEN** `MagicRbacHandler::hasConditionalRulesBypassingMultitenancy()` evaluates the rules
- **THEN** `matchHasNonOrganisationFields()` MUST detect field `aanbieder` (not `_organisation`)
- **AND** the multi-tenancy WHERE clause MUST be skipped
- **AND** RBAC MUST handle access control via `t.aanbieder = 'org-uuid'`

#### Scenario: RBAC with only _organisation match does NOT bypass multi-tenancy
- **GIVEN** schema `dossiers` has RBAC rule: `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **WHEN** `hasConditionalRulesBypassingMultitenancy()` evaluates
- **THEN** `matchHasNonOrganisationFields()` MUST return `false` (only `_organisation` field)
- **AND** multi-tenancy filtering MAY remain active (RBAC and multi-tenancy produce equivalent filtering)

#### Scenario: Simple group rules bypass multi-tenancy
- **GIVEN** schema `producten` has RBAC rule: `{ "read": ["public"] }`
- **AND** user `jan` qualifies for `public`
- **WHEN** `hasConditionalRulesBypassingMultitenancy()` evaluates
- **THEN** `simpleRuleBypassesMultitenancy('public')` MUST return `true`
- **AND** multi-tenancy filtering MUST be bypassed (user has unconditional access)

### Requirement: FLS MUST strip restricted fields from API responses and export outputs
When `PropertyRbacHandler::filterReadableProperties()` determines a user cannot read a property, that property MUST be completely omitted from REST API responses (not returned as `null` or redacted). In exports, `ExportService` MUST exclude restricted columns from CSV/XLSX headers and row data. In GraphQL, restricted fields MUST resolve to `null` with a partial error.

#### Scenario: REST API response omits restricted field entirely
- **GIVEN** user `medewerker-1` cannot read property `bsn`
- **WHEN** `RenderObject` calls `PropertyRbacHandler::filterReadableProperties()`
- **THEN** the response JSON for each object MUST NOT contain the key `bsn`
- **AND** the field MUST NOT appear as `"bsn": null` — it MUST be absent from the JSON object entirely

#### Scenario: Export excludes restricted columns
- **GIVEN** user `medewerker-1` cannot read property `bsn`
- **WHEN** `ExportService` generates CSV headers for schema `inwoners`
- **THEN** `PropertyRbacHandler::canReadProperty()` MUST be called for each property
- **AND** `bsn` MUST be excluded from the CSV header row
- **AND** `bsn` values MUST NOT appear in any data row

#### Scenario: Conditional FLS with organisation matching
- **GIVEN** property `interneAantekening` has authorization: `{ "read": [{ "group": "public", "match": { "_organisation": "$organisation" } }] }`
- **AND** user `jan` has active organisation `org-1`
- **AND** object `obj-1` belongs to `org-1`, object `obj-2` belongs to `org-2`
- **WHEN** `jan` reads both objects
- **THEN** `ConditionMatcher::objectMatchesConditions()` MUST check `_organisation === 'org-1'`
- **AND** `interneAantekening` MUST be visible on `obj-1` but stripped from `obj-2`

### Requirement: FLS on create operations MUST skip organisation matching for conditional rules
When a new object is being created, there is no existing object data to evaluate conditional match rules against. `ConditionMatcher::filterOrganisationMatchForCreate()` MUST remove organisation-based conditions from the match criteria during create operations, so that users can set protected fields on new objects they are creating within their own organisation.

#### Scenario: Create operation skips organisation match
- **GIVEN** property `interneAantekening` has authorization: `{ "update": [{ "group": "public", "match": { "_organisation": "$organisation" } }] }`
- **WHEN** user `jan` creates a new object with `{ "interneAantekening": "initial note" }`
- **THEN** `PropertyRbacHandler::canUpdateProperty()` MUST call `ConditionMatcher::filterOrganisationMatchForCreate()`
- **AND** the `_organisation` condition MUST be removed from the match criteria
- **AND** if no remaining conditions exist, access MUST be granted
- **AND** the create MUST succeed

#### Scenario: Create operation preserves non-organisation match conditions
- **GIVEN** property `vertrouwelijk` has authorization: `{ "update": [{ "group": "managers", "match": { "_organisation": "$organisation", "priority": { "$gte": 5 } } }] }`
- **WHEN** a new object is created
- **THEN** `filterOrganisationMatchForCreate()` MUST remove `_organisation` but keep `priority`
- **AND** since there is no existing object data, the `priority` condition MUST be evaluated against empty data
- **AND** access evaluation MUST proceed with remaining conditions

### Requirement: Security rules MUST be auditable for compliance
All access decisions based on RLS and FLS SHOULD be loggable for compliance monitoring. Security-relevant events (denials, field stripping) MUST be logged at debug level via `LoggerInterface` for troubleshooting, and SHOULD be integrable with Nextcloud's audit log (`OCP\Log\ILogFactory`) for production compliance.

#### Scenario: Log RLS denial at debug level
- **GIVEN** `MagicRbacHandler::applyRbacFilters()` adds denial conditions (no matching rules)
- **THEN** a debug log MUST record: `[MagicRbacHandler] No access conditions met, denying all` with context including `userId`, `action`, file, and line number

#### Scenario: Log FLS field stripping at debug level
- **GIVEN** `PropertyRbacHandler::filterReadableProperties()` removes property `bsn` from a response
- **THEN** a debug log MUST record: `[PropertyRbacHandler] Filtered unreadable property` with context including `property: "bsn"`, file, and line number

#### Scenario: Log invalid authorization rule format
- **GIVEN** a schema contains a malformed authorization rule (not string and not array with `group`)
- **WHEN** `MagicRbacHandler::processAuthorizationRule()` encounters it
- **THEN** a warning log MUST record: `[MagicRbacHandler] Invalid authorization rule format` with the rule content

#### Scenario: Log unknown operator in match conditions
- **GIVEN** a match condition uses an unsupported operator (e.g., `$regex`)
- **WHEN** `MagicRbacHandler::buildSingleOperatorCondition()` encounters it
- **THEN** a warning log MUST record: `[MagicRbacHandler] Unknown operator` with the operator name
- **AND** `OperatorEvaluator::applySingleOperator()` MUST log `[OperatorEvaluator] Unknown operator` and return `true` (fail-open for unknown operators to avoid false denials)

### Requirement: Schema property authorization configuration MUST be inspectable via Schema entity methods
The `Schema` entity MUST provide methods to check whether any property has authorization rules (`hasPropertyAuthorization()`), to retrieve authorization rules for a specific property (`getPropertyAuthorization(string $propertyName)`), and to list all properties with authorization rules (`getPropertiesWithAuthorization()`). These methods serve as the contract between the Schema entity and `PropertyRbacHandler`.

#### Scenario: Schema with property authorization is detected
- **GIVEN** schema `inwoners` has property `bsn` with `authorization: { "read": ["bsn-geautoriseerd"] }`
- **WHEN** `Schema::hasPropertyAuthorization()` is called
- **THEN** it MUST iterate the `properties` array and return `true` when any property has a non-empty `authorization` key

#### Scenario: Schema without property authorization skips FLS processing
- **GIVEN** schema `tags` has no properties with `authorization` blocks
- **WHEN** `PropertyRbacHandler::filterReadableProperties()` is called
- **THEN** `Schema::hasPropertyAuthorization()` MUST return `false`
- **AND** the object MUST be returned unmodified without iterating individual properties

#### Scenario: Retrieve all properties with authorization for batch checking
- **GIVEN** schema `dossiers` has 3 properties with authorization out of 15 total properties
- **WHEN** `Schema::getPropertiesWithAuthorization()` is called
- **THEN** it MUST return an associative array with exactly 3 entries: `propertyName => authorizationConfig`
- **AND** `PropertyRbacHandler::filterReadableProperties()` and `getUnauthorizedProperties()` MUST only iterate these 3 properties, not all 15

### Requirement: CamelCase property names MUST be correctly mapped to snake_case column names in SQL conditions
`MagicRbacHandler::propertyToColumnName()` MUST convert camelCase property names from authorization rules to snake_case column names used in the dynamic MagicMapper tables. This ensures that match conditions reference the correct database columns.

#### Scenario: CamelCase to snake_case conversion
- **GIVEN** match condition `{ "assignedTo": "$userId" }`
- **WHEN** `MagicRbacHandler::propertyToColumnName('assignedTo')` is called
- **THEN** it MUST return `assigned_to`
- **AND** the SQL condition MUST reference `t.assigned_to`, not `t.assignedTo`

#### Scenario: Already snake_case property name passes through
- **GIVEN** match condition `{ "status": "open" }`
- **WHEN** `propertyToColumnName('status')` is called
- **THEN** it MUST return `status` unchanged

#### Scenario: Underscore-prefixed system property
- **GIVEN** match condition `{ "_organisation": "$organisation" }`
- **WHEN** `propertyToColumnName('_organisation')` is called
- **THEN** it MUST return `_organisation` unchanged (no camelCase conversion needed)

### Requirement: ConditionMatcher MUST support @self property lookup for system fields
When evaluating property-level authorization match conditions, `ConditionMatcher::getObjectValue()` MUST check both the direct property and the `@self` sub-object for underscore-prefixed properties. This allows conditions to reference system fields like `_organisation` which may be stored under `@self.organisation` in the rendered object format.

#### Scenario: Direct property lookup
- **GIVEN** object data `{ "status": "open", "_organisation": "org-1" }`
- **AND** match condition references `_organisation`
- **WHEN** `ConditionMatcher::getObjectValue($object, '_organisation')` is called
- **THEN** it MUST return `org-1` from the direct property

#### Scenario: Fallback to @self for underscore-prefixed properties
- **GIVEN** object data `{ "status": "open", "@self": { "organisation": "org-1" } }` (no direct `_organisation` key)
- **AND** match condition references `_organisation`
- **WHEN** `ConditionMatcher::getObjectValue($object, '_organisation')` is called
- **THEN** it MUST strip the underscore prefix, check `@self.organisation`, and return `org-1`

#### Scenario: Non-underscore property does not check @self
- **GIVEN** object data `{ "status": "open" }`
- **AND** match condition references `status`
- **WHEN** `ConditionMatcher::getObjectValue($object, 'status')` is called
- **THEN** it MUST return `open` from the direct property
- **AND** it MUST NOT check `@self` (only underscore-prefixed properties fall back to `@self`)

### Requirement: writeOnly properties MUST never be returned on any read
A property declared `writeOnly: true` MUST be stripped from every read response for every caller, including admin, while remaining writable. This is the field-level read mechanism for secrets and tokens (standard JSON Schema / OpenAPI keyword) and is fully backward compatible: a property without `writeOnly` is returned exactly as before. It closes openregister#380 at the platform level so ADR-063 MCP tools inherit the redaction without per-dialect field projection.

#### Scenario: writeOnly property stripped from single get for admin and non-admin
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **AND** object `cred-1` has `apiToken: "s3cr3t"` and `name: "prod"`
- **WHEN** admin reads `cred-1` via the object read path
- **THEN** the rendered response MUST contain `name` but MUST NOT contain `apiToken`
- **WHEN** a non-admin reads `cred-1`
- **THEN** the rendered response MUST NOT contain `apiToken`

#### Scenario: writeOnly property stripped from list responses
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **WHEN** any user lists `credential` objects
- **THEN** no object in the list response MUST contain `apiToken`

#### Scenario: writeOnly property remains stored and writable
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **WHEN** a user writes `apiToken: "new-secret"` to `cred-1`
- **THEN** the value MUST be persisted on the stored object
- **AND** a subsequent read MUST still omit `apiToken` from the response

### Requirement: Read-time field stripping MUST be fail-safe against caller field re-widening
Property-level read stripping (both `writeOnly` and property `authorization.read`) MUST be applied server-side after any caller-supplied `fields`, `extend`, or `unset` selection, so a caller can never re-surface a stripped property by naming it. Stripping MUST apply to single get, list, and nested/related object expansion, all of which flow through the single render choke point.

#### Scenario: fields query cannot re-surface a stripped property
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **WHEN** a caller reads `cred-1` with `fields=apiToken`
- **THEN** the response MUST NOT contain `apiToken`

#### Scenario: Stripping applies to nested expanded objects
- **GIVEN** object `case-1` expands a related `credential` object via `_extend`
- **AND** the `credential` schema has a `writeOnly` property `apiToken`
- **WHEN** `case-1` is read with the relation expanded
- **THEN** the expanded `credential` MUST NOT contain `apiToken`

### Requirement: Property authorization stripping MUST bypass for trusted internal reads
Property `authorization.read` (group-based) stripping MUST be bypassed when the render is invoked with `_rbac === false` or while `SystemOperationContext::isActive()` is true, mirroring `PermissionHandler::hasPermission()`. This guarantees the application's own service and repair-step reads receive the full object.

This bypass MUST NOT extend to `writeOnly` (see the requirement below): `_rbac === false` is not a secret-bearing trust boundary, because an admin HTTP read renders with `_rbac: false`. Internal code that needs a raw `writeOnly` value MUST read with `_render: false` (or take `ObjectEntity::getObject()` off the mapper), which never enters the render path at all.

#### Scenario: Internal render with _rbac false returns group-restricted properties
- **GIVEN** schema `case` has property `internalNote` with `authorization.read` restricted to group `staff`
- **WHEN** an internal caller renders `case-1` with `_rbac: false`
- **THEN** the rendered object MUST contain `internalNote`

#### Scenario: System operation context returns group-restricted properties
- **GIVEN** `SystemOperationContext::isActive()` is true
- **AND** schema `case` has a group-restricted property `internalNote`
- **WHEN** `case-1` is rendered
- **THEN** the rendered object MUST contain `internalNote`

### Requirement: writeOnly stripping MUST NOT be bypassed by _rbac or system context
`writeOnly` stripping is a hard render-boundary rule: it MUST apply on every read regardless of `_rbac`, `SystemOperationContext`, or admin status. An admin HTTP read renders with `_rbac: false`, so gating `writeOnly` on `_rbac` returns plaintext secrets to admin-context reads (openregister#389). The only supported way for internal code to obtain a `writeOnly` value is to not render: `ObjectService::find(_render: false)` returns the raw entity before `renderEntity()` is reached, which is how the credential migration and `CallService` re-resolve secrets.

This rule binds BOTH read paths, which are separate code paths that MUST agree:
1. the single-object path (`RenderObject::renderEntity()`), and
2. the list/search cheap path (`RenderObject::redactWriteOnlyFromRows()`), which lists take when no `_extend`/`_fields`/`_filter`/`_unset` is requested and which therefore never enters `renderEntity()` at all.

Applying the rule to only one of them is a live disclosure, not a partial fix: `ObjectsController` derives `_rbac` as `($isAdmin === false)`, so an ADMIN list/search read arrives on path 2 with `_rbac: false`. Gating the strip there returned every `writeOnly` secret in cleartext for admin list reads even while path 1 was correctly hardened (openregister#460 — the list half of openregister#389). `_rbac: false` on an HTTP read means "this caller bypasses WHICH OBJECTS it may see"; it never means "this caller may see secrets". Conflating those two is the defect both issues describe.

Both paths MUST decide via the same schema-level predicate (top-level `writeOnly` OR a declared `x-openregister-writeonly-paths` entry) and strip via the same choke point, so a rule added to one is enforced by the other. The `@self.relations` mirror (openregister#429) MUST be stripped on the same boundary on both paths.

#### Scenario: writeOnly is stripped even when _rbac is false
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **WHEN** a caller renders `cred-1` with `_rbac: false`
- **THEN** the rendered object MUST NOT contain `apiToken`
- **AND** `@self.relations` MUST NOT contain `apiToken`

#### Scenario: An admin list read does not return writeOnly secrets
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **AND** the caller is an admin, so the controller derives `_rbac: false`
- **WHEN** the admin lists `credential` objects on the cheap path (no `_extend`/`_fields`/`_filter`/`_unset`)
- **THEN** no row in the response MUST contain `apiToken`
- **AND** no row's `@self.relations` MUST contain `apiToken`

#### Scenario: An admin list read does not return a nested writeOnly path
- **GIVEN** schema `source` declares `x-openregister-writeonly-paths: ["configuration.authentication.client_secret"]`
- **AND** the caller is an admin, so the controller derives `_rbac: false`
- **WHEN** the admin lists `source` objects on the cheap path
- **THEN** no row MUST contain `configuration.authentication.client_secret`
- **AND** each row MUST still contain the non-secret siblings under `configuration`

#### Scenario: A system-context list read does not return writeOnly secrets
- **GIVEN** `SystemOperationContext::isActive()` is true
- **AND** schema `credential` has property `apiToken` with `writeOnly: true`
- **WHEN** `credential` objects are listed on the cheap path
- **THEN** no row in the result MUST contain `apiToken`

#### Scenario: _render false returns the raw value for the engine
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **WHEN** an internal caller reads `cred-1` with `_render: false`
- **THEN** the returned entity's `getObject()` MUST contain `apiToken`

### Requirement: Nested writeOnly paths MUST never be returned on any read
A secret nested inside an untyped `object` property cannot be declared with `writeOnly: true`, because JSON Schema keywords attach only to properties the schema declares — and marking the whole parent object `writeOnly` would break the editors that legitimately read the rest of it back. Schemas MUST therefore be able to declare individual nested dot-paths as write-only via the schema-level annotation `x-openregister-writeonly-paths`, a list of dot-separated paths rooted at a declared property (e.g. `configuration.authentication.client_secret`).

A declared path MUST be stripped from the value at that location AND its entire sub-tree, on the same hard render boundary as top-level `writeOnly`: unconditionally, for every caller including admin, on single get, list (including the cheap path), and nested expansion, after any caller-supplied `fields`/`extend`/`unset` selection, and from the `@self.relations` search-index mirror — which `SaveObject::scanForRelations()` populates with flattened literal dot-path keys, so a nested secret is mirrored there under its full path. `_render: false` remains the internal bypass.

This closes openconnector#235 (`source.configuration.authentication.*`) and the openconnector#147 residual (`rule.configuration.authentication.keys`) at the platform level. It is strictly opt-in: a schema without the annotation is unaffected.

#### Scenario: A declared nested path is stripped for admin
- **GIVEN** schema `source` declares `x-openregister-writeonly-paths: ["configuration.authentication.client_secret"]`
- **AND** object `src-1` has `configuration.authentication.client_secret: "s3cr3t"` and `configuration.endpoint: "https://api.example.gov"`
- **WHEN** an admin reads `src-1`
- **THEN** the response MUST NOT contain `configuration.authentication.client_secret`
- **AND** the response MUST still contain `configuration.endpoint`

#### Scenario: A declared path strips its whole sub-tree
- **GIVEN** schema `rule` declares `x-openregister-writeonly-paths: ["configuration.authentication.keys"]`
- **AND** `configuration.authentication.keys` is a map whose leaf keys are caller-supplied apiKeys
- **WHEN** any user reads the rule
- **THEN** the response MUST NOT contain `keys` nor any entry beneath it

#### Scenario: The relations mirror does not leak a nested path
- **GIVEN** `@self.relations` holds the flattened key `configuration.authentication.client_secret`
- **WHEN** the object is rendered
- **THEN** `@self.relations` MUST NOT contain that key nor any key prefixed by a declared path

#### Scenario: A caller cannot re-widen a nested path via fields
- **GIVEN** schema `source` declares a nested write-only path under `configuration`
- **WHEN** a caller reads with `fields=configuration`
- **THEN** the returned `configuration` MUST NOT contain the declared path

#### Scenario: An invalid path declaration fails loudly at save
- **GIVEN** a schema whose `x-openregister-writeonly-paths` contains a malformed path or one rooted at an undeclared property
- **WHEN** the schema is saved
- **THEN** the save MUST fail with an error naming the offending path
- **AND** the annotation MUST NOT be silently dropped, because a dropped write-only declaration would persist a schema whose secrets are unprotected while appearing annotated

### Requirement: An omitted writeOnly value MUST be preserved on save
The read-side strip has a mandatory save-side counterpart. `writeOnly`'s semantic — "a client may SEND this, the server never returns it" — tacitly assumes the client re-sends the value on update. **A client that was never given the value cannot re-send it.** Since object update is PUT-semantic (a schema property absent from the payload is nulled so the mapper issues an explicit `SET column=NULL`), the natural round-trip destroys the secret: GET returns the object with the secret stripped (correct), the client edits one field and PUTs the body back without the secret it never saw, and the save nulls it. A read boundary that is airtight while the write boundary silently deletes is not a partial implementation of `writeOnly`; it is data destruction (openregister#463, observed live in openconnector#245, and the blocker on openconnector#147).

Therefore, when persisting an update to an EXISTING object: for each top-level `writeOnly: true` property AND each declared `x-openregister-writeonly-paths` dot-path, if the incoming payload OMITS that location, the stored value MUST be carried forward instead of nulled. Creates have no stored value and are a no-op. The rule is opt-in and fail-safe: a schema declaring neither is unaffected.

Both declaration surfaces MUST be honoured by the same rule, and the set of locations preserved on save MUST be the same set stripped on read — a value the server refuses to return but does not protect on write is the exact defect above.

**Absent and explicit `null` MUST NOT be treated alike.** Only an ABSENT location is preserved; a location present with an explicit `null` clears the stored value. Conflating them would make clearing a secret impossible — settable and rotatable but never removable, leaving a decommissioned credential permanently undeletable. Absent is the accidental case (the client was never shown the value); explicit null is an act a client can only perform deliberately. This also keeps PUT consistent with PATCH, which already merges the payload over the stored object: an omitted key is backfilled from storage, an explicit null overwrites. Both verbs therefore agree — **omit to keep, send null to clear.**

The preserved value MUST be read from the RAW stored object (`ObjectEntity::getObject()`, or a `_render: false` read). `_rbac: false` is NOT sufficient: the writeOnly strip is schema-gated and deliberately ignores `_rbac`, so an `_rbac: false` read still returns the object with the secret already stripped and the preserve would silently no-op.

#### Scenario: An omitted writeOnly property keeps its stored value
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **AND** object `cred-1` has `apiToken: "s3cr3t"` and `name: "prod"`
- **WHEN** a client updates `cred-1` with a payload containing only `name: "prod-renamed"`
- **THEN** the stored `apiToken` MUST still be `"s3cr3t"`
- **AND** the stored `name` MUST be `"prod-renamed"`

#### Scenario: The rendered body fed back as an update payload does not destroy the secret
- **GIVEN** schema `source` declares `x-openregister-writeonly-paths: ["configuration.authentication.client_secret"]`
- **AND** a client GETs `src-1`, receiving a body with the secret stripped
- **WHEN** the client edits an unrelated field and PUTs that same body back
- **THEN** the stored `configuration.authentication.client_secret` MUST be unchanged
- **AND** the edited field MUST be persisted

#### Scenario: An omitted nested path is preserved without clobbering sibling edits
- **GIVEN** schema `source` declares `configuration.authentication.client_secret` write-only
- **AND** `src-1` has `configuration.endpoint: "https://old.example.gov"` and `configuration.authentication: {username: "svc", client_secret: "s3cr3t"}`
- **WHEN** a client updates with `configuration.endpoint: "https://new.example.gov"` and `configuration.authentication: {username: "new-user"}`
- **THEN** the stored `client_secret` MUST still be `"s3cr3t"`
- **AND** `configuration.endpoint` MUST be `"https://new.example.gov"`
- **AND** `configuration.authentication.username` MUST be `"new-user"`
- **BECAUSE** the preserved leaf is merged back into the incoming sub-tree, never restored as a whole parent object

#### Scenario: A new writeOnly value still overwrites
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true` and `cred-1` has `apiToken: "old-secret"`
- **WHEN** an operator updates `cred-1` with `apiToken: "rotated-secret"`
- **THEN** the stored value MUST be `"rotated-secret"`
- **BECAUSE** a preserve rule that is too eager makes secrets unsettable

#### Scenario: An explicit null clears the secret
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true` and `cred-1` has `apiToken: "s3cr3t"`
- **WHEN** a client updates `cred-1` with `apiToken: null` explicitly present in the payload
- **THEN** the stored `apiToken` MUST be null

#### Scenario: An ordinary omitted property is still nulled
- **GIVEN** schema `credential` has a non-write-only property `name`
- **WHEN** a client updates `cred-1` with a payload omitting `name`
- **THEN** the stored `name` MUST be null
- **BECAUSE** the preserve rule is scoped to write-only locations and MUST NOT turn PUT into PATCH for ordinary fields

#### Scenario: A create has nothing to preserve
- **GIVEN** schema `credential` has property `apiToken` with `writeOnly: true`
- **WHEN** a client creates a new object without `apiToken`
- **THEN** the save MUST succeed and MUST NOT invent an `apiToken` key

## Current Implementation Status

**Substantially implemented.** The row-level and field-level security system is production-ready with the following components:

**Fully implemented (row-level security):**
- `MagicRbacHandler` (`lib/Db/MagicMapper/MagicRbacHandler.php`) — SQL-level RBAC filtering with QueryBuilder integration and raw SQL for UNION queries. Supports conditional rules with `group` + `match`, dynamic variable resolution (`$organisation`, `$userId`, `$now`), MongoDB-style operators (`$eq`, `$ne`, `$gt`, `$gte`, `$lt`, `$lte`, `$in`, `$nin`, `$exists`), owner bypass (`t._owner`), admin bypass, `public` and `authenticated` pseudo-groups, camelCase-to-snake_case column mapping, and SQL injection prevention via `quoteValue()`.
- `PermissionHandler` (`lib/Service/Object/PermissionHandler.php`) — Schema-level RBAC with `hasPermission()` for non-query access checks, supporting conditional rules with object data for individual object authorization.
- `MultiTenancyTrait` (`lib/Db/MultiTenancyTrait.php`) — Organisation-level data isolation with RBAC bypass detection via `hasConditionalRulesBypassingMultitenancy()`.

**Fully implemented (field-level security):**
- `PropertyRbacHandler` (`lib/Service/PropertyRbacHandler.php`) — Property-level RBAC with `canReadProperty()`, `canUpdateProperty()`, `filterReadableProperties()`, `getUnauthorizedProperties()`. Supports conditional rules with match criteria, admin bypass, `public`/`authenticated` pseudo-groups, and create-operation organisation match skipping.
- `ConditionMatcher` (`lib/Service/ConditionMatcher.php`) — Evaluates match conditions with dynamic variable resolution (`$organisation`, `$userId`, `$now`), `@self` property lookup for system fields, and delegation to `OperatorEvaluator`.
- `OperatorEvaluator` (`lib/Service/OperatorEvaluator.php`) — MongoDB-style operator evaluation for PHP-level condition matching (`$eq`, `$ne`, `$in`, `$nin`, `$exists`, `$gt`, `$gte`, `$lt`, `$lte`).
- `Schema` entity (`lib/Db/Schema.php`) — `hasPropertyAuthorization()`, `getPropertyAuthorization()`, `getPropertiesWithAuthorization()` methods for inspecting property-level authorization rules.
- Write-only secrets (`lib/Db/Schema.php`, `lib/Service/PropertyRbacHandler.php`) — top-level `writeOnly: true` via `hasWriteOnlyProperties()`/`getWriteOnlyProperties()`, and nested dot-paths via the `x-openregister-writeonly-paths` annotation (`Schema::WRITEONLY_PATHS_ANNOTATION`, `hasWriteOnlyPaths()`, `getWriteOnlyPaths()`). Both are enforced by `PropertyRbacHandler::stripWriteOnlyProperties()`, which strips the object body and the flattened `@self.relations` mirror. Enforcement is unconditional at the render boundary (`RenderObject::renderEntity()` and the list cheap path `redactWriteOnlyFromRows()`), gated only by `RenderObject::schemaHasWriteOnlyRule()`. The annotation is validated at save time by `Schema::validateWriteOnlyPathsValue()` and is the one configuration key exempt from per-key isolation: a malformed declaration aborts the save rather than being dropped, because a dropped write-only declaration is fail-open.
- Save-side preservation (`lib/Service/PropertyRbacHandler.php`, `lib/Service/Object/SaveObject.php`) — the counterpart to the read-side strip (openregister#463). `collectOmittedWriteOnlyPaths()` reports which declared locations an incoming update payload omits, and `restoreWriteOnlyValues()` carries the stored values forward, both honouring top-level `writeOnly` and nested dot-paths under one rule (a top-level property is treated as a single-segment path). `SaveObject::prepareObjectForUpdate()` detects omissions against the raw payload before `setDefaultValues()` can materialise a key, and restores after `prepareObjectData()` (so an encrypted stored value is not double-encrypted) but before `fillMissingSchemaPropertiesWithNull()` (which would otherwise erase the absent-vs-explicit-null distinction). Absent preserves; explicit `null` clears. The stored value is read from the raw `ObjectEntity::getObject()` snapshot, never a rendered read.

**Fully integrated across access methods:**
- REST API: `RenderObject` calls `PropertyRbacHandler::filterReadableProperties()` during object rendering (line ~1065).
- REST write: `SaveObject` calls `PropertyRbacHandler::getUnauthorizedProperties()` during save validation (line ~2562).
- GraphQL queries: `GraphQLResolver` integrates `PropertyRbacHandler` for field-level filtering and `MagicRbacHandler` for query-level RLS.
- GraphQL mutations: `GraphQLResolver` checks `PropertyRbacHandler::getUnauthorizedProperties()` before mutation execution.
- Exports: `ExportService` uses `PropertyRbacHandler::canReadProperty()` to filter export columns (line ~531).
- Search: `MagicRbacHandler::applyRbacFilters()` is called before search query execution, ensuring facet counts reflect accessible data.

**Partially implemented:**
- Save-side write-only preservation covers the single-object path only (`SaveObject::prepareObjectForUpdate()`, which also serves `saveObjectsStreaming()` since it delegates per row). The **bulk** path does not have it: `SaveObjects::saveObjects()` builds payloads via `extractBusinessData()` and persists through `MagicMapper::ultraFastBulkSave()` as an upsert-by-UUID (`INSERT ... ON CONFLICT DO UPDATE`), never reading the existing row (`existingObjects: []`), so a bulk update omitting a declared write-only location overwrites it. This is a narrower vector than the single-object round-trip — bulk payloads originate from import files rather than from a stripped GET — and closing it requires a chunk-level batched existing-row fetch gated on the schema declaring write-only. Tracked separately.
- Audit logging of RLS/FLS decisions exists at debug level via `LoggerInterface` but is not integrated with Nextcloud's audit log (`OCP\Log\ILogFactory`) for production compliance visibility.
- No dedicated security rule management API (rules are configured as part of the schema definition JSON, not via a separate CRUD endpoint).
- No security rule testing/dry-run endpoint to preview what a user would see without executing the actual query.

**Not implemented:**
- `$CURRENT_USER.groups` dynamic variable for matching user group membership in conditions (currently only `$userId` for user identity).
- `$CURRENT_USER.customAttribute` for matching against Nextcloud user profile attributes.
- Security rule versioning or rollback capability.
- Real-time security rule change propagation to active sessions (changes take effect on next request via schema reload).
- Permission matrix UI for visual management of property-level authorization rules.

## Standards & References
- **PostgreSQL Row-Level Security (RLS)** — Conceptual reference for row-level filtering where policies define visibility predicates per table.
- **Directus ABAC (v11)** — Competitive reference for filter-based permissions with dynamic variables (`$CURRENT_USER`, `$CURRENT_ROLE`, `$NOW`), additive policy system, and field-level access per CRUD action.
- **ABAC — NIST SP 800-162** — Attribute-Based Access Control guide for fine-grained authorization using subject, object, and environment attributes.
- **Dutch BIO (Baseline Informatiebeveiliging Overheid)** — Baseline information security for Dutch government organizations, requiring data compartmentalization and need-to-know access controls.
- **AVG/GDPR** — Data protection regulation requiring purpose limitation and data minimization, supported by field-level security to restrict access to personal data fields.
- **WCAG 2.1 AA** — Accessible display of security-restricted content (e.g., indicating that fields are hidden, not showing empty columns).
- **RBAC — NIST RBAC Model** — Role-Based Access Control standard that `MagicRbacHandler` implements using Nextcloud groups as roles.
- **MongoDB Query Operators** — The operator syntax (`$eq`, `$gt`, `$in`, etc.) used in match conditions follows MongoDB's filter query language.
- **Nextcloud OCP Interfaces** — `IUserSession`, `IGroupManager`, `IAppConfig` for user identity and group resolution.
- **ZGW Autorisaties API (VNG)** — Dutch government authorization patterns for zaaktype-based access control with confidentiality levels.

## Cross-References
- **`auth-system`** — Defines the authentication system that resolves all access methods to Nextcloud user identities before RLS/FLS evaluation. RLS and FLS depend on `IUserSession::getUser()` being set correctly by the auth system.
- **`rbac-scopes`** — Maps Nextcloud group-based RBAC to OAuth2 scopes in the OAS output. Property-level authorization groups are extracted by `OasService` and included as OAuth2 scopes.
- **`rbac-zaaktype`** — Schema-level RBAC per zaaktype. RLS and FLS extend this with finer-grained per-object and per-field control within the same schema.

## Specificity Assessment
- **Highly specific and substantially implemented**: All core RLS and FLS components are implemented and integrated across REST, GraphQL, search, and export access methods.
- **Code-grounded scenarios**: Every scenario references specific classes (`MagicRbacHandler`, `PropertyRbacHandler`, `ConditionMatcher`, `OperatorEvaluator`), methods, and line numbers from the actual implementation.
- **Complete operator coverage**: All 9 MongoDB-style operators are specified with SQL generation and PHP evaluation paths.
- **Dynamic variables fully specified**: `$userId`, `$organisation`, `$now` with resolution paths, caching behavior, and null-handling.
- **No major design ambiguity**: The condition syntax, evaluation order (schema > row > field), and interaction with multi-tenancy are well-defined.
- **Minor gaps identified**: Audit log integration, security rule management API, and extended `$CURRENT_USER` variable support are the remaining enhancement opportunities.
