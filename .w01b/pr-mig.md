REVIEW: data integrity (which objects are moved, and where to).

## What changes
`MigrationHandler::migrateObjects()` is split into steps. Its public signature is unchanged and behaviour is the same.

- `loadSides()` loads the source register and schema, then the target register and schema, in the old order.
- `migrateOne()` handles one found object: title, the source check, the property mapping and its counts, the move to the target register and schema, the update, and the detail entry. Any exception is recorded and the next object is tried, as before.
- `assertInSource()` is the ownership check. It still compares the stored register and schema, as integers, with the values as requested (so a source named by slug never matches, as before).
- `MigrationRun` holds the two `MigrationSide`s, the mapping and the report; `MigrationSide` holds one end's requested and loaded register and schema.
- Removed without observable effect: the outer `catch` that appended to a local report and rethrew, and two empty if-blocks around the folder and relations getters.

## Numbers (gate 122 reader)
| Unit | Before (LOC/McCabe/params) | After |
|---|---|---|
| migrateObjects | 133 / 15 / 6 | 31 / 6 / 6 |
| migrateOne | | 38 / 2 / 2 |
| assertInSource | | 18 / 3 / 2 |

Scope `lib/Service/Object*/**` + ObjectService.php, development to this branch:
- size >15/30/60: 78.8/50.9/19.4 to 78.6/50.7/19.0 (5-star 23.6/11.6/4.2)
- McCabe >5/10/25: 56.3/24.9/6.1 to 56.0/24.5/6.1 (5-star 10.1/3.7/0.55)
- params >=3/5/7: 49.0/20.5/9.4 to 48.7/20.2/9.4 (5-star 7.5/1.65/0.45)

## Verification
- Golden test `MigrationHandlerMigrateObjectsGoldenTest` with 19 scenarios, recorded on the old code and re-run in plain mode: mixed outcomes, wrong register, wrong schema, failing update and mapping, ids not found, int and string ids, slugs, every missing register or schema, a failing load and a failing batch load. It records each load, the batch load, each mapping call, the object exactly as handed to `update()`, the log lines and the report.
- Forged stored data: the moved object carries `register`, `schema`, `_owner` and `@self.register` in its data; the recorded update shows the target entity ids decide where it goes.
- Capped mutants: old code 30/30 killed (one survivor, a source schema named by slug, was closed by an added scenario before the final run), new code 34/34 killed (MigrationHandler 23, MigrationRun 9, MigrationSide 2).
- Targeted pcov over the 20 test files that touch MigrationHandler: 607 tests, 0 failures; 2 risky cases inherited in `ObjectServiceDraftWriteTest` (#5281 fixes them).
- Touched files: php -l, phpcs, phpmd (coupling kept at 12), phpstan and psalm exit 0. phpstan over lib: the 3 inherited OrganisationController errors. Gate 119 0 = 0, gate 16 0.
- Not run locally: the full suite and coverage-guard. CI decides.

## Inherited, not fixed
An object found by uuid but also requested by its id as a string (`'12'` for id 12) is migrated and reported "not found" as well, because the remaining-id filter compares strictly. The golden records this.

AI assistance: written with Claude Code (claude-opus-5-5) and reviewed before submission.
