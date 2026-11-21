## 1. Resolution

- [x] 1.1 Read `relatedObject` and `lookup` references with `_rbac: false` and `_multitenancy: false` in `lib/Service/Calculation/ReferenceResolver.php`.
- [x] 1.2 Add `lib/Service/Calculation/ReferenceTenantGuard.php` with the rule in `design.md`, and run every resolved row through it.
- [x] 1.3 Report unresolved references through `ReferenceResolver::resolveAllWithOutcome()`; pass the saving object's organisation from every caller.

## 2. Keep the stored value

- [x] 2.1 Carry the unresolved list in the payload and strip it before persisting (`CalculationPayloadBuilder`).
- [x] 2.2 Skip a calculation reading an unresolved reference and record an `error` verdict in `CalculationOnSaveListener::process()`.
- [x] 2.3 Apply the same skip in `TemporalCalculationSweepService::recomputeChanges()`.

## 3. Tests

- [x] 3.1 `tests/Unit/Service/Calculation/ReferenceResolverTest.php`: reads as the system, same tenant, cross tenant, org-less either side, parent organisation, shared master data, missing target against empty key, lookup skipping foreign rows.
- [x] 3.2 `tests/Unit/Listener/CalculationOnSaveUnresolvedReferenceTest.php`: real listener, builder, resolver, guard and evaluator; anonymous save computes, cross-tenant keeps stored values, unresolved keeps stored values and traces the reason.

## 4. Live check

- [ ] 4.1 On the integration instance, withdraw a dossiq case as a resident on the portal. `statusPublicLabel`, `deadline` and `statutoryTerm` stay set, and the audit trail shows no null write. (live pass, decision 139)
