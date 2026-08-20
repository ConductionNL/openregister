## 1. Route-query scoping (RegisterSchemaSelector)

- [x] 1.1 On `mounted()`, adopt `?register=` (+ optional `?schema=`) over the store: set the store selection, populate the selects, fetch schemas, and let the view watcher load data.
- [x] 1.2 Fall back to the persisted store selection when no route query is present, and mirror the restored selection into the URL.
- [x] 1.3 In `handleRegisterChange` / `handleSchemaChange`, mirror the selection into the route via `$router.replace({ query })` (swallow `NavigationDuplicated`); changing the register clears the schema in the URL.
- [x] 1.4 Add `data-testid="mdm-register-select"` / `data-testid="mdm-schema-select"` to the two selects; keep their `inputLabel`.
- [x] 1.5 Add `@spec` tags on the changed selector methods pointing to `mdm-views-route-scoping` scenarios.

## 2. Action-control test handles

- [x] 2.1 `DuplicatesIndex`: `data-testid` on the candidate row + per-pair Merge launch.
- [x] 2.2 `MergeOperationsIndex`: `data-testid` on the operation row + Reverse button.
- [x] 2.3 `MasterEntitiesIndex`: `data-testid` on the entity row + "View golden record".
- [x] 2.4 `GoldenRecordDetail`: `data-testid` on "Resolve conflicts".
- [x] 2.5 `MdmMergeWizardModal`: `data-testid` on Confirm + the reason select.
- [x] 2.6 `MdmConflictResolutionModal`: `data-testid` on the source picker + Save.

## 3. Self-seeding e2e fixture

- [x] 3.1 `tests/e2e/mdm-seed.ts`: discover the `pipelinq` register + `masterEntity`/`sourceRecord`/`mergeOperation` schema ids; no-op + return null when absent.
- [x] 3.2 Seed a validation-safe duplicate pair, a multi-source conflict entity, and good/fair/poor scored entities via `POST /api/objects/{register}/{schema}`; make it idempotent with the `e2e-mdm-` marker.
- [x] 3.3 Best-effort verify the pair is detectable, then write ids + uuids to `tests/e2e/.mdm-seed.json`.
- [x] 3.4 Hook `seedMdm` into `globalSetup` behind a fresh disposable request context + try/catch so a non-pipelinq instance never fails setup.

## 4. Make the committed specs RUN the chains

- [x] 4.1 Read `.mdm-seed.json`; keep the `test.skip()` fallback when absent; fix navigation to hash-mode routes (`#/quality`, `#/master-entities`, `#/mergeOperations`).
- [x] 4.2 `mdm-frontend`: route-scoped populated KPIs + histogram + lowest-quality + master-entities table + golden-record detail.
- [x] 4.3 `mdm-merge-ui`: route-scoped duplicate → merge wizard → preview → reason → confirm → refresh → Merge Operations audit row → reverse.
- [x] 4.4 `mdm-survivorship-override`: open the seeded conflict entity → Resolve conflicts → pick source → persistent + one-off outcomes.

## 5. Verify

- [x] 5.1 `npm run lint` clean on touched files.
- [x] 5.2 `npm run build` succeeds with the route changes.
- [x] 5.3 e2e TypeScript parses (`tsc --noEmit` on the specs/seed).
- [x] 5.4 `openspec validate mdm-views-route-scoping-e2e --strict` passes.
