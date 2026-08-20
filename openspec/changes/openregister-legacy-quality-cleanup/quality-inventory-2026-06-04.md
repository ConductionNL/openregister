# Quality Baseline Inventory — 2026-06-04

Captured on branch `feature/25/openregister-legacy-quality-cleanup` before burn-down work.

## PHPCS

Gate: `composer phpcs` (exit 0 = clean)

**Status: CLEAN** — 0 errors across all 72 PHP files in `lib/`.

Legacy-debt `<exclude-pattern>` blocks: 0 (already removed before this spec was created).
The phpcs.xml has only the standard excludes: `vendor`, `vendor-bin`, `node_modules`,
`composer-setup.php`, `lib/Resources/template`.

## PHPMD

Gate: `composer phpmd` (`phpmd lib text phpmd.xml --cache`)

Note: the `composer phpmd` script has a `|| echo 'PHPMD not installed, skipping...'`
fallback that exits 0 regardless; phpmd violations do NOT currently block CI.
This should be addressed in task 5.2 once violations are cleared.

**Status: 52 violations remaining after partial burn-down in this PR.**

Before this PR the violations included ElseExpression (147) and MissingImport (41) — all
of those have been fixed. The remaining structural violations:

| Category | Count | Next step |
|---|---|---|
| CyclomaticComplexity | 12 | Extract methods to flatten branches |
| NPathComplexity | 12 | Split branches into named helpers |
| CouplingBetweenObjects | 6 | Extract dependencies via interfaces |
| ExcessiveMethodLength | 6 | Extract helper methods |
| ErrorControlOperator | 4 | Replace `@func()` with try/catch or explicit error check |
| ExcessiveParameterList | 3 | Group params into value objects |
| StaticAccess | 3 | Replace with DI services |
| ExcessiveClassComplexity | 2 | Refactor or split classes |
| TooManyPublicMethods | 1 | Refactor |
| LongVariable | 1 | Rename |
| ExcessiveClassLength | 1 | Refactor or split class |
| ShortVariable | 1 | Rename |

No `phpmd.baseline.xml` exists — violations are live and reported on every run.

## PHPStan

Gate: `composer phpstan` (`vendor/bin/phpstan analyse --memory-limit=1G`)

**Status: 1,371 baseline entries (6,868 lines in phpstan-baseline.neon).**

Distribution by directory cluster:

| Cluster | Entries | Sprint target |
|---|---|---|
| `lib/Controller/` | 504 | Sprint 1 |
| `lib/Service/` (core) | 380 | Sprint 2 |
| `lib/Db/` | 205 | Sprint 3 |
| `lib/Service/Object/` | 140 | Sprint 4 |
| `lib/Service/File/` | 56 | Sprint 4 |
| Other (`lib/Search/`, `lib/Formats/`, etc.) | 49 | Sprint 4 |
| `lib/BackgroundJob/` / `lib/Cron/` | 20 | Sprint 5 |
| `lib/Migration/` | 6 | Sprint 5 |
| `lib/Service/Aggregation/` | 5 | Sprint 5 |
| `lib/AppInfo/` | 3 | Sprint 5 |
| `lib/Settings/` | 3 | Sprint 5 |

Common error types (by inspection):
- Method issues (413): missing return types, wrong param types, unknown method calls
- Property issues (205): undefined/wrong-typed properties
- Call to unknown/undefined (131): missing use, interface drift
- Parameter type mismatch (65): mixed→typed narrowing needed
- Variable issues (40): undefined variables, type narrowing

## CI Integration

**Status: CONFIRMED** — `.forgejo/workflows/pre-merge-check-strict.yaml` exists and
runs `composer check:strict` on every PR targeting `development`, `main`, and `beta`.

The workflow also runs all 19 Hydra gates (diff-scoped per ADR-020).

`composer check:strict` currently runs: `lint phpcs phpmd psalm phpstan test:all`
PHPStan and PHPMD are included. PHPMD exits 0 due to the `|| echo...` fallback;
this masks failures. See task 5.2.

## Burn-down Progress

| Gate | Before PR | After PR | Delta |
|---|---|---|---|
| PHPCS errors | 9 | 0 | -9 ✅ |
| PHPMD violations | 75 | 52 | -23 |
| PHPStan baseline entries | 1,371 | 1,371 | 0 |

## Files Changed in This PR

All fixes are style-only refactors (ternary→if/else, FQN→import, else-removal).
No business logic was changed.

- `lib/Controller/EntityRelationsController.php` — 3 ternary→if/else
- `lib/Controller/FileTextController.php` — 4 ternary→if/else  
- `lib/Controller/SchemasController.php` — 1 else-removal
- `lib/Controller/SourcesController.php` — 1 ShortVariable rename
- `lib/Db/EntityRelationMapper.php` — 4 ternary→if/else, 1 else-removal
- `lib/Db/MagicMapper.php` — 1 else-removal, comment style fix
- `lib/Exception/PdfAnonymisationException.php` — 1 else-removal
- `lib/Formats/ExtendedFieldTypeValidator.php` — 1 else-removal
- `lib/Search/ObjectsProvider.php` — 2 else-removals
- `lib/Service/ActionExecutor.php` — 1 else-removal
- `lib/Service/Aggregation/AggregationRunner.php` — 3 else-removals
- `lib/Service/CalendarLinkService.php` — 2 else-removals
- `lib/Service/EmailLinkService.php` — 1 else-removal
- `lib/Service/File/DocumentProcessingHandler.php` — 3 else-removals + 2 MissingImport fixes
- `lib/Service/File/ManualEntityService.php` — 1 ternary→if/else
- `lib/Service/OasService.php` — 1 else-removal
- `lib/Service/Object/DeleteObject.php` — 1 else-removal
- `lib/Service/Reporting/SpreadsheetReportWriter.php` — 1 else-removal
- `lib/Service/UserService.php` — 1 else-removal
- `lib/Service/WebhookService.php` — 10 MissingImport fixes
