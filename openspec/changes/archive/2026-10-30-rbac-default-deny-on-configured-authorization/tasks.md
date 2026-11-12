## 1. Single-object enforcement (PermissionHandler)

- [x] 1.1 In `lib/Service/Object/PermissionHandler.php::hasGroupPermission()` (~line 1017), replace the open-default `if (isset($authorization[$action]) === false) { return true; }` with the fail-closed `if (empty($authorization[$action]) === true) { return false; }`.
- [x] 1.2 Verify the preceding branches are left intact: admin bypass (~line 1002), object-owner bypass (~line 1007), and the `empty($authorization) === true` open-default (~line 1012).
- [x] 1.3 Update the stale docblock at ~lines 962–964 (the `hasGroupPermission` rule list) so "action not specified → everyone has permission" is replaced with the new fail-closed default; keep the empty-block open-default and admin/owner-bypass lines accurate.
- [x] 1.4 Update the stale docblock at ~lines 195–196 to match the new default.

## 2. Row-level and SQL-list enforcement (MagicRbacHandler)

- [x] 2.1 In `lib/Db/MagicMapper/MagicRbacHandler.php::hasPermission()` (~line 884), change the omitted-action verdict from `if (empty($rules) === true) { return true; }` to `return false;`.
- [x] 2.2 Verify the admin bypass (~line 863), owner bypass (~line 868), and `empty($authorization)` open-default (~line 876) are left intact in `hasPermission()`.
- [x] 2.3 In `lib/Db/MagicMapper/MagicRbacHandler.php::applyRbacFilters()` (~line 195), delete the "action not configured ⇒ open access" early-return block (~lines 195–202) so flow falls through to the owner condition (~line 208) and the existing deny-all impossible predicate (`1 = 0`, ~lines 247–260).
- [x] 2.4 Verify the `empty($authorization)` open-default (~line 184) and the admin early-return remain intact in `applyRbacFilters()`, and that any deny-branch logging stays metadata-only (ADR-005, no object values/PII).
- [x] 2.5 (added during apply) In `lib/Db/MagicMapper/MagicRbacHandler.php::buildRbacConditionsSql()` (~line 996) — the raw-SQL/UNION variant used by `MagicSearchHandler` (~line 575) — remove the identical omitted-action `return ['bypass' => true, 'conditions' => []]` so flow falls through to the owner condition and the documented `bypass => false`/empty-conditions deny-all contract. Keeps all FOUR paths consistent and prevents an IDOR-style search-vs-GET divergence.

## 3. Exclusions and consistency check

- [x] 3.1 Confirm `lib/Service/PropertyRbacHandler.php` (~line 270) is NOT changed — property-level absence must still inherit object-level rules, not deny. (Verified: `git diff` shows it untouched.)
- [x] 3.2 Confirm the list/search path still enforces `action: 'read'` (`lib/Db/MagicMapper/MagicSearchHandler.php` ~lines 575, 1058, 1090), so `read`-configured schemas keep public read and browse and only write actions lock down. (Verified.)

## 4. Tests

- [x] 4.1 PHPUnit `PermissionHandler` RBAC tests: added `testActionNotConfiguredOnNonEmptyBlockFailsClosed` (granted action works; omitted create/update/delete deny) in `PermissionHandlerRbacTest`. Also inverted `RbacTest::testSchemaHasPermissionMissingAction` (the `Schema::hasPermission` entity helper — a 5th, currently-unused implementation flipped for consistency).
- [x] 4.2 PHPUnit `MagicRbacHandler` tests: inverted `testActionNotConfiguredGrantsOpenAccess` → `...DeniesAccess` and added `...DeniesAnonymous` (unit); inverted the two integration tests (`testHasPermissionUnconfiguredActionFailsClosed`, `testBuildRbacConditionsSqlUnconfiguredAction`) guarded by `isAdmin()` so the CLI runner's admin status doesn't flake. Bypass and empty-block tests left intact.
- [x] 4.3 Newman RBAC suite: no scenario needed inverting — existing scoped scenarios are FULLY configured (all four actions) and the open scenario uses an empty block, so none expected 200 on an omitted action. Corrected the suite's MODEL `description` to document the new partial-config fail-closed rule. (A dedicated partial-config 403 e2e scenario can be added and validated in-container under 4.4.)
- [x] 4.4 Ran the strict suite in an isolated container env (a `/tmp` copy with dev-deps installed via `--ignore-platform-reqs`; the live `--no-dev` app untouched). Results vs `beta` on the changed files: **PHPCS 0 new errors** (per-file counts identical to beta), **PHPStan 94 vs 95** (0 new — one fewer), **Psalm 3 vs 3** (0 new), **`php -l` clean**. **RBAC unit suite 66/66 green** (PermissionHandlerRbacTest, MagicRbacHandlerTest, RbacTest — incl. two pre-existing stale validation-message assertions aligned to current code). Whole-repo phpmd/phpstan/psalm pre-existing debt is unchanged. Newman + cross-app (opencatalogi/softwarecatalog) regression deferred to CI (needs a running NC instance + the `e2euser`).

## 5. Seed/config authorization review (ADR-016)

- [x] 5.1 Reviewed the 20 read-only installed schemas (`bag`, `brp`, `kvk`, `ori`, `opencatalogi` publication register). Write lockdown accepted for the external reference registers (bag/brp/kvk/ori). For the opencatalogi publication schemas the decision is to **leave explicit write grants to the opencatalogi maintainers** (those configs live in the opencatalogi tree, not OpenRegister) — flagged in the audit.
- [x] 5.2 Confirmed `dso` and `n8n_workflows` are unaffected (no authorization blocks → open default preserved).

## 6. Cross-app follow-up

- [x] 6.1 Cross-app follow-up tracked: **Codeberg issue docudesk#125** (pre-migration, not migrated to GitHub; `docudesk` is now `filinq`) — add an explicit `read` grant to the `docudesk` **Publication Prohibition** schema in `docudesk/lib/Settings/docudesk_register.json` (its config omits `read`, so read/list would deny for non-admin/non-owner after this change). That file is outside OpenRegister's tree; the fix lands in the docudesk repo before/with this change reaching production.
