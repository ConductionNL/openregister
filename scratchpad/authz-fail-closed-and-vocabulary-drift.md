# authz-fail-closed-and-vocabulary-drift — report

Tracked on openregister#439. PRs: **#441** (apply, merged), **#442** (archive, merged).
Archived to `openspec/changes/archive/2026-07-16-authz-fail-closed-and-vocabulary-drift/` (`status: done`).

## Verify verdicts (all three CONFIRMED against HEAD)

| # | Finding | Verdict | Evidence |
|---|---------|---------|----------|
| 1 | `getRegisterAuthorization()` fail-open, unlogged, cached | **CONFIRMED — and wider than reported** | `catch (\Throwable) { $this->cachedRegisterAuth[$id] = null; return null; }`, no logger call |
| 2 | `x-openregister-seed` in vocabulary, zero engines | **CONFIRMED** | only hit in `lib/` is the vocabulary entry itself (`Schema.php:2105`) |
| 3 | `x-openregister-processing` read but not in vocabulary | **CONFIRMED** | `ProcessingLogService::ANNOTATION_KEY`; vocabulary had only the *different* `…-processing-activity` |

### Where the audit was imprecise
- **Finding 1 understated blast radius.** The audit said "a sibling resolver logs on the same shape;
  this one doesn't" — implying the sibling was fine. It wasn't: `getRegisterForSchema()` logged a
  **warning** and still returned `null` → open. **Logging a fail-open does not make it fail closed.**
  Fixed too. The fail-open also reached `MagicRbacHandler` (`empty($auth)` → `bypass => true`),
  so RBAC **SQL filtering** was dropped, not just the PHP verdict. 4 resolvers/callers fixed, not 1.
- **Finding 2's MDM claim root-caused.** The 6 trust rules sat at
  `components.schemas.trustConfiguration.x-openregister-seed.objects`; `ImportHandler` only reads
  `components.objects` / top-level `objects`. The importer existed (`ImportTrustConfigurationRegister`
  runs fine) — the **seed location** was the phantom. Relocated; all 6 objects + every field preserved
  (verified semantically, not by eyeballing the diff).

## Fail-closed test proof (denied + logged + not cached)

`tests/Unit/Service/Object/PermissionHandlerFailClosedTest.php` — 3 tests, **all fail on pre-fix code**
(reverted `PermissionHandler.php` to `origin/development` and re-ran):

```
1) testUnresolvableAuthorizationDeniesEveryAction
   Action "read" must be DENIED when authorization cannot be resolved
2) testUnresolvableAuthorizationIsLogged
   Authorization resolution failure MUST be logged, not swallowed
3) testResolutionFailureIsNotCachedAsAnAnswer
FAILURES! Tests: 3, Failures: 3
```
Post-fix: `OK — Tests: 3, Assertions: 9`. The non-caching test uses a mapper that throws once then
recovers, and asserts the lookup is **retried** — a cached failure would replay a transient error as a
permanent (open) verdict.

## Seed decision: REMOVE the key (+ relocate OR's own seeds)

Fleet scan (read-only, `git ls-files`):

| App | Key | Count | Content |
|-----|-----|-------|---------|
| scholiq | `x-openregister-seed` | 22 | **all empty arrays** — no data at risk |
| openregister | `x-openregister-seed` | 1 | the 6 MDM trust rules — **relocated** |
| decidesk | `x-openregister-seed**s**` (plural) | 21 | a *different* key, never in the vocabulary — already dead |

Why remove, not implement: OR **already has** a seed engine (`ImportHandler` → `components.objects`);
a second dialect is the drift this change ends. Nothing real breaks (only OR's own held data, migrated).
Removal makes future declarations **fail loudly** via the dropped-key warning (#396 anti-phantom).

## Processing round-trip proof
`testProcessingAnnotationSurvivesRoundTripAndReachesItsEngine` asserts the key survives
`setConfiguration()` **keyed by `ProcessingLogService::ANNOTATION_KEY` itself** — i.e. the value the
**engine** reads is the value written (`logReads: true`), not merely "not dropped".

## Root-cause insight: "not dropped" ≠ "consumed"
The contract only required a key to **round-trip**, never that anything **reads** it.
OR's own `testSeedAnnotationSurvivesRoundTrip()` was **green the entire time the 6 trust rules were
never planted**. A round-trip test on an annotation proves storage, never behaviour. Those tests are
inverted here to assert the phantom is rejected.

## Why gate `unsafe-auth-resolver` missed it — a PATH gap, not a logic gap
The gate globs **non-recursively**: `for f in lib/Service/*.php lib/Controller/*.php`.
`PermissionHandler.php` is at `lib/Service/**Object/**PermissionHandler.php` — one level deeper, never opened.
Measured on this repo: the glob scans **227 of 1264** `lib/` PHP files — **82% unscanned**, including
`lib/Service/Object/`, `lib/Db/` and `lib/Db/MagicMapper/`.
The **detection logic would have caught this verbatim** (`getRegisterAuthorization` matches its
`[Aa]uthori[sz]ation` regex; the catch block held a bare `return null`).
**Blind spot to fix in hydra: `find lib -name '*.php'`.** Today the deeper a security-critical class
sits, the less likely it is checked — exactly backwards. Same idiom likely under-scans other gates.

## Baseline + delta (real output, no fabrication)
Container: `or-phpunit-83-full:local` (php 8.3 + zip/bcmath/soap/xsl/intl/gd), fresh composer install,
`phpunit-unit-local.xml`, `memory_limit=2G`. (`phpunit-unit.xml` cannot run outside an NC root — fatals at bootstrap.)

```
BASELINE (pristine origin/development @ 04e59be30):
  Tests: 14727, Assertions: 32254, Errors: 54, Failures: 18, Warnings: 8, Skipped: 22
THIS BRANCH (merged with development):
  Tests: 14735, Assertions: 32309, Errors: 54, Failures: 18, Warnings: 8, Skipped: 22
```
+8 tests. Errors and failures **identical**. Verified by test-**NAME** diff (`comm -13`), not counts —
**zero new failure names**.

One regression surfaced mid-run and was fixed at root, not silenced:
`PermissionHandlerCustomScopeTest` used an unconfigured `ContainerInterface` mock (returning `null` for
`RegisterMapper`), so it **depended on the fail-open** to reach the listener path. Wired the mock
realistically. Its 2 other failures are pre-existing in baseline.

## Gates: 37/39 pass
Both failures are **pre-existing and untouched by this diff** (proven against `origin/development`):
- **gate-46 spec-anchor-existence** — 25 `@spec` tags pointing at evaporated `openspec/changes/retrofit-*`
  dirs. Baseline has **5056** fleet-wide. All of this change's anchors resolve (synced the canonical spec).
- **gate-52 orphaned-write-capability** — `clearPermissionCache` / `clearInheritFromPublicCache`, both
  present verbatim on `development`; my diff doesn't touch them.

PHPCS: **0 errors**. Psalm: **no errors**.

## Remaining / follow-ups for #439
1. **hydra**: make the gate globs recursive — `unsafe-auth-resolver` (and peers using the idiom) scan 18% of `lib/`.
2. **decidesk**: 21 `x-openregister-seeds` (plural) declarations are inert — migrate to `components.objects`.
3. **scholiq**: 22 now-dropped empty `x-openregister-seed` declarations — remove (harmless, but noisy warnings).
4. **openregister**: 25 `@spec` tags → evaporated change dirs in `PermissionHandler.php` (5056 fleet-wide).
5. **openregister**: gate-52's 2 orphaned cache-clear methods — wire or remove.
