# Tasks

## 1. Reconcile (do not trust the source commit)
- [x] 1.1 Verify the commit is unreachable from every Codeberg branch (464 checked)
- [x] 1.2 Establish a clean baseline worktree from `origin/development`
- [x] 1.3 Verify each of the 5 fixes against HEAD by BEHAVIOUR, not symbol name
- [x] 1.4 Identify sub-fixes independently re-implemented since the fork
- [x] 1.5 Identify sub-fixes that conflict with newer architecture
- [x] 1.6 Confirm `check:strict` swallows exit codes; run each tool directly instead

## 2. readOnly enforcement
- [x] 2.1 `ValidateObject::validateReadOnlyConstraints()` (+ `collectReadOnlyPropertyNames()`)
- [x] 2.2 `ObjectService::enforceReadOnlyOnUpdate()`, wired after hard validation
- [x] 2.3 Confirm Opis has no `readOnly` keyword parser (the gap is real)
- [x] 2.4 Disambiguate property-level `readOnly` from objectSource-level `readOnly`

## 3. Default-closed writes (CWE-862)
- [x] 3.1 `rbac.enforce_default_closed` flag, default false
- [x] 3.2 `applyDefaultClosedPolicy()` in `evaluatePermission()` (Schema in scope)
- [x] 3.3 Preserve the admin AND owner bypasses
- [x] 3.4 Warn-once per schema per action while the flag is off
- [x] 3.5 Drop the `"public": true` opt-in — broken against the current fail-closed branch

## 4. Bulk write invariants
- [x] 4.1 `resolveExistingUuids()` — one batched lookup per chunk, chunked at 500
- [x] 4.2 Derive each row's real action; authorize existing rows as `update`
- [x] 4.3 Enforce append-only on rows targeting an existing object
- [x] 4.4 Confirm the other 3 original sub-parts are already present or unreachable

## 5. Dot-syntax tokens
- [x] 5.1 `ConditionMatcher::resolveDotted()` (PHP path)
- [x] 5.2 `MagicRbacHandler::resolveDotted()` (SQL path) — parity is mandatory
- [x] 5.3 Unknown token → null → deny, logged, on both paths
- [x] 5.4 Split out `$user.groups` (array; no scalar SQL equivalent)

## 6. Per-object `_authorization`
- [x] 6.1 Verify it is genuinely dead storage (enumerate every reader)
- [x] 6.2 Layer the merge onto the fail-closed body; preserve `@throws` + `@spec`
- [x] 6.3 Wire the single live consumer — `evaluatePermission()` passes the object
- [x] 6.4 Action-scoped merge (a whole-block merge silently denies other actions)
- [x] 6.5 Restrict to write actions; ignore + log `read` overrides
- [x] 6.6 Drop the permission cache for objects carrying a non-empty block

## 7. Tests
- [x] 7.1 readOnly: 11 tests
- [x] 7.2 Default-closed + per-object authorization: 16 tests
- [x] 7.3 Bulk invariants: 8 tests
- [x] 7.4 Dot-syntax: 13 tests
- [x] 7.5 Prove the capability tests FAIL against pristine `origin/development`
      (28 of 48 do; the other 20 are BC guards that must pass both sides)
- [x] 7.6 Fix the blanket `getValueBool` stub in `PermissionHandlerRbacTest` that
      silently opted every later flag into its non-default value

## 8. Quality
- [x] 8.1 Full unit suite vs baseline BY TEST NAME — zero regressions
- [x] 8.2 PHPCS clean on all changed lib files (incl. 2 pre-existing, fixed)
- [x] 8.3 PHPStan: 32 = baseline 32, zero new
- [x] 8.4 PHPMD: identical to pristine, zero new
- [x] 8.5 Suppress `TooManyMethods` with justification; flag decomposition

## 9. Follow-ups to file
- [ ] 9.1 `$user.groups` dot-syntax needs an SQL `IN`-predicate design
- [ ] 9.2 Per-object `read` overrides need the SQL list path to express them
- [ ] 9.3 `MagicRbacHandler` has its own default-open; mirror the flag if reads close
- [ ] 9.4 `PermissionHandler` decomposition (29 methods vs threshold 25)
- [ ] 9.5 `readOnly` on engine-written relation arrays in the ZaakRegister examples
- [ ] 9.6 `check:strict` swallows 4 of 6 gates via `|| echo` — fleet-wide
- [ ] 9.7 Decide: organisation from `@self` — admin-only, or verified-member (current)
