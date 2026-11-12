---
status: draft
---

# RBAC scopes: crossing organisations is explicit

## MODIFIED Requirements

### Requirement: Multi-Tenancy Integration with Scopes
RBAC scopes MUST integrate with the multi-tenancy system so that organisation-based data isolation works alongside group-based access control. OpenRegister owns this boundary: apps MUST NOT re-implement tenancy checks. Every read rule MUST keep the caller's organisation filter unless the rule declares `"crossOrganisation": true`. This holds for plain group rules, `authenticated`, user rules, conditional rules and `public` rules. A plain string rule cannot carry the marker; an author writes it as an object (`{"group": "hr", "crossOrganisation": true}`). A `crossOrganisation` value other than the boolean `true` MUST NOT take a rule across organisations.

For a signed-in, non-admin caller, the organisation filter MUST be widened by the match of every marked read rule the caller holds: the caller reads the rows a marked rule admits in every organisation, and the rows of their other rules only in their own. A marked rule the caller holds with no match reads every row, so the filter MUST be lifted. A caller holds a rule by the same qualification the RBAC filter applies (a named group, `authenticated`, `public` with public inheritance, or the named user). Only a schema with an authorization block of its own has marked rules; the block is read resolved, so expanded roles and compiled department matrix rules count. A register's rules MUST NOT take a caller across organisations.

An admin, an explicit `_multi=true`, a caller holding per-object grants and an anonymous caller read as before: an admin reads every organisation, `_multi=true` keeps the strict filter with no cross-organisation branch, a per-object grant keeps the tenant edge, and a `public` rule (marked or not) lifts the filter for an anonymous caller. Rows with no organisation that the register cascade admits and shared master data holders are unchanged. Every read path (list, union search, facets, count, aggregation, export and the single-object read) MUST take this decision from `MagicRbacHandler`; the aggregation fast path cannot render a cross-organisation branch and falls back to the list.

#### Scenario: A plain group rule keeps the organisation filter
- **GIVEN** schema `client-organisation` has RBAC: `{ "read": ["hr", "coordinators"] }`
- **AND** user `ann` is in `hr` with active organisation `school-a`
- **AND** a client organisation row belongs to `school-b`
- **WHEN** `ann` lists the schema, counts it, or reads that row by id
- **THEN** the `school-b` row MUST NOT be returned
- @e2e exclude {enforcement is a query boundary; covered by PHPUnit on the SQL list, the union search, the single-object query and the aggregation decision}

#### Scenario: A marked plain group rule reads every organisation
- **GIVEN** schema `sector` has RBAC: `{ "read": [{ "group": "vng-raadpleger", "crossOrganisation": true }] }`
- **WHEN** a member of `vng-raadpleger` in `org-1` lists the schema
- **THEN** the organisation filter MUST be lifted and rows of every organisation MUST be returned
- @e2e exclude {covered by PHPUnit on the SQL list}

#### Scenario: A rule that binds a field to the caller's organisation stays inside it unless marked
- **GIVEN** schema `usage` has `{ "group": "aanbod-beheerder", "match": { "provider": "$organisation" } }` without the marker
- **WHEN** a member of `aanbod-beheerder` in `org-1` lists usages
- **THEN** only `org-1`'s usages whose `provider` is `org-1` MUST be returned
- **AND** with `"crossOrganisation": true` on that rule, usages of other organisations whose `provider` is `org-1` MUST be returned as well
- @e2e exclude {covered by PHPUnit on the SQL list}

#### Scenario: A marked conditional rule widens the filter by its own rows only
- **GIVEN** schema `case` has `{ "group": "landelijk-team", "match": { "caseType": "ct-national" }, "crossOrganisation": true }` and `{ "group": "team-toezicht", "match": { "caseType": "ct-permit" } }`
- **AND** user `femke` in `org-1` is in both groups
- **WHEN** `femke` lists cases, counts them, or reads one by id
- **THEN** `ct-national` cases of every organisation MUST be returned
- **AND** `ct-permit` cases of other organisations MUST NOT be returned
- @e2e exclude {covered by PHPUnit on the SQL list, the union search and the single-object query}

#### Scenario: A public rule crosses for signed-in callers only when marked
- **GIVEN** schema `publication` has `{ "group": "public", "match": { "publicationDate": { "$lte": "$now" } } }` and public inheritance on
- **WHEN** a signed-in, non-admin user in `org-1` lists publications
- **THEN** only `org-1`'s published rows MUST be returned
- **AND** with `"crossOrganisation": true` on that rule, the published rows of every organisation MUST be returned, and the user's other rules MUST still read only `org-1`
- **AND** an anonymous caller MUST read the published rows of every organisation in both cases
- @e2e exclude {covered by PHPUnit on the SQL list and the union search}

