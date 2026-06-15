# Tasks: OpenRegister Adopts Its Own AppHost

## 0. Baseline

- [ ] 0.1 Capture baseline: `curl /apps/openregister/api/health` JSON + `/api/metrics` Prometheus text on a seeded dev instance; store as fixture for parity diff

## 1. Manifest descriptors

- [ ] 1.1 Add `observability` block to `src/manifest.json` per proposal (2 health checks, 9 metric descriptors)
- [ ] 1.2 Validate via ManifestService diagnostics (no errors)

## 2. Wiring and deletion

- [ ] 2.1 Alias `OCA\OpenRegister\Controller\HealthController`/`MetricsController` routes to the AppHost generics (Bootstrap or direct alias)
- [ ] 2.2 Delete the hand-written controller bodies; keep route names/URLs identical
- [ ] 2.3 Sweep references (tests, OCS registration, docs)

## 3. Parity verification

- [ ] 3.1 Diff new endpoint output vs 0.1 baseline: identical metric names, types, label sets; health shape identical; document any intentional deltas (expected: none)
- [ ] 3.2 Newman contract collection green against OR itself in CI
- [ ] 3.3 Existing OR e2e + unit suites green

## 4. Docs

- [ ] 4.1 Update OR observability docs page: OR itself now runs declaratively; link manifest block as the living example

## 5. Quality gates

- [ ] 5.1 `composer check:strict` + 18 hydra gates green; `@spec` tags updated
