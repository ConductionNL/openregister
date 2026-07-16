# Tasks

## 1. Verify (do not trust the audit)
- [x] 1.1 Verify finding 1 (fail-open) against HEAD
- [x] 1.2 Verify finding 2 (seed phantom) against HEAD + fleet-wide read-only scan
- [x] 1.3 Verify finding 3 (processing dropped) against HEAD
- [x] 1.4 Establish clean baseline from origin/development

## 2. Fail closed (CWE-863)
- [x] 2.1 Add `AuthorizationUnresolvableException` (SPDX EUPL-1.2)
- [x] 2.2 `getRegisterAuthorization()`: log + throw; never cache the failure
- [x] 2.3 `getRegisterForSchema()`: same shape — log + throw
- [x] 2.4 Route `evaluatePermission()` / `getReadableByUsers()` /
      `resolveRegisterInheritFromPublic()` / `getRegisterConfiguration()` to denials
- [x] 2.5 `MagicRbacHandler`: propagate + clamp to deny-all (both call sites)

## 3. Vocabulary drift
- [x] 3.1 Remove phantom `x-openregister-seed`
- [x] 3.2 Add engine-read `x-openregister-processing`
- [x] 3.3 Relocate the 6 MDM trust rules to `components.objects`

## 4. Tests
- [x] 4.1 Fail-closed: unresolvable authorization DENIES every action
- [x] 4.2 Fail-closed: the failure is LOGGED
- [x] 4.3 Fail-closed: the failure is NOT cached as an answer
- [x] 4.4 Prove all three fail against pre-fix code
- [x] 4.5 `x-openregister-processing` round-trips and reaches its engine
- [x] 4.6 `x-openregister-seed` is dropped loudly; invert the round-trip tests
      that encoded the "not dropped = consumed" fallacy
- [x] 4.7 Trust seeds live where the importer reads them
- [x] 4.8 Full suite: baseline + delta, zero new failures

## 5. Ship
- [x] 5.1 Spec delta
- [x] 5.2 Stale-base guard, PR, admin-merge
- [x] 5.3 Archive; report the gate blind spot on #439