#### Scenario: An authenticated rule keeps the organisation filter
- **GIVEN** schema `feedback` has `{ "read": ["authenticated"] }`
- **WHEN** a signed-in user in `org-1` lists feedback
- **THEN** only `org-1`'s rows MUST be returned
- @e2e exclude {covered by PHPUnit on the SQL list}

#### Scenario: An explicit _multi keeps the strict filter
- **GIVEN** a caller who holds a marked rule
- **WHEN** they list with `_multi=true`
- **THEN** only their own organisations' rows MUST be returned
- @e2e exclude {covered by PHPUnit on the SQL list}

#### Scenario: Admin users see all organisations
- **GIVEN** a user in the `admin` group
- **WHEN** they query any register
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST return without filtering (admin bypass)
- **AND** multi-tenancy filtering MUST also be bypassed for admin users

## ADDED Requirements

### Requirement: The crossOrganisation marker is validated, compiled and migrated
Saving a schema whose authorization rule carries a `crossOrganisation` value that is not a boolean MUST be refused with the message `Authorization 'crossOrganisation' for action '<action>' in <context> must be true or false; set it to true only on a rule that may read other organisations' rows`. A department matrix row marked `crossOrganisation: true` MUST compile into a rule that carries the marker. Schema and register lookups MUST read `{"group": "public"}` with no match as public, the same as `"public"`. On upgrade, a repair step MUST add the marker to every stored read rule that binds a field other than `_organisation` to the caller's organisation or membership and is held through a named group, and MUST log, per schema, the plain group and `public` read rules that now keep the caller's organisation filter.

#### Scenario: A non-boolean marker is refused
- **GIVEN** a schema with read rule `{ "group": "hr", "crossOrganisation": "yes" }`
- **WHEN** the schema is saved
- **THEN** the save MUST be refused with the message above
- @e2e exclude {validator unit test}

#### Scenario: A matrix row keeps its marker when compiled
- **GIVEN** a matrix row `{ "group": "aanbod", "where": { "provider": "$organisation" }, "crossOrganisation": true }`
- **WHEN** the matrix compiles
- **THEN** the compiled read rule MUST carry `"crossOrganisation": true`
- @e2e exclude {compiler unit test}

#### Scenario: The repair step marks a bound-field rule and leaves plain rules
- **GIVEN** a stored schema with read rules `["hr", { "group": "aanbod-beheerder", "match": { "provider": "$organisation" } }]`
- **WHEN** the repair step runs
- **THEN** the second rule MUST carry `"crossOrganisation": true`
- **AND** `"hr"` MUST be left as it is and logged as narrowed
- **AND** a second run MUST change nothing
- @e2e exclude {repair step unit test}

### Requirement: Only an administrator may add a crossing

A write to a schema's authorization by a caller who is not an administrator MUST be refused with 403 when the new block holds a rule or matrix row with `"crossOrganisation": true` that the old block did not hold in that form and in that place. Such a caller MAY change the other rules, keep a marked rule as it is, and remove a marker. The rule applies to update, patch, draft publish and upload, and it judges the block the schema would store: an `authorization` sent as JSON text is judged decoded.

A schema or register MUST store a body key only under its exact property name, so `Authorization`, `Organisation` or `Owner` store nothing. An upload over an existing schema MUST NOT change its `id`, `organisation`, `owner` or `created`.

#### Scenario: A schema manager adds the marker
- **GIVEN** a schema whose `manage` rule names a group the caller is in, and the caller is not an administrator
- **WHEN** the caller writes a read rule with `"crossOrganisation": true` that was not there
- **THEN** the write MUST be refused with 403 and nothing MUST be stored
- @e2e exclude {controller unit test}

#### Scenario: A schema manager edits around a marker
- **GIVEN** the same caller and a schema whose read rule an administrator marked
- **WHEN** the caller adds another read rule and keeps the marked rule as it is
- **THEN** the write MUST succeed
- @e2e exclude {controller unit test}

#### Scenario: A marked rule moves to another action
- **GIVEN** the same caller and a schema with a marked rule under `update`
- **WHEN** the caller moves that rule to `read`
- **THEN** the write MUST be refused with 403
- @e2e exclude {comparator unit test}

#### Scenario: The marker is sent in another form
- **GIVEN** the same caller
- **WHEN** the caller sends the marked block as JSON text, or under the key `Authorization`
- **THEN** the JSON text MUST be refused with 403 and the other spelling MUST store nothing
- @e2e exclude {controller and entity unit tests}

#### Scenario: A manager tries to move a schema
- **GIVEN** the same caller
- **WHEN** the caller uploads a document with `organisation`, `Organisation`, `owner` or `Owner`
- **THEN** the schema MUST keep its organisation and owner
- @e2e exclude {controller unit test}

#### Scenario: A repair step keeps an explicit choice
- **GIVEN** a stored read rule with `"crossOrganisation": false`
- **WHEN** the repair step runs
- **THEN** the rule MUST be left as written
- @e2e exclude {repair step unit test}
