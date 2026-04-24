## MODIFIED Requirements

### Requirement: Conditional Scopes with Dynamic Variables

Authorization rules MUST support conditional matching where access depends on both group membership AND runtime conditions evaluated against the object's data. The system MUST resolve dynamic variables `$organisation`, `$userId`/`$user`, and `$now` at evaluation time. Crucially, the same rule grammar MUST be honoured by every enforcement point: the row-level SQL filter (`MagicRbacHandler::applyRbacFilters`), the schema-level PHP check (`PermissionHandler::hasPermission`), and the property-level check (`PropertyRbacHandler`). All three enforcement points SHALL route conditional rule evaluation through the shared `ConditionMatcher` service (SQL-emission is the only specialised path — the PHP-side evaluator is shared).

#### Scenario: Schema-level conditional rule with `$lte` operator and `$now` variable matches list and find verdicts

- **GIVEN** schema `publicaties` has authorization `{ "read": [{ "group": "public", "match": { "publishDate": { "$lte": "$now" } } }] }`
- **AND** object `pub-1` has `publishDate = 2026-01-01`
- **AND** current time is `2026-04-23`
- **WHEN** an unauthenticated caller hits `GET /api/objects/{register}/publicaties` (list path) and `GET /api/objects/{register}/publicaties/pub-1` (find path)
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST add `publish_date <= NOW()` to the SQL WHERE and return `pub-1` in the list
- **AND** `PermissionHandler::hasPermission()` MUST delegate match evaluation to `ConditionMatcher::objectMatchesConditions()` and return `true` for `pub-1`
- **AND** neither path MUST throw `"User 'Anonymous' does not have permission ..."`

#### Scenario: Schema-level conditional rule with `$userId` variable matches list and find verdicts

- **GIVEN** schema `taken` has authorization `{ "read": [{ "group": "medewerkers", "match": { "assignedTo": "$userId" } }] }`
- **AND** user `jan` is in group `medewerkers`
- **AND** object `taak-7` has `assignedTo = "jan"`
- **WHEN** `jan` lists taken and then fetches `taak-7` by UUID
- **THEN** the SQL filter MUST produce `assigned_to = 'jan'` and include `taak-7` in the list
- **AND** `PermissionHandler::hasPermission()` for `taak-7` MUST resolve `$userId` via `ConditionMatcher::resolveDynamicValue()` and return `true`
- **AND** the find path MUST NOT throw

#### Scenario: Schema-level conditional rule with `$in` operator matches list and find verdicts

- **GIVEN** schema `meldingen` has authorization `{ "read": [{ "group": "behandelaars", "match": { "status": { "$in": ["open", "review"] } } }] }`
- **AND** user `jan` is in `behandelaars`
- **AND** object `m-1` has `status = "open"`, object `m-2` has `status = "done"`
- **WHEN** `jan` lists meldingen and then fetches each by UUID
- **THEN** the list MUST include `m-1` and exclude `m-2` (SQL `IN` filter)
- **AND** `PermissionHandler::hasPermission()` MUST return `true` for `m-1` and `false` for `m-2`, evaluated via `ConditionMatcher::valueMatchesOperator`
- **AND** the verdicts MUST agree between list and find for every object

#### Scenario: Anonymous caller against public-with-match rule is evaluated through `ConditionMatcher`

- **GIVEN** an unauthenticated request (`IUserSession::getUser() === null`) against schema `publicaties` with `{ "read": [{ "group": "public", "match": { "publishDate": { "$lte": "$now" } } }] }`
- **WHEN** `PermissionHandler::hasPermission(userId: null)` is called with a loaded `ObjectEntity` whose `publishDate` is in the past
- **THEN** the anonymous branch at line 165-183 MUST route to `hasGroupPermission(groupId: "public")`
- **AND** `hasGroupPermission` MUST invoke `ConditionMatcher::objectMatchesConditions()` (not the removed `evaluateMatchConditions`) with the object's full envelope
- **AND** the return value MUST be `true`
- **AND** `checkPermission` MUST NOT throw

#### Scenario: `_organisation` field resolves via `@self` envelope

