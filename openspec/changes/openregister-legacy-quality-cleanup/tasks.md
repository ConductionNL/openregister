# Tasks: OpenRegister Legacy Quality Cleanup

## Phase 1 — Inventory + planning

- [ ] Run `composer phpcs` and capture current baseline error count
      (target line: starting from the 87 exclude-patterns in
      phpcs.xml's legacy-debt block)
- [ ] Run `composer phpmd` and capture current violation count
      (target line: starting from 335-line phpmd.baseline.xml across
      142 files)
- [ ] Run `composer phpstan` and capture current error count
      (target line: starting from 7,471-line phpstan-baseline.neon)
- [ ] Group errors by directory cluster (12 buckets per audit)
- [ ] Create per-cluster sub-issues with owner assignments and
      target sprint
- [ ] Confirm CI runs `composer check:strict` on every PR before
      starting burn-down work

## Phase 2 — PHPCS burn-down (per directory cluster)

For each cluster: fix errors, remove the phpcs.xml `<exclude-pattern>`
entries for that bucket, verify gate stays green, ship one PR.

- [ ] Bucket 1: Controllers (`lib/Controller/`)
- [ ] Bucket 2: Services — core (`lib/Service/`)
- [ ] Bucket 3: Services — object-graph (`lib/Service/ObjectHandlers/`)
- [ ] Bucket 4: Db mappers (`lib/Db/*Mapper.php`)
- [ ] Bucket 5: Db entities (`lib/Db/*.php` excl. mappers)
- [ ] Bucket 6: Migrations (`lib/Migration/`)
- [ ] Bucket 7: Cron / background jobs (`lib/Cron/`,
      `lib/BackgroundJob/`)
- [ ] Bucket 8: Repair steps (`lib/Repair/`)
- [ ] Bucket 9: Settings (`lib/Settings/`)
- [ ] Bucket 10: Util / helpers (`lib/Service/`, `lib/Helper/`)
- [ ] Bucket 11: Tests (`tests/`)
- [ ] Bucket 12: Bootstrap / appinfo (`lib/AppInfo/`, `appinfo/`)
- [ ] Once all 12 buckets are clean, drop the legacy-debt block from
      phpcs.xml entirely

## Phase 3 — PHPMD burn-down

Work the categories in roughly volume-descending order so the
baseline shrinks visibly between PRs. Each task is the full sweep
across all 142 baselined files for that category.

- [ ] ElseExpression (147 violations) — re-shape `if/else` chains
      to early-return / guard clauses
- [ ] CyclomaticComplexity (62) — extract methods to flatten
      branches
- [ ] NPathComplexity (44) — split branches into named helpers
- [ ] MissingImport (41) — add `use` statements; remove inline
      FQCNs
- [ ] ExcessiveMethodLength (24) — extract helpers; pull internals
      into private methods
- [ ] StaticAccess (19) — replace static calls with DI services
- [ ] LongVariable (17) — rename variables to <=20 chars
- [ ] UndefinedVariable (14) — initialise variables on all paths;
      fix typos
- [ ] UnusedFormalParameter (13) — remove dead params; if
      interface-mandated, document with `@SuppressWarnings`
- [ ] ShortVariable (13) — rename single-char variables
- [ ] After each category: regenerate phpmd.baseline.xml and confirm
      line count drops
- [ ] Once baseline reaches 0 lines: delete phpmd.baseline.xml and
      drop `--baseline-file` from composer.json's phpmd script

## Phase 4 — PHPStan burn-down

- [ ] Inventory phpstan-baseline.neon by error type and file
- [ ] Group errors by directory cluster (re-use Phase 2's 12 buckets)
- [ ] Burn down per-bucket; regenerate baseline after each PR
- [ ] Per-bucket common patterns to fix:
  - [ ] Missing return-type / param-type declarations
  - [ ] Mixed types (specify generic / union)
  - [ ] Strict-comparison nudges (`==` to `===`)
  - [ ] Possibly-null dereferences (add null guards)
- [ ] Once baseline reaches 0 lines: delete phpstan-baseline.neon

## Phase 5 — CI integration

- [ ] Verify `composer check:strict` runs in CI on every PR (PHPCS,
      PHPMD, PHPStan, Psalm)
- [ ] Add PR template checkbox: "Burn-down PR? Cite cluster + before/
      after baseline counts"
- [ ] Once all baselines are empty:
  - [ ] Delete `phpmd.baseline.xml`
  - [ ] Delete `phpstan-baseline.neon`
  - [ ] Drop the legacy-debt section from `phpcs.xml`
  - [ ] Drop `--baseline-file` from composer.json's phpmd script
- [ ] Add a smoke-test cron that runs `composer check:strict` weekly
      on `development` to catch silent baseline regression

## Phase 6 — Documentation

- [ ] Update README quality-gates section to reflect zero-baseline
      state
- [ ] Note in `app-config.json` that legacy quality cleanup is done
- [ ] Cross-link the 2026-05-03 audit report from
      `docs/development/quality.md` so the historical context survives
- [ ] Close the burn-down tracking issue once the last baseline
      line is removed
