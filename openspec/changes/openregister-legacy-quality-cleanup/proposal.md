# OpenRegister Legacy Quality Cleanup

## Why

The OR-abstraction audit (2026-05-03, stream 3 + the quality-gates
cleanup at session start) flagged that openregister's quality gates
have legacy debt absorbed via baselines and exclude patterns.
OpenRegister is the heaviest app in the portfolio: 87 phpcs.xml
exclude-patterns, a 335-line phpmd.baseline.xml across 142 files, and
a 7,471-line phpstan-baseline.neon. Burning these down keeps PR diffs
honest — gates catch real regressions rather than silently absorbing
already-broken code.

This is a tracking change so the burn-down work can be picked up later
without re-running the audit. It is spec-only; no code changes are
proposed in this change. The actual file-by-file work will land in
follow-up PRs grouped by directory cluster.

## What Changes

- Burn down the 87 phpcs.xml exclude-patterns. The legacy-debt block
  was added during the 2026-05-03 audit to keep the gate green while
  the rest of OR was being normalised. Each file gets proper
  docblocks + named-parameter call audits, then the exclude is
  dropped and the gate stays green.
- Burn down the 335-line phpmd.baseline.xml across 142 files.
  Categories per audit:
  - ElseExpression (147 violations)
  - CyclomaticComplexity (62)
  - NPathComplexity (44)
  - MissingImport (41)
  - ExcessiveMethodLength (24)
  - StaticAccess (19)
  - LongVariable (17)
  - UndefinedVariable (14)
  - UnusedFormalParameter (13)
  - ShortVariable (13)
- Verify phpstan-baseline.neon is auto-managed and currently clean.
  If errors regressed since last regeneration, capture them in a
  fresh baseline before starting cluster work so the gate stays
  representative.
- Wire phpcs/phpmd/phpstan into CI as the unified quality gate so
  future PRs cannot regress, and so cluster removal is blocked on a
  clean run.

## Problem

Baselines exist because the audit produced a mechanical work pile
faster than any single PR could absorb. Blocking every PR while the
335-line phpmd baseline gets cleared would freeze the repo for
weeks; the alternative — silently rolling forward — defeats the
purpose of having gates at all. The agreed compromise is to capture
the debt as a baseline, then burn it down deliberately on a per-
cluster cadence.

Now is the time to clear them because the per-app OR-abstraction
adoption work (Hydra ADR-022) is touching the same files. Any
cluster cleanup that happens in parallel with adoption work
amortises across both efforts: one round of file-edits, two
audit-debts cleared.

## Proposed Solution

File-by-file cleanup phased by directory cluster, following the
12-bucket grouping from `.claude/audit-2026-05-03/03-repo-hygiene.md`:

1. Controllers (`lib/Controller/`)
2. Services — core (`lib/Service/`)
3. Services — object-graph (`lib/Service/ObjectHandlers/`)
4. Db mappers (`lib/Db/`)
5. Db entities (`lib/Db/`)
6. Migrations (`lib/Migration/`)
7. Cron / background jobs (`lib/Cron/`, `lib/BackgroundJob/`)
8. Repair steps (`lib/Repair/`)
9. Settings (`lib/Settings/`)
10. Util / helpers (`lib/Service/`, `lib/Helper/`)
11. Tests (`tests/`)
12. Bootstrap / appinfo (`lib/AppInfo/`, `appinfo/`)

Each phase removes its bucket's exclude-pattern entries from
phpcs.xml and its violations from phpmd.baseline.xml. Gate stays
green between buckets — no bucket merges until that bucket's gate
is clean.

Estimated effort: 8-12 PRs over 2-3 sprints for the full burn-down.

## Out of scope

- Refactoring beyond what the sniff requires
- New features (separate adoption-spec changes own those)
- Test additions (separate test-coverage spec change if needed)
- Phpstan rule-level changes (use existing config; baseline only)

## See also

- `.claude/audit-2026-05-03/03-repo-hygiene.md` (audit research,
  canonical source for bucket groupings and category counts)
- `phpcs.xml` (the legacy-debt baseline section)
- `phpmd.baseline.xml` (the PHPMD baseline file)
- `phpstan-baseline.neon` (the PHPStan baseline file)
- Hydra ADR-022 (apps consume OR abstractions) — quality conventions
- `composer.json` `check:strict` script (the unified gate target)
