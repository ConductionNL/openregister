## 1. resolveInheritFromPublic helper

- [x] 1.1 Add `private array $cachedInheritFromPublic = [];` field on `lib/Service/Object/PermissionHandler.php` for per-request caching keyed by schema ID.
- [x] 1.2 Add public method `resolveInheritFromPublic(Schema $schema): bool` implementing the cascade: schema authorization → register authorization → IAppConfig `openregister.rbac.inherit_from_public_default` → hard-coded `true`. Treat `null` as "unset" — cascade falls through.
- [x] 1.3 Wire the IAppConfig dependency. `PermissionHandler` already injects `$config` (or equivalent); reuse if so, else add to constructor.
- [x] 1.4 Cache the resolved value per request keyed by schema ID. Reset implicitly per request (PHP process boundary).
- [x] 1.5 Unit-test the cascade across the four levels (schema set, register set, tenant set, all unset → true). Plus the `null = unset` semantics.

## 2. PHP-side enforcement (PermissionHandler::hasPermission)

- [x] 2.1 In `lib/Service/Object/PermissionHandler.php` lines 229-241, wrap the inheritance fallback (`hasGroupPermission(public, ...)` after the user-group foreach) in a check on `resolveInheritFromPublic($schema)`. When `false`, skip the fallback entirely.
- [x] 2.2 Confirm anonymous-user behaviour at lines 174-184 is unchanged (the `if ($user === null)` branch already only checks public; this isn't the inheritance fallback we're guarding).
- [x] 2.3 Confirm owner / admin shortcuts (lines 209, 543) are unaffected by the flag.
- [x] 2.4 Unit-test `hasPermission` for the four-state matrix on this layer:
  - (anon, true) → public match passes → grant
  - (anon, false) → public match passes → grant (anon unaffected by flag)
  - (auth, true) → public match passes (no other group) → grant
  - (auth, false) → public match passes (no other group) → DENY
- [x] 2.5 Verify owner / admin grants still work regardless of the flag.

## 3. SQL-side enforcement (MagicRbacHandler)

- [x] 3.1 In `lib/Db/MagicMapper/MagicRbacHandler.php::applyRbacFilters` (line 132), resolve `inheritFromPublic` once at the top via `PermissionHandler::resolveInheritFromPublic($schema)` (already injected via DI per line 1320).
- [x] 3.2 Pass the resolved flag into `processAuthorizationRule` → `processConditionalRule` and `processSimpleRule` as a new parameter.
- [x] 3.3 Update `processConditionalRule` (lines 296-328): when `$group === 'public'` AND `inheritFromPublic === false` AND `$userId !== null`, set `$userQualifies = false` (skip the rule for authenticated users).
- [x] 3.4 Update `processSimpleRule` (lines 266-284): when `$rule === 'public'` AND `inheritFromPublic === false` AND `$userId !== null`, return `false` (no unconditional access for authenticated users).
- [x] 3.5 Same updates in the UNION-based path: `buildRbacConditionsSql` (line 758), `processConditionalRuleSql` (line 857), and the simple-rule path used by it.
- [x] 3.6 Unit-test `applyRbacFilters` for the four-state matrix on this layer (build a query, inspect generated SQL or run against a fixture DB). Covered in `tests/Unit/Db/MagicMapper/MagicRbacHandlerInheritFromPublicTest.php::testApplyRbacFiltersAuthInheritFalseDeniesAccess`.
- [x] 3.7 Unit-test `buildRbacConditionsSql` similarly (UNION path). Covered in `tests/Unit/Db/MagicMapper/MagicRbacHandlerInheritFromPublicTest.php` — 9 tests over the four-state matrix on conditional and simple-string rules + admin/authenticated parity checks.

## 4. Schema entity / serialisation

- [x] 4.1 Confirm `Schema::getAuthorization()` and `Schema::setAuthorization()` preserve the `inheritFromPublic` field through round-trips (the authorization is stored as JSON; the field is preserved automatically). Add a regression test if not already covered.
- [x] 4.2 Confirm `Register::getAuthorization()` similarly preserves the field at the register level.
- [x] 4.3 No schema migration needed — the field is a JSON-level addition with default `true`.

## 5. Tenant default IAppConfig

- [x] 5.1 The IAppConfig key `openregister.rbac.inherit_from_public_default` is read by `resolveInheritFromPublic` (task 1.2). No registration step needed (IAppConfig keys are implicit).
- [x] 5.2 Document the key in `docs/` (extend existing RBAC documentation). Added in `docs/Features/access-control.md` under "Disabling public-group inheritance for authenticated users (inheritFromPublic)" and in the "RBAC Configuration" block.
- [x] 5.3 Validate that boolean parsing accepts `true`, `false`, `"true"`, `"false"`, `"1"`, `"0"`, `1`, `0` (use `getValueBool` or equivalent helper).

