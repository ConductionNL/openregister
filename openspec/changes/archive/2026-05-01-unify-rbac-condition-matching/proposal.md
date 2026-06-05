## Why

OpenRegister's RBAC system evaluates conditional `match` rules in three different code paths (row-level SQL, schema-level PHP, property-level PHP), and two of those three already share a single evaluator (`ConditionMatcher`). The third — `PermissionHandler::evaluateMatchConditions` — is a local reimplementation that only understands strict equality and only resolves the `$organisation` dynamic variable. As a result, any schema authorization that uses operators (`$eq/$ne/$gt/$gte/$lt/$lte/$in/$nin/$exists`) or `$userId` / `$now` variables works correctly on list endpoints (row-level SQL) but is rejected by the find / show / attachments path, because `hasGroupPermission` compares a string value against an operator array with strict `!==`. The `rbac-scopes` spec already mandates the full operator + variable set (scenarios for `$userId`, `$now`, `$lte`, etc. are listed as implemented), so the schema-level handler is out of compliance with its own capability contract.

The user-visible symptom is OpenCatalogi's `PublicationsController`: `index` returns publications that `attachments` then throws on, because the second RBAC evaluation speaks a weaker grammar than the first. Rather than backfilling operator support into `evaluateMatchConditions`, this change collapses the duplicate: schema-level evaluation delegates to `ConditionMatcher` (the same service `PropertyRbacHandler` already uses), and `MagicRbacHandler`'s private PHP-side twin is removed.

## What Changes

- Inject `ConditionMatcher` into `PermissionHandler` via constructor DI.
- Replace `PermissionHandler::evaluateMatchConditions()` body with a delegation to `ConditionMatcher::objectMatchesConditions()`, folding `$objectOrganisation` into the object envelope under `@self.organisation` so `ConditionMatcher::getObjectValue()` resolves the `_organisation` field correctly.
- Update `PermissionHandler::hasGroupPermission()` call sites so the full object envelope (not just inner data) is passed to the matcher; callers already supply both `$objectData` and `$objectOrganisation` separately, so the merging happens at the call boundary.
- Remove the now-dead `evaluateMatchConditions` method and its helpers in `PermissionHandler` (only the delegating shim stays, or the method is deleted entirely and `hasGroupPermission` calls `ConditionMatcher` directly).
- Inject `ConditionMatcher` into `MagicRbacHandler` and collapse `MagicRbacHandler::hasPermission()`'s private PHP-side matcher (`objectMatchesConditions`, `objectPropertyMatchesCondition`, `valueMatchesOperator`, `singleOperatorMatches`, `comparisonOperatorMatches`, `arrayOperatorMatches`, `existsOperatorMatches`, and the `resolveDynamicValue` duplicate) onto the shared service. The SQL emitter (`applyRbacFilters` → `buildMatchConditions` → `buildPropertyCondition`) stays — that is genuinely different concern and is the canonical row-level path.
- **No spec-level requirement change to rbac-scopes.** The existing scenarios (e.g. "Time-based conditional access via `$now` variable", "User-scoped access via `$userId` variable") already require this behavior at every enforcement point; this change is bringing the schema-level implementation into alignment with what the spec already says.
- Add unit tests that exercise the full operator and variable set through `PermissionHandler::hasPermission` with a loaded `ObjectEntity`, to guarantee parity with the SQL path.

## Capabilities

### New Capabilities
<!-- None — this change collapses duplicate implementations onto an existing shared service. -->

### Modified Capabilities
- `rbac-scopes`: Strengthen the existing conditional-evaluation requirement with an explicit delegation contract. Schema-level conditional rule evaluation in `PermissionHandler` SHALL delegate to `ConditionMatcher`, so the operator set (`$eq/$ne/$gt/$gte/$lt/$lte/$in/$nin/$exists`) and dynamic variables (`$organisation/$userId/$now`) that the spec already mandates for the SQL and property paths are also enforced at the schema-level PHP path. Add scenarios proving parity between list (`MagicRbacHandler.applyRbacFilters`) and find (`PermissionHandler.hasPermission`) verdicts for the same rule and object.

## Impact

- **Code** (openregister only):
  - `openregister/lib/Service/Object/PermissionHandler.php` — add `ConditionMatcher` dependency; rewrite or remove `evaluateMatchConditions()`; update `hasGroupPermission()` to pass the merged envelope; drop unused helpers.
  - `openregister/lib/Db/MagicMapper/MagicRbacHandler.php` — add `ConditionMatcher` dependency; `hasPermission()` delegates; remove the private PHP-side matcher helpers (`objectMatchesConditions`, `objectPropertyMatchesCondition`, `valueMatchesOperator`, `singleOperatorMatches`, `comparisonOperatorMatches`, `arrayOperatorMatches`, `existsOperatorMatches`, the PHP-side `resolveDynamicValue`). The SQL-emitter path (`applyRbacFilters`, `buildRbacConditionsSql`, `buildMatchConditions`, `buildPropertyCondition`, `buildSingleOperatorCondition`, etc.) is untouched.
  - `openregister/lib/Service/ConditionMatcher.php` — no signature changes expected; may tighten docblock to document the new schema-level caller.
- **Public API contract**: No HTTP surface change. The behavior change is that schema RBAC now enforces the authorization JSON the OAS already advertises — so endpoints that were silently rejecting legitimate requests start returning the correct 200s.
- **DI**: Adds `ConditionMatcher` to `PermissionHandler` and `MagicRbacHandler` constructor wiring. Both are already services in the Nextcloud DI container (`OCA\OpenRegister\Service\ConditionMatcher`); no registration changes required.
- **Dependencies**: Relies on existing `ConditionMatcher` + `OperatorEvaluator` — no new libraries, no Nextcloud version bump.
- **Consumers** (`opencatalogi`, `softwarecatalog`, `procest`, `pipelinq`, `docudesk`): No code change required. Any consumer that passes `_rbac: true` to `ObjectService::find()` immediately benefits — schemas authored with operator-based `match` rules start working consistently across list and find paths. Specifically, OpenCatalogi's `PublicationsController::attachments` stops throwing on schemas that use, e.g., `{ "read": [{ "group": "public", "match": { "publishDate": { "$lte": "$now" } } }] }`.
- **Performance**: Neutral. `ConditionMatcher` is request-scoped with cached `$organisation` resolution (same as the removed implementations). One extra service call per permission check is negligible.
- **Security / RBAC**: Strictly tightens enforcement — no path that was previously blocked is now allowed. The change makes the schema-level check stop *under-*enforcing (stop rejecting legitimate requests) and stop *over-*rejecting on operator rules.
- **Observability**: No new log lines. Existing `PermissionHandler::checkPermission` failures continue to throw the same exception; fewer of those exceptions will fire because the evaluator is now correct.
- **Out of scope (follow-ups)**:
  - Flipping `_rbac: false` to `true` in OpenCatalogi's `PublicationsController::show` and `renderEntity` — consumer-side fix, separate PR in the `opencatalogi` repo.
  - Removing the double-find in OpenCatalogi's `PublicationsController::attachments` → `PublicationService::attachments` — consumer-side, separate PR.
  - Extending RBAC to the file-retrieval path (`FileService::getFiles` currently bypasses all RBAC) — larger design question, separate change.
  - Rewording the "User 'Anonymous' does not have permission" exception — cosmetic; may ride along if trivial but is not load-bearing here.
