## 1. Importer fix

- [ ] 1.1 In `ImportHandler::importFromJson()` (`lib/Service/Configuration/ImportHandler.php`), immediately before the object loop (~line 1960), build a merged seed-object list from `data['components']['objects']` plus a top-level `data['objects']` array.
- [ ] 1.2 De-duplicate the merged list by `@self` identity (slug within (register, schema), falling back to `@self.id`/uuid) so an object declared in both keys is processed once.
- [ ] 1.3 Feed the merged list into the existing loop (register/schema resolution, search-by-(register, schema, slug), version compare, `saveObject()` with `_rbac:false`/`_multitenancy:false`, per-entity try/catch) without altering that logic, the slug guard, or `@ref` token resolution.
- [ ] 1.4 Ensure folded top-level objects are counted in the existing `result['objects']` and `result['skipped']['objects']` counters.

## 2. Tests

- [ ] 2.1 Add a test proving top-level `objects` seed entries materialise after a forced import (assert saved-object count / `saveObject()` invocations) in `tests/Unit/Service/Configuration/`.
- [ ] 2.2 Add an idempotency test: re-importing the same top-level seed does not duplicate (match by (register, schema, slug)/uuid; unchanged version skipped).
- [ ] 2.3 Add an equivalence test: identical objects at `components.objects` vs top-level produce the same result; and a regression test that a `components.objects`-only config is unchanged and a slug-less top-level object is skipped.

## 3. Validation

- [ ] 3.1 Run the configuration import test suite (`ImportHandler*Test`) and confirm new + existing tests pass.
- [ ] 3.2 Run `composer check:strict` on `ImportHandler.php` and fix any new findings.
- [ ] 3.3 Smoke-verify against `shillinq/lib/Settings/shillinq_register.json` (78 top-level objects) — a forced import imports the seed objects (0 → 78) and a second import does not duplicate.

Acceptance criteria:
- A top-level `objects` array imports identically to `components.objects` on the app-init/forced path.
- Re-import is idempotent (match by `@self` slug/uuid; no duplicates).
- `components.objects`-only configs and the slug-skip guard are unchanged.
- Fix is OR-side only — no app change to `loadConfiguration`/`importFromApp`.

Quality:
- No new PHPCS/PHPMD/PHPStan/Psalm regressions; SPDX header + PHPDoc preserved.
- WARNING logging and `skipped.objects` counters cover folded top-level objects.