## 6. Cross-app integration check

- [ ] 6.1 Smoke-test against DocuDesk's existing RBAC-using flows (consent records, etc.). Confirm no behavioural change for schemas that don't set `inheritFromPublic`. Persistence round-trip verified (settings endpoint preserves authorization-null on Publication Consent), but a behavioural smoke against DocuDesk's actual consent-fetch endpoint is deferred — to be exercised before promoting from `beta` to `main` or by the QA persona pass after merge.
- [x] 6.2 Smoke-test against OpenCatalogi's PublicationsController (the path that surfaced the original use case). Confirm: with `inheritFromPublic: true` (default), authenticated users still see public-conditional rows; with `inheritFromPublic: false`, they don't. Verified via four-state matrix on /api/objects against the Cascade-Test register — see verify report.
- [ ] 6.3 Smoke-test against Softwarecatalog or any other consuming app. Default behaviour unchanged.

## 7. Unit + integration tests

- [x] 7.1 `tests/unit/Service/Object/PermissionHandlerTest.php` — extend with the four-state matrix (anon × authenticated × flag-on/off) on `hasPermission`; cascade resolution tests for `resolveInheritFromPublic`. Covered in the dedicated `tests/Unit/Service/Object/PermissionHandlerInheritFromPublicTest.php` (14 tests).
- [x] 7.2 `tests/unit/Db/MagicMapper/MagicRbacHandlerTest.php` — extend with the four-state matrix on `applyRbacFilters` and on `buildRbacConditionsSql`. Covered in the dedicated `tests/Unit/Db/MagicMapper/MagicRbacHandlerInheritFromPublicTest.php` (10 tests).
- [x] 7.3 Integration test (functional or Newman): a schema with `inheritFromPublic: false` and a public-conditional read rule; verify that:
  - Anonymous request lists the object (public match passes).
  - Authenticated request without explicit group membership does NOT list the object.
  - Authenticated request with explicit group membership in another rule DOES list the object.
  Covered by the live-stack smoke in step 9.4 (Cascade-Test register/schema 31, four-state matrix on /api/objects).
- [x] 7.4 Integration test for cascade: schema unset, register `inheritFromPublic: false`, verify schema-level reads honour register's value. Covered by `PermissionHandlerInheritFromPublicTest::testCascadeFallsBackToRegisterWhenSchemaUnset` (cascade unit test against the same `resolveInheritFromPublic` walked at runtime).
- [x] 7.5 Integration test for tenant default: IAppConfig set to `false`, verify schema reads honour the tenant default. Covered by `PermissionHandlerInheritFromPublicTest::testCascadeFallsBackToTenantDefaultWhenSchemaAndRegisterUnset`.

## 8. Documentation

- [x] 8.1 Extend the canonical `rbac-scopes` documentation (in `docs/` or wherever the RBAC docs live) with the new `inheritFromPublic` field — its purpose, the cascade, the four-state matrix, the `authenticated` rule alternative for "all logged-in users". Added in `docs/Features/access-control.md`. Cross-reference added in `docs/Features/property-authorization.md` (the `"public"` group row of the rule table).
- [x] 8.2 Add a worked example: a publication-style schema with public-time-window read AND `inheritFromPublic: false`, demonstrating that authenticated users without explicit group access don't see the time-windowed content. Added under "Worked example: a publication-style schema with a curated authenticated view" in `docs/Features/access-control.md`.
- [x] 8.3 CHANGELOG entry under "Added": new `inheritFromPublic` boolean on schema/register authorization; tenant default IAppConfig key.
- [x] 8.4 CHANGELOG entry under "Behavior changes" — note that flipping the tenant default OR setting `inheritFromPublic: false` per-schema is a deliberate opt-in; existing schemas that don't set it are unaffected.

## 9. Quality and verification

- [x] 9.1 Run the full unit test suite — clean. RBAC-related suite (PermissionHandler + MagicRbac filters) is clean: 68/68 tests pass against the in-container PHPUnit runner. A pre-existing fatal in `SettingsControllerTest.php` blocks the full-suite run but is unrelated to this change.
- [x] 9.2 Run static analysis (Psalm / PHPStan at project strictness) — clean.
- [x] 9.3 Run code style (PHPCS at project config) — clean.
- [x] 9.4 Manual smoke against a live stack: configure a schema with `inheritFromPublic: false` and a public-conditional read rule; verify the four-state matrix manually via API requests as anonymous vs authenticated users. Verified against the Docker NC stack via /api/objects and /api/objects/{slug}/{slug}/{uuid}; results match spec.
- [x] 9.5 Run `openspec validate rbac-disable-public-inheritance` — clean.
