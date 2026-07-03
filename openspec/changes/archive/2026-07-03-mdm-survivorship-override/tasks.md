## 1. Backend — per-object override primitive

- [x] 1.1 Extend `SurvivorshipResolver::resolveGoldenRecord()` to accept an optional per-object override map and short-circuit `pickWinner()` when an override exists for an attribute (override value wins, even with no source); skip a malformed override map/entry without throwing. `@spec` the modified `mdm-survivorship` requirement; keep the resolver pure.
- [x] 1.2 Emit a manual-override provenance entry (`override: true`, `overriddenBy`, optional `rationale`) instead of a `trustTier` entry for an overridden attribute.
- [x] 1.3 In `SurvivorshipRecomputeListener`, read the override map from `overridesField` (default `attributeOverrides`), thread it into the resolver, and preserve the map across recomputes in `materialise()` (an unrelated save MUST NOT drop overrides). `@spec` the added preservation requirement.
- [x] 1.4 Add `SurvivorshipController::override()` (`#[NoAdminRequired]` + `#[NoCSRFRequired]`) setting/clearing one attribute override, recording actor + optional rationale, triggering a recompute via the object write path (RBAC/tenant scoped through `ObjectService`), returning the recomputed object. SPDX docblock header; `@spec` the endpoint requirement.
- [x] 1.5 Register `survivorship#override` → `POST /api/objects/survivorship/{id}/override` in `appinfo/routes.php` (route-reachability + route-auth).

## 2. Frontend — conflict-resolution modal + store

- [x] 2.1 Add `setAttributeOverride(id, attribute, value, rationale)` and `persistTrustRule({entityType, attribute, sourceSystem, trustTier, rationale})` to `src/store/modules/quality.js` — thin `generateUrl` + axios wrappers following the `previewMerge`/`executeMerge` pattern (loading, `error` capture, return data, no custom base class). `@spec` the store-action requirement. (Also added `clearAttributeOverride` + `touchObject` thin actions to support the modal's clear and persistent-outcome-recompute flows.)
- [x] 2.2 Create `src/modals/mdm/MdmConflictResolutionModal.vue` (modal-isolation): compute per-attribute conflicts (>1 distinct non-empty source value), one `NcSelect` per conflict with `inputLabel` (nc-input-labels), persistent/one-off outcome toggle, optional rationale, save/cancel; on save dispatch the matching store action, recompute/refresh, emit `saved`; error toast on failure keeps the modal open. SPDX header; English i18n keys.
- [x] 2.3 Launch the modal from `src/views/quality/GoldenRecordDetail.vue` (button in the golden-record header) passing the object + its source records; refresh the golden record on `saved`.

## 3. Tests & coverage

- [x] 3.1 PHPUnit: resolver override cases (override wins over gold; override populates an unsourced attribute; malformed override skipped) + listener preservation across an unrelated recompute + override isolation between objects.
- [x] 3.2 PHPUnit: `SurvivorshipController::override()` sets/clears an override and returns the recomputed object; unauthorised caller gets forbidden/not-found (run the CI way — php:8.3-cli + OCP stubs).
- [x] 3.3 jest: `quality.js` `setAttributeOverride` + `persistTrustRule` post to the correct URLs and surface errors; extend `quality.spec.js`.
- [ ] 3.4 gate-19 `@e2e` + gate-26 visual: e2e (`tests/e2e/spec-coverage/mdm-survivorship-override.spec.ts`) and visual (`tests/e2e/visual/mdm-survivorship-override.visual.spec.ts`) specs written, but NOT run against a live seeded browser in this pass (no live NC/browser available in this backend-focused verification run) — the visual baseline PNG is therefore NOT committed yet. Live-browser verification + baseline capture remains outstanding.
- [x] 3.5 Run `npm run build` (frontend compiles) and `composer check:strict`-equivalent (PHPCS/PHPMD/Psalm/PHPStan run individually, CI-equivalent php:8.3-cli container) clean.

## Acceptance Criteria

- The steward opens a conflict-resolution modal from `GoldenRecordDetail` that lists only attributes whose linked sources disagree.
- A persistent choice writes a `trustConfiguration` row via generic `/api/objects` CRUD (no bespoke endpoint) and recomputes this object's golden record.
- A one-off choice sets a per-object override via `POST /api/objects/survivorship/{id}/override`; the golden record reflects the override without altering any trust rule.
- An override wins over the tier-selected value, is marked a manual override in provenance, and is preserved across unrelated recomputes; clearing it falls back to trust resolution.
- Overrides are isolated to their object; an unauthorised caller cannot set an override.
- No new register or schema ships; trust rules and overrides reuse existing storage.

## Quality Checklist

- SPDX EUPL-1.2 header in the docblock of every new PHP file.
- `@spec openspec/changes/mdm-survivorship-override/...` on every changed backend + frontend method (gate-16).
- New route declares its auth posture and is registered in `appinfo/routes.php` (route-auth + route-reachability).
- Override endpoint enforces authorisation through `ObjectService` (no-admin-idor); no trusted object id.
- Modal in its own `src/modals/` file (modal-isolation); every `NcSelect` has `inputLabel` (nc-input-labels); no DOM data-attribute reads.
- English i18n source strings (ADR-025); nc-vue Options-API store, no custom base class (ADR-026).
- New modal has gate-26 visual coverage and a gate-19 `@e2e` reference.
- `composer check:strict`, `npm run build`, PHPUnit, and jest all pass.
- Placeholder ids in any fixtures use nil UUIDs (`00000000-0000-0000-0000-000000000000`).
