## What changes
The schema-wide validate-and-save run in `ValidationHandler` is split into steps. The public signature of `validateAndSaveObjectsBySchema()` is unchanged and behaviour is the same.

- `ValidationRun` carries the register and schema ids, the save callback, limit and offset, the object counts and the chunk plan (`plan()`, `chunkLabel()`). `ValidationChunk` is one slice and its offset.
- `processValidationChunk(chunk, run)` (was 8 parameters), `processAllChunks(allObjects, run)` (was 7), `applyLimitOffset(allObjects, run)` (was 5) and `loadObjectsForValidation(loaded, schemaId)` (was 3) take two parameters each.
- New helpers: `planChunks()` (chunk size, estimate and the start log) and `bulkSave()` (the callback check and the `saveObjects()` call with RBAC, multitenancy and events off).
- Removed without observable effect: the empty-chunk `break` and the `progressPct = 100` default. The loop bound guarantees a non-empty chunk and a positive count, so neither could run.

## Numbers (gate 122 reader)
| Unit | Before (LOC/McCabe/params) | After |
|---|---|---|
| validateAndSaveObjectsBySchema | 101 / 5 / 5 | 67 / 4 / 5 |
| processValidationChunk | 88 / 5 / 8 | 58 / 2 / 2 |
| processAllChunks | 40 / 3 / 7 | 16 / 2 / 2 |
| applyLimitOffset | 18 / 4 / 5 | 18 / 3 / 2 |
| loadObjectsForValidation | 16 / 3 / 3 | 16 / 3 / 2 |

Scope `lib/Service/Object*/**` + ObjectService.php, development to this branch:
- size >15/30/60: 78.8/50.9/19.4 to 78.7/50.7/19.1 (5-star 23.6/11.6/4.2)
- McCabe >5/10/25: 56.3/24.9/6.1 to 56.4/24.9/6.1 (no unit crosses a band; the share moves because the run sheds lines from low-complexity units)
- params >=3/5/7: 49.0/20.5/9.4 to 48.5/20.0/9.0 (5-star 7.5/1.65/0.45)

## Verification
- Golden test `ValidationHandlerSchemaRunGoldenTest` with 26 scenarios, recorded on the old code and re-run in plain mode: failed schema, register and table loads, limit and offset combinations, statistics variants, a failing bulk save, a callback without `saveObjects()`, an empty callback, a four-chunk run with one failing chunk, and every chunk-size band edge (1,000 to 200,001 objects). Numbers are recorded with their type, so `2` and `2.0` differ; memory figures are left out. Long call logs are kept as their first and last entries plus a hash.
- Capped mutants: old code 41 killed, 4 equivalent or unreachable (the two removed branches, `(object) null`, and `convertChunkToArrays()`'s `is_array` branch, which an array can never reach because `getObject()` is called first); new code 48 killed, 1 equivalent.
- Targeted pcov over the 27 test files that touch ValidationHandler: 877 tests, 0 failures; 2 risky cases inherited in `ObjectServiceDraftWriteTest` (#5281 fixes them).
- Touched files: php -l, phpcs, phpmd, phpstan and psalm exit 0. phpstan over lib: the 3 inherited OrganisationController errors. Gate 119 0 = 0, gate 16 0.
- Not run locally: the full suite and coverage-guard. CI decides.

## Inherited, not fixed
`convertChunkToArrays()` calls `getObject()` before its `is_array()` check, so an array in a chunk fails instead of passing through.

AI assistance: written with Claude Code (claude-opus-5-5) and reviewed before submission.