- **GIVEN** schema `zaken` with `{ "read": [{ "group": "behandelaars", "match": { "_organisation": "$organisation" } }] }`
- **AND** user `jan` in group `behandelaars` has active organisation UUID `abc-123`
- **AND** object `zaak-1` has `@self.organisation = "abc-123"`
- **WHEN** `PermissionHandler::hasGroupPermission()` evaluates the rule for `zaak-1`
- **THEN** the full object envelope (including `@self`) MUST be passed to `ConditionMatcher::objectMatchesConditions()`
- **AND** `ConditionMatcher::getObjectValue()` MUST resolve `_organisation` by stripping the underscore and looking up `@self.organisation`
- **AND** the returned value MUST equal the resolved `$organisation` (`abc-123`) and the rule MUST match

#### Scenario: `MagicRbacHandler::hasPermission` delegates to the shared matcher

- **GIVEN** any authorization rule with a `match` clause
- **WHEN** `MagicRbacHandler::hasPermission()` is invoked with object data (e.g. from a non-SQL code path that needs a PHP-side verdict)
- **THEN** it MUST delegate conditional evaluation to `ConditionMatcher::objectMatchesConditions()`
- **AND** the private helpers `objectMatchesConditions`, `objectPropertyMatchesCondition`, `valueMatchesOperator`, `singleOperatorMatches`, `comparisonOperatorMatches`, `arrayOperatorMatches`, `existsOperatorMatches` MUST be removed from `MagicRbacHandler`
- **AND** only the SQL-emission helpers (`applyRbacFilters`, `buildRbacConditionsSql`, `buildMatchConditions`, `buildPropertyCondition`, and their operator/comparison/array builders) MUST remain as specialised row-level code

#### Scenario: Enforcement points agree on every rule form

- **GIVEN** any `match` clause using operators from `{$eq, $ne, $gt, $gte, $lt, $lte, $in, $nin, $exists}` and/or dynamic variables from `{$organisation, $userId, $now}`
- **WHEN** the same principal queries the list endpoint and the find endpoint for the same object
- **THEN** the list endpoint MUST include the object if and only if the find endpoint returns 200
- **AND** the find endpoint MUST throw `Exception` with permission-denied wording if and only if the list endpoint excludes the object
- **AND** this equivalence MUST hold for the cross-product of (authenticated / anonymous) × (owner / non-owner) × (admin / non-admin) × (every operator × every variable)

#### Scenario: Dynamic `$now` variable resolves to a canonical SQL-native format

- **GIVEN** a conditional `match` rule using `$now` (e.g. `{ "publicatiedatum": { "$lte": "$now" } }`)
- **WHEN** the list path (`MagicRbacHandler::resolveDynamicValue`) and the find path (`ConditionMatcher::resolveDynamicValue`) each resolve `$now` independently
- **THEN** both paths MUST emit `$now` in `Y-m-d H:i:s` format (SQL-native, e.g. `"2026-04-24 14:43:49"`)
- **AND** neither path MUST emit ISO 8601 `c` format with `"T"` separator and `+00:00` offset (e.g. `"2026-04-24T14:43:49+00:00"`)
- **AND** the format MUST match across handlers even when the stored date column is a text/JSON column where the comparison is a raw lexicographic string compare
- **AND** rule authors who need consistent date-math semantics on user-supplied data SHOULD rely on OpenRegister's `DateTimeNormalizer` to normalize stored dates to the same format, rather than embedding multiple formats in the same column

#### Scenario: Null-valued properties evaluate conservatively (SQL three-valued logic)

- **GIVEN** schema `publicaties` has authorization `{ "read": [{ "group": "public", "match": { "publishedAt": { "$lte": "$now" } } }] }`
- **AND** object `draft-1` has `publishedAt` absent from its data, or explicitly set to `null`
- **WHEN** an unauthenticated caller hits the list endpoint and the find endpoint
- **THEN** `MagicRbacHandler::applyRbacFilters()` MUST produce SQL `publish_date <= NOW()` which evaluates to `NULL` for `draft-1` and excludes it from the list (SQL `WHERE NULL` is false)
- **AND** `ConditionMatcher::objectMatchesConditions()` MUST return `false` for `draft-1` — delegating through `OperatorEvaluator`, a null object value against any of `$gt`, `$gte`, `$lt`, `$lte`, `$in`, `$nin`, or `$ne: <non-null>` MUST return `false` instead of relying on PHP's loose coercion (`null` → `""` / `0`)
- **AND** `PermissionHandler::hasPermission()` MUST therefore deny `draft-1`, matching the list endpoint's exclusion
- **AND** the only null-aware operator whose verdict depends on `null` MUST be `$exists` (`$exists: true` → `false` for null, `$exists: false` → `true` for null)
- **AND** `$eq: null` MAY match null object values as a backward-compatible "match missing field" escape hatch; rule authors requiring strict SQL-aligned semantics SHOULD use `$exists: false` instead

