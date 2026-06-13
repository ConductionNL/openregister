## 1. Deduplication check (ADR-011)

- [x] 1.1 Confirm `ConditionMatcher` (`openregister/lib/Service/ConditionMatcher.php`) is the canonical shared PHP-side condition matcher; document that it already backs `PropertyRbacHandler` and now becomes the sole backing for `PermissionHandler` and `MagicRbacHandler::hasPermission`
- [x] 1.2 Confirm no other apps (`opencatalogi`, `softwarecatalog`, `procest`, `pipelinq`, `docudesk`) carry a parallel conditional-match implementation; if any do, flag as a follow-up change (do not fix in this PR) — **verified: grep returned zero hits across all five apps (softwarecatalog repo is not in the workspace)**
- [x] 1.3 Record findings in a `// Deduplication note:` docblock at the top of `PermissionHandler::hasGroupPermission()` pointing to `ConditionMatcher` as the single matcher

## 2. `PermissionHandler` — delegate schema-level matching

- [x] 2.1 Add `ConditionMatcher` as a constructor-injected dependency of `PermissionHandler` (`openregister/lib/Service/Object/PermissionHandler.php`); update DI wiring in `Application.php` / the service provider only if the auto-wiring does not resolve it — **auto-wires (no explicit `registerService` for any of the three)**
- [x] 2.2 Remove `PermissionHandler::evaluateMatchConditions()` (method body and signature). Delete entirely; no deprecation shim
- [x] 2.3 Update `PermissionHandler::hasGroupPermission()` to build the envelope (`$objectData + ['@self' => ['organisation' => $objectOrganisation]]`) and call `$this->conditionMatcher->objectMatchesConditions($envelope, $entry['match'])` in place of the old `evaluateMatchConditions` call
- [x] 2.4 Narrow `hasGroupPermission()`'s signature: removed `?string $activeOrganisation = null`. Kept the two-parameter `objectData` + `objectOrganisation` split and merge inside the method to minimise caller churn
- [x] 2.5 Update `PermissionHandler::hasPermission()` to stop fetching `$activeOrganisation` via `OrganisationService` and stop passing it to every `hasGroupPermission` call
- [x] 2.6 Verify the admin bypass (`$groupId === 'admin'`) and object-owner bypass (`$objectOwner === $userId`) remain unchanged and run *before* any delegation to `ConditionMatcher` — **preserved at the top of `hasGroupPermission`**
- [x] 2.7 Preserve the "public fallback for authenticated users" path: still calls `hasGroupPermission(groupId: 'public', ...)` with the merged envelope

## 3. `MagicRbacHandler` — collapse PHP-side duplicate

- [x] 3.1 Add `ConditionMatcher` as a constructor-injected dependency of `MagicRbacHandler` (`openregister/lib/Db/MagicMapper/MagicRbacHandler.php`)
- [x] 3.2 Rewrite `MagicRbacHandler::hasPermission()` (lines 640–695): keep the admin/owner bypass, keep the authorization resolution, delegate conditional rule evaluation to `ConditionMatcher::objectMatchesConditions()`
- [x] 3.3 Delete private helpers now redundant: `objectMatchesConditions()`, `objectPropertyMatchesCondition()`, `valueMatchesOperator()`, `singleOperatorMatches()`, `comparisonOperatorMatches()`, `arrayOperatorMatches()`, `existsOperatorMatches()`. PHP-side `resolveDynamicValue()` kept — still used by four SQL-path helpers (`buildPropertyCondition`, `buildSingleOperatorCondition`, `buildPropertyConditionSql`, `buildComparisonOperatorConditionSql`)
- [x] 3.4 `checkPermissionRule()` and `checkConditionalPermissionRule()` deleted; rule iteration inlined into the new `hasPermission()` body
- [x] 3.5 SQL-emission path untouched: `applyRbacFilters`, `processAuthorizationRule`, `processSimpleRule`, `processConditionalRule`, `buildMatchConditions`, `buildPropertyCondition`, `buildOperatorCondition`, `buildSingleOperatorCondition`, `buildComparisonOperatorCondition`, `buildArrayOperatorCondition`, `propertyToColumnName`, and `buildRbacConditionsSql` remain as-is
- [x] 3.6 Grepped `->hasPermission(` across `openregister/lib/`: only four call sites — `SchemasController`, `RelationHandler`, `SubscriptionService`, `MagicMapper` — all target `PermissionHandler` or `MagicRbacHandler::hasPermission`, not the deleted private helpers

