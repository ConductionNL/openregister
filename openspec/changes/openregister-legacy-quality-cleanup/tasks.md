# Tasks: OpenRegister Legacy Quality Cleanup

## Phase 1 — Inventory + planning

- [ ] 1.1 Capture baselines: run `composer phpcs`, `composer phpmd`, `composer phpstan` and record current counts (starting points: 87 phpcs.xml legacy-debt `<exclude-pattern>` entries; 335-line phpmd.baseline.xml across 142 files; 7,471-line phpstan-baseline.neon). Group errors by directory cluster (the same 12 buckets used in Phase 2). File per-cluster sub-issues with owner + target sprint. Confirm CI runs `composer check:strict` on every PR before starting burn-down work.

## Phase 2 — PHPCS burn-down (per directory cluster)

For each cluster: fix errors, remove the phpcs.xml `<exclude-pattern>` entries for that bucket, verify gate stays green, ship one PR.

- [ ] 2.1 Buckets 1-4 — Controllers (`lib/Controller/`); Services — core (`lib/Service/`); Services — object-graph (`lib/Service/ObjectHandlers/`); Db mappers (`lib/Db/*Mapper.php`).
- [ ] 2.2 Buckets 5-8 — Db entities (`lib/Db/*.php` excl. mappers); Migrations (`lib/Migration/`); Cron / background jobs (`lib/Cron/`, `lib/BackgroundJob/`); Repair steps (`lib/Repair/`).
- [ ] 2.3 Buckets 9-12 — Settings (`lib/Settings/`); Util / helpers (`lib/Service/`, `lib/Helper/`); Tests (`tests/`); Bootstrap / appinfo (`lib/AppInfo/`, `appinfo/`). Once all 12 buckets are clean, drop the legacy-debt block from `phpcs.xml` entirely.

## Phase 3 — PHPMD burn-down

Work the categories in roughly volume-descending order so the baseline shrinks visibly between PRs. Each sub-bullet is the full sweep across all 142 baselined files for that category.

- [ ] 3.1 Categories — ElseExpression (147) → reshape `if/else` chains to early-return/guard clauses; CyclomaticComplexity (62) → extract methods to flatten branches; NPathComplexity (44) → split branches into named helpers; MissingImport (41) → add `use` statements, remove inline FQCNs.
- [ ] 3.2 Categories — ExcessiveMethodLength (24) → extract helpers; StaticAccess (19) → replace with DI services; LongVariable (17) / ShortVariable (13) → rename within Conduction conventions; UndefinedVariable (14) → initialise on all paths; UnusedFormalParameter (13) → remove, or document via `@SuppressWarnings` when interface-mandated.
- [ ] 3.3 After each category: regenerate `phpmd.baseline.xml` and confirm line count drops. Once baseline reaches 0 lines: delete `phpmd.baseline.xml` and drop `--baseline-file` from `composer.json`'s phpmd script.

## Phase 4 — PHPStan burn-down

- [ ] 4.1 Inventory `phpstan-baseline.neon` by error type AND by file, then group errors by directory cluster (re-use Phase 2's 12 buckets).
- [ ] 4.2 Burn down per-bucket targeting the common patterns: missing return/param-type declarations; mixed types (specify generic/union); strict-comparison nudges (`==` → `===`); possibly-null dereferences (add null guards). Regenerate baseline after each PR.
- [ ] 4.3 Once `phpstan-baseline.neon` reaches 0 lines: delete the file.

## Phase 5 — CI integration

- [ ] 5.1 Verify `composer check:strict` runs in CI on every PR (PHPCS, PHPMD, PHPStan, Psalm). Add a PR-template checkbox: "Burn-down PR? Cite cluster + before/after baseline counts".
- [ ] 5.2 Once all baselines are empty: delete `phpmd.baseline.xml` + `phpstan-baseline.neon`, drop the legacy-debt section from `phpcs.xml`, and drop `--baseline-file` from `composer.json`'s phpmd script.
- [ ] 5.3 Add a smoke-test cron that runs `composer check:strict` weekly on `development` to catch silent baseline regression.

## Phase 6 — Documentation

- [ ] 6.1 Update README quality-gates section to reflect zero-baseline state; note in `app-config.json` that legacy quality cleanup is done; cross-link the 2026-05-03 audit report from `docs/development/quality.md` so the historical context survives; close the burn-down tracking issue once the last baseline line is removed.
