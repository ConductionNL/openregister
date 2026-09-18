## 1. Register resolution (fix failure #4)

- [x] 1.1 In `MagicMapper::searchObjectsPaginatedMultiSchema`, build a `schema_id → register` map across all candidate registers (the register whose `getSchemas()` contains the schema id) and pair each schema with its real owning register; remove the `reset($registers)` fallback.
- [x] 1.2 Derive the candidate-register set from the schema→register map when the query carries only a searchable-schema set (no register filter), instead of requiring a register id to load registers.
- [x] 1.3 In `searchObjectsPaginated`, make `$isMultiSchemaSearch` fire on a schema-id array even with no register/register-ids filter, so the schema-only provider call reaches the multi-schema path.
- [x] 1.4 Skip (and log) any schema whose owning register cannot be resolved or whose magic table does not exist, without failing the whole search.

## 2. Bounded, batched fan-out

- [x] 2.1 Add a named batch-size constant (UNION arms per statement) chosen to stay safely under the database statement-size / arm-count limit; document the rationale in the docblock.
- [x] 2.2 In the fan-out helper (`searchAcrossMultipleTables` / `searchAcrossMultipleTablesWithUnion`), split the resolved (register, schema) pairs into batches, run each batch's UNION (per-schema-scoped arms per PR #233), and collect rows with score + a stable tiebreaker (`updated`, then `uuid`).
- [x] 2.3 Merge the per-batch result sets in PHP, sort by relevance/score then the stable tiebreaker, and apply offset/limit pagination across the merged set (per-batch over-fetch up to `offset + limit`).
- [~] 2.4 Include only `searchable = true` schemas whose magic table exists as UNION arms; confirm the per-schema count summation (PR #233) still produces the correct total.

## 3. Provider

- [x] 3.1 Confirm/repoint `lib/Search/ObjectsProvider.php` so the cross-schema search reaches the fixed batched path (passes the searchable-schema set, no register filter required), and remove any reliance on the external Solr `search-index` backend from the unified-search path.

## 4. Tests (PHPUnit, CI-way — php:8.3-cli + OCP stubs, no NC/OR runtime)

- [x] 4.1 Add register-resolution unit tests (mocked register/schema mappers) covering: schema paired with its real owning register, schema-only query reaching the multi-schema path, and skip-on-missing-register/table.
- [x] 4.2 Add batching-boundary unit tests (mocked `IDBConnection`/query builder) covering: pairs split into batches under the limit, cross-batch merge/sort/paginate correctness, and that no single statement exceeds the arm-count/`IN`-list bounds.

## 5. Spec + docs

- [x] 5.1 Add this change to the `## OpenSpec changes` list in `openspec/specs/unified-search-provider/spec.md` and confirm the delta validates with `openspec validate`.
- [x] 5.2 Add a docs note that unified search uses the magic tables only and that Solr/Elasticsearch are deprecated for unified search (the external `search-index` capability is untouched and removed in a separate change).

## Acceptance criteria

- Unified search returns OpenRegister objects, each linked to its real owning register/table, on an instance with 1000+ searchable schemas, with no `54011` (1664-column) or >1000-`IN` errors and no single UNION over all tables.
- Results respect RBAC, tenant isolation (active organisation), the `searchable` flag, and the published predicate — verified by the MODIFIED requirement's scenarios.
- Schemas with a missing/unresolvable register or table are skipped and logged, never fatal.
- Cross-batch results are correctly ordered and paginated without duplicates across pages.
- No new database table, migration, listener, or backfill is introduced; the external Solr `search-index` code is not modified.
- No regressions for opencatalogi and softwarecatalog unified search.

## Quality reminders

- PHP must pass `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan); fix any pre-existing issues touched.
- Run the Hydra mechanical gates (spdx-headers, forbidden-patterns, stub-scan, spec-coverage, etc.) before push.
- Add `@spec openspec/changes/unified-search-index/...` traceability tags to changed methods.
- i18n: any new user-facing strings go through `IL10N::t` with English source keys.
- Use only safe placeholder identifiers (nil UUID `00000000-0000-0000-0000-000000000000`, `<uid>`) in any docs/tests.

## Status, 2026-09-18

Section 2 shipped as `MagicMapper::UNION_ARM_BATCH_SIZE` (50, matching the
chunk `ObjectsProvider` already applies so the two bounds agree instead of
interacting). The former `searchAcrossMultipleTablesWithUnion()` body is now
`runUnionBatch()` and returns raw rows; the method above it chunks the pairs,
over-fetches `offset + limit` per batch, merges, sorts and takes the page. One
batch is still one statement that the database orders and paginates, so the
common path is byte-for-byte what it was. The order keys the SQL uses and the
keys the PHP merge sorts on come from one helper, `buildUnionOrderKeys()`,
because two copies of that mapping would drift and the drift would look like a
ranking bug.

**2.4 is half done, deliberately.** The table-exists half is in place (a pair
whose magic table is absent and whose schema has magic mapping off is not given
an arm). The `searchable = true` half stays in `ObjectsProvider`, where it
already is: `MagicMapper::searchAcrossMultipleTables()` also serves the objects
API, where the caller names the register/schema pairs explicitly. Dropping a
pair there because a schema opted out of the MAGNIFIER would silently answer
about less than the caller asked for, which is the class of bug this change
exists to fix, not one to add.

**Not covered by a unit test:** no test executes the batched SQL. That needs a
database, and the unit suite runs without one. What the tests do pin is the
part that decides the answer in PHP: the order keys, the per-batch over-fetch,
and the cross-batch merge, sort and page (`MagicMapperUnionBatchingTest`), plus
the schema -> owning-register map (`MagicMapperSchemaOwnershipTest`). The map
was section 1's work and had no unit test at all until now; the only existing
one covered `extractSchemaIds()` on an unmerged branch.