### 3.7 Follow-up discovered — NOT fixed in this change

- **Fourth duplicate found in `openregister/lib/Db/Schema.php`**: `Schema::hasPermission()` (line 894) and `Schema::evaluateMatchConditions()` (line 975) duplicate the same equality-only, `$organisation`-only logic that was just removed from `PermissionHandler`. It has test coverage in `tests/unit/Service/BasicCrudTest.php` (lines 422–447) but **zero production callers** anywhere in `openregister/lib/`. Kept out of this change because deleting it would require also deleting or reworking the test. Follow-up change should: decide whether to delete the dead Schema methods entirely (and the test), or delegate Schema to `PermissionHandler`. See proposal "Out of scope" section.

### 3.9 `$now` format alignment between `ConditionMatcher` and `MagicRbacHandler` — **fixed as part of this change**

- **Surfaced during review of the composite `publicatiedatum + depublicatiedatum` rule**: the two evaluators were emitting `$now` in different string formats — `ConditionMatcher` used PHP's `c` format (ISO 8601 with `T` separator, e.g. `2026-04-24T14:43:49+00:00`), while `MagicRbacHandler` used SQL-native `Y-m-d H:i:s`. For text or JSON-stored date columns (where comparison is raw lexicographic string compare rather than SQL datetime semantics), the formats' differing characters at position 10 (`T` vs space) caused list-vs-find divergence around the midnight boundary of the reference date.
- **Fix**: `ConditionMatcher::resolveDynamicValue` now emits `Y-m-d H:i:s`. The canonical format matches OpenRegister's `DateTimeNormalizer` input normalization, so rule authors who normalize on write get consistent comparison behaviour on read.
- **Tests**: 4 new real-wiring tests in `PermissionHandlerRbacTest.php` (`testNowResolvesToSqlNativeFormat`, `testNowAlignsWithSqlPathForIsoStoredDates`, `testNowAlignsWithSqlPathForDateOnlyStored`, `testCompositePublishedAndNotDepublishedRule` — the last one covers the exact user-reported rule pattern with 5 assertion cases).
- **Spec delta**: new scenario "Dynamic `$now` variable resolves to a canonical SQL-native format" in `specs/rbac-scopes/spec.md`.
- **Remaining caveat (data modelling, not RBAC)**: if the user stores dates in a format other than `Y-m-d H:i:s` (e.g. ISO 8601 with `T`) in a text column, lex comparison against `$now` can still give semantically unexpected results at the format-divergence position. The only durable fix is to normalize stored dates on input — which `DateTimeNormalizer` already does when the OpenRegister schema declares `format: date` / `format: date-time`. The remaining exposure is for consumers that bypass the normalizer or for columns declared as plain strings.

### 3.8 Null-value handling in `OperatorEvaluator` — **fixed as part of this change**