#### Scenario: Resolved relations unwrap to their scalar id for scalar comparisons

- **GIVEN** a conditional rule `{ "match": { "parent": "uuid-123" } }`
- **AND** object data where the `parent` property has been expanded into its full related object: `{ "parent": { "id": "uuid-123", "name": "Parent" } }`
- **WHEN** `ConditionMatcher::objectMatchesConditions()` evaluates the rule
- **THEN** the object value `{ "id": "uuid-123", ... }` MUST be unwrapped to the scalar `"uuid-123"` before the equality check
- **AND** the rule MUST return `true` regardless of whether the consumer passes expanded or unexpanded object data
- **AND** this unwrapping MUST happen for every comparison branch (simple equality, operator objects, null checks) so list (SQL compares against the id column directly) and find (PHP compares after unwrapping) produce identical verdicts
- **AND** array values without an `id` key MUST NOT be unwrapped — they stay as arrays and mismatch against scalar rules (arrays-as-first-class are not a resolved-relation shape)

#### Scenario: Unknown operators fail closed (reject the match)

- **GIVEN** a malformed conditional rule using an operator the system does not recognise, e.g. `{ "match": { "publishedAt": { "$foo": "bar" } } }`
- **WHEN** `MagicRbacHandler::applyRbacFilters()` (list) and `ConditionMatcher::objectMatchesConditions()` (find) each process the rule
- **THEN** the SQL path MUST produce no clause that could satisfy the rule (`buildSingleOperatorCondition` returns `null`; the rule is dropped from the OR list; if no other rule grants access the row is filtered out)
- **AND** the PHP path MUST return `false` from `OperatorEvaluator::applySingleOperator()` for the unknown operator (fail-closed), NOT `true`
- **AND** both paths MUST log a warning identifying the unknown operator
- **AND** neither path MUST grant access on a malformed rule — this prevents typos and forward-incompatible schemas from accidentally widening the access surface

### Requirement: Divergences from strict SQL three-valued logic (documented)

The PHP-side matcher aims for parity with SQL's three-valued logic, but two operators deliberately diverge for ergonomic reasons. Rule authors who need strict SQL alignment MUST avoid these forms and use `$exists` instead.

#### Scenario: `$eq: null` matches null object values (escape hatch)

- **GIVEN** a rule `{ "match": { "field": null } }` where the rule author's intent is "match objects where `field` is missing"
- **WHEN** the PHP path evaluates this against an object with `field: null`
- **THEN** `operatorEquals` MUST return `true` (`null === null`)
- **AND** the SQL path (which emits `col = NULL`, evaluating to `NULL`) would filter this row out, so the two paths diverge for this specific form
- **AND** rule authors SHOULD prefer `{ "match": { "field": { "$exists": false } } }` when strict SQL alignment matters (e.g. UNION queries, cross-engine deployments)

#### Scenario: `$ne: null` evaluates against PHP `!==`, not SQL `IS NOT NULL`

- **GIVEN** a rule `{ "match": { "field": { "$ne": null } } }` where the rule author's intent is "match objects where `field` has a value"
- **WHEN** the PHP path evaluates this against an object with a non-null `field`
- **THEN** `operatorNotEquals` MUST return `true` (`<value> !== null`)
- **AND** when the PHP path evaluates this against an object with `field: null`, `operatorNotEquals` MUST return `false` (the null-value guard explicitly added for SQL alignment)
- **AND** the SQL path (which emits `col != NULL`, always `NULL`) would filter every row out, so the two paths diverge for this specific form (PHP returns sensible results for the non-null case; SQL returns no rows)
- **AND** rule authors SHOULD prefer `{ "match": { "field": { "$exists": true } } }` when strict SQL alignment matters
