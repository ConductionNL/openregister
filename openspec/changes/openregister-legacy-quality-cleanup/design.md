# Design: OpenRegister Legacy Quality Cleanup

**Change name:** openregister-legacy-quality-cleanup
**Issue:** #26
**Status:** pr-created
**Kind:** tracking

## Summary

Tracking spec for the planned burn-down of quality-gate baselines in the
openregister app. The 2026-05-03 audit identified substantial technical debt
absorbed into baselines (phpcs.xml exclude-patterns, phpmd.baseline.xml,
phpstan-baseline.neon). This change documents the current state, groups work
into 12 directory-cluster PRs, and sets up CI gates to prevent regression.

No production code changes ship in this PR. All burn-down work lands in
follow-up PRs, one per cluster.

## Current baseline state (captured 2026-06-04)

### PHPCS

- 0 errors, 72 warnings in 41 files
- All 72 warnings are `Generic.Files.LineLength.TooLong` (lines > 125 chars)
- No legacy-debt `<exclude-pattern>` block in `phpcs.xml`

| Cluster | Warnings |
|---|---|
| Service | 51 |
| Controller | 4 |
| BackgroundJob | 4 |
| Db | 4 |
| AppInfo | 3 |
| Reference | 3 |
| Middleware | 1 |
| Other | 2 |

### PHPMD

- `phpmd.baseline.xml` has 2 entries (LongVariable in AuditTrail.php and Consumer.php)
- Live violations (not in baseline, no `--baseline-file` in composer script): ~350+
  across Controllers, Services, Db, BackgroundJob
- Composer `phpmd` script does NOT use `--baseline-file`, so phpmd currently fails
  in `check:strict` due to live violations

Primary violation categories (controllers + background jobs sample):
- ElseExpression, CyclomaticComplexity, NPathComplexity
- ShortVariable, LongVariable, UndefinedVariable, UnusedFormalParameter
- ExcessiveMethodLength, TooManyMethods, ExcessiveClassLength

### PHPStan

- `phpstan-baseline.neon`: 1,425 suppressed entries (7,126 lines)
- 5 NEW errors outside baseline (OCA\OpenRegister\Dto\DeletionAnalysis not found)
- Entries by cluster:

| Cluster | Baseline entries |
|---|---|
| Controller | 547 |
| Service | 525 |
| Db | 213 |
| Migration | 100 |
| BackgroundJob | 8 |
| Command | 6 |
| EventListener | 4 |
| Event | 4 |
| Cron | 4 |
| Other | 14 |

Top error categories:
- Method OCA\\* not found (444) — Nextcloud internal API gaps
- Property OCA\\* not found (202)
- Unknown parameter (133)
- Strict comparison issues (56)
- Undefined methods on OCP\\* (37)

## Architecture decisions

### Declarative-vs-imperative decision

This change adds no service classes. It is purely spec/tracking work + CI
configuration. No ADR-031 assessment required.

### No MCP surface

No new user-callable functionality. `No MCP surface — tracking/quality PR`.

## CI state (2026-06-04)

The `composer check:strict` script runs:
`lint` → `phpcs` → `phpmd` → `psalm` → `phpstan` → `test:all`

`phpmd` is run WITHOUT `--baseline-file phpmd.baseline.xml`, so any phpmd
violation causes `check:strict` to fail. This must be fixed (add
`--baseline-file` flag) before the burn-down PRs begin, or those PRs will be
blocked by pre-existing debt.

## Burn-down plan

12-cluster grouping (re-used from 2026-05-03 audit):

| Cluster | PHPCS | PHPStan | PHPMD (sample) |
|---|---|---|---|
| 1. Controllers | 4 | 547 | High |
| 2. Services — core | varies | varies | High |
| 3. Services — object-graph | varies | varies | Medium |
| 4. Db mappers | 4 | 213 | Low |
| 5. Db entities | 0 | 0 | Low |
| 6. Migrations | 0 | 100 | Low |
| 7. Cron / BackgroundJob | 4 | 8 | Low |
| 8. Repair / EventListener | 0 | 4 | Low |
| 9. Settings | 0 | 2 | Low |
| 10. Util / helpers | 51 | varies | Medium |
| 11. Tests | — | — | — |
| 12. Bootstrap / appinfo | 3 | 3 | Low |

Estimated effort: 8–12 PRs over 2–3 sprints.

## Immediate prerequisites (before burn-down PRs begin)

1. Fix `DeletionAnalysis` DTO missing (5 new PHPStan errors outside baseline).
2. Add `--baseline-file phpmd.baseline.xml` to `composer.json` phpmd script
   so phpmd only fails on NEW violations, not pre-existing baseline debt.
3. Confirm CI pipeline runs `composer check:strict` on every PR.

## See also

- `openspec/changes/openregister-legacy-quality-cleanup/proposal.md`
- `openspec/changes/openregister-legacy-quality-cleanup/tasks.md`
- `phpcs.xml`
- `phpmd.baseline.xml`
- `phpmd.xml`
- `phpstan-baseline.neon`