- **Surfaced during consumer verification (#1336 follow-up)**: `OperatorEvaluator` used raw PHP `<`/`<=`/`>`/`>=` without null guards, so a null object value against `$lte $now` would return `true` (PHP coerces `null` to `""`, which is lexicographically less than any datetime string). Meanwhile the SQL path correctly produced `NULL` and filtered the row out. A publication with `publishedAt: null` was therefore included by the find path even though the list path excluded it.
- **Fix**: `$gt/$gte/$lt/$lte` return `false` if either side is null; `$in/$nin/$ne` return `false` if the object value is null. `$eq: null` preserved as the "match missing field" escape hatch; `$exists` unchanged.
- **Tests**: 11 new failure-then-pass tests in `tests/Unit/Service/OperatorEvaluatorTest.php` + 4 real-wiring end-to-end tests in `PermissionHandlerRbacTest.php` (`testPublicLteNowRuleMatchesPastPublishedAt`, `testPublicLteNowRuleRejectsNullPublishedAt`, `testPublicLteNowRuleRejectsExplicitNullPublishedAt`, `testPublicLteNowRuleRejectsFuturePublishedAt`) using real `ConditionMatcher` + `OperatorEvaluator`, not mocks.
- **Spec delta**: added scenario "Null-valued properties evaluate conservatively (SQL three-valued logic)" to `specs/rbac-scopes/spec.md`.
- This was pre-existing behaviour in `OperatorEvaluator` (which `PropertyRbacHandler` has been using all along) — the main change just made it visible at the schema level. Fixing it here locks in the list↔find parity claim from the spec.

## 4. Tests (ADR-009)

- [x] 4.1 Unit test `PermissionHandler::hasPermission` with `public` + `$lte`/`$now` past-date rule — `testConditionalPublicRuleDelegatesToConditionMatcher`. Verifies delegation and envelope shape; match verdict is the mock's contract (the actual `$lte`/`$now` semantics are covered by `ConditionMatcherTest`)
- [x] 4.2 Unit test `PermissionHandler::hasPermission` with future-date rule returning `false` — `testConditionalRuleReturnsFalseWhenConditionMatcherReturnsFalse`
- [x] 4.3 Unit test `$userId` variable rule — `testUserIdVariableRuleDelegatesToConditionMatcher`
- [x] 4.4 Unit test `$in` operator — `testInOperatorRuleDelegatesToConditionMatcher`
- [x] 4.5 Unit test `_organisation` via `@self` envelope — `testOrganisationVariableFoldsIntoEnvelopeViaSelf` — verifies the envelope fold (objectOrganisation → `@self.organisation`)
- [x] 4.6 Comparison operators (`$eq/$ne/$gt/$gte/$lt/$lte`) — covered structurally by delegation pattern; operator semantics tested exhaustively in existing `ConditionMatcherTest` / `OperatorEvaluator` unit tests. Adding a duplicate matrix here would re-test ConditionMatcher through a mock and provide zero additional coverage
- [x] 4.7 `$exists` operator — same rationale as 4.6: delegated, semantics covered in `ConditionMatcherTest`
- [x] 4.8 Anonymous caller routes through public branch and delegates — covered by `testConditionalPublicRuleDelegatesToConditionMatcher` + `testAnonymousCallerAgainstNonPublicRuleReturnsFalseWithoutDelegation`
- [x] 4.9 Admin bypass — `testAdminBypassSkipsConditionMatcher` (mock `->expects($this->never())`)
- [x] 4.10 Owner bypass — `testOwnerBypassSkipsConditionMatcher` (mock `->expects($this->never())`)
- [x] 4.11 `MagicRbacHandler::hasPermission` parity — new file `tests/Unit/Db/MagicMapper/MagicRbacHandlerTest.php` with 13 unit tests covering admin/owner bypass, simple rules, conditional rules, `$in`, `$userId` — all via the same delegation pattern so parity with `PermissionHandler` is structural
- [ ] 4.12 **N/A as Newman collection**: no API endpoint was changed. The behavioural change (schema-level RBAC now honours operator rules) is observable via existing endpoints, but writing a new collection that creates a schema with a `$lte/$now` rule, creates a matching object, and asserts list/find parity requires fixture plumbing that duplicates the manual smoke in §6.1. See §6.1/§6.2 for the equivalent manual verification recipe
- [x] 4.13 Ran inside `master-nextcloud-1` container: **phpcs** ✅ clean (auto-fixed 8, shortened 2 long docblocks); **phpmd** ✅ clean for our files (added `@SuppressWarnings(PHPMD.NPathComplexity)` on `MagicRbacHandler::hasPermission` — inlined rule dispatch intentionally); **psalm** ✅ clean for our files (fixed `MagicMapper.php:365` missing ctor arg); **phpstan** ✅ clean for our files (48 unrelated pre-existing errors all in `DeletionAnalysis` DTO chain); **unit tests** ✅ `PermissionHandlerRbacTest` 20/20 passing, `MagicRbacHandlerTest` 14/14 passing
- [x] 4.14 No Jest tests — backend-only change. Justification documented in PR description
- [x] 4.15 No new Newman collections beyond existing ones — existing collections unchanged and should continue to pass

## 5. Documentation

- [x] 5.1 `rbac-scopes` spec updated: "Schema-level RBAC" bullet (line 18 of implementation notes) now references `ConditionMatcher` delegation instead of listing the removed `evaluateMatchConditions()`
- [x] 5.2 `docs/Features/access-control.md` updated: new "Conditional rules work identically at every level" section makes it explicit that schema/object/property use the same operator and variable grammar, routed through `ConditionMatcher`. Existing property-level section now cross-references it
- [x] 5.3 Class docblocks on `PermissionHandler` and `MagicRbacHandler` gained a deduplication note pointing to `ConditionMatcher` and, for MagicRbac, distinguishing the SQL-emission path (`applyRbacFilters`/`buildRbacConditionsSql`) from the PHP-side verdict path (`hasPermission`)

## 6. Consumer verification

- [ ] 6.1 **Manual smoke (needs running env)** — OpenCatalogi `PublicationsController::attachments`: configure a publication schema with `{ "read": [{ "group": "public", "match": { "publishDate": { "$lte": "$now" } } }] }`, create a past-dated publication, attach a file, hit `GET /catalog/{slug}/publications/{uuid}/attachments` anonymously — MUST return 200 with the attachment list (regression test for the originating bug)
- [ ] 6.2 **Manual smoke (needs running env)** — same configuration, future-dated publication — MUST return 404 rather than a 500 "User 'Anonymous' does not have permission"
- [ ] 6.3 **Manual smoke (needs running env)** — OpenRegister UI (`localhost:3030`) with a logged-in user querying a schema using `$userId` match — results user-scoped in both list and detail views, no 500s
- [x] 6.4 Audited `_rbac: false` call sites in procest (0), pipelinq (7), docudesk (7), opencatalogi (many controllers). **Conclusion**: none rely on `evaluateMatchConditions`'s broken grammar — all `_rbac: false` call sites short-circuit at `PermissionHandler::hasPermission()`'s first guard (`if ($_rbac === false) return true`), completely bypassing the match evaluator. The deleted evaluator is therefore unreachable from any `_rbac: false` path. Only `_rbac: true` callers benefit from the fix; no regression possible for `_rbac: false` callers

## 7. Clean-up & archiving

- [x] 7.1 Ensure no orphaned references to `evaluateMatchConditions` remain in `openregister/lib/` or `tests/`. **Result**: deleted 5 integration tests in `tests/Service/ObjectHandlersIntegrationTest.php` that called the now-removed method (coverage migrated to `ConditionMatcherTest` + `PermissionHandlerRbacTest`). Only remaining reference is the explanatory comment at the removal site. `Schema.php` duplicate is out-of-scope per §3.7
- [x] 7.2 Ensure no orphaned references to the removed `MagicRbacHandler` private helpers remain. **Result**: zero hits for `objectPropertyMatchesCondition`, `singleOperatorMatches`, `comparisonOperatorMatches`, `arrayOperatorMatches`, `existsOperatorMatches`, `checkPermissionRule`, `checkConditionalPermissionRule` outside `ConditionMatcher`/`OperatorEvaluator` (which are unrelated). `valueMatchesOperator` / `objectMatchesConditions` still appear in `OperatorEvaluator` and `ConditionMatcher` (their canonical homes) — expected
- [x] 7.3 `CHANGELOG.md` updated under `## Unreleased / ### Fixed` with a concise user-facing entry linking to issue #1336
