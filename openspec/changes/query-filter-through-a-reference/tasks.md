# Tasks: query-filter-through-a-reference

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Parse

- [x] 1.1 `lib/Service/Query/ReferenceFilterParser.php`: parse `_ref` blocks (D-1), resolve the referenced schema (D-4), refuse per D-2. Verify: `tests/Unit/Service/Query/ReferenceFilterParserTest.php` for each refusal and a valid block with an operator.

## 2. Apply

- [~] 2.1 `lib/Service/Query/ReferenceFilterApplier.php` and its call in `lib/Db/MagicMapper/MagicSearchHandler.php` beside the `_related` call: `EXISTS` with the referenced schema's RBAC and multitenancy conditions (D-3), single and list references, Postgres and MariaDB. Verify: integration test on both databases in CI.
  - Built for single references (second pass): no separate applier class. `RelatedRowQueryApplier::apply()` takes the queried schema and turns `_ref` blocks (through `ReferenceFilterParser`) into the forward filters `_referenced` uses, so they run through the same `EXISTS` clause under the referenced schema's own access predicate and organisation scope on Postgres and MariaDB. `MagicSearchHandler::applyRelatedRowFilters()` passes the schema; the UNION route refuses `_ref` and `shouldUseUnionQuery()` keeps it on the builder route. Unit tests: `ReferenceFilterApplyTest`, `MagicSearchHandlerReferenceFilterTest`. Open: a property holding a list of references is refused with 400 until the forward clause joins through the list (JSON containment); a referenced schema in another register reads its table in the queried register (no rows, never extra rows); the integration test on both databases needs CI.
- [ ] 2.2 Facets over `_ref[<property>][<field>]` in the facet builder. Verify: unit test.
- [ ] 2.3 Solr path: join query where the backend supports it, database fallback otherwise. Verify: unit test of the translation.

## 3. Tests and docs

- [ ] 3.1 Newman: list with `_ref`, with an operator, a list reference, a refused field (400), and a caller who may not read the referenced object.
- [x] 3.2 `docs/`: the `_ref` filter beside `_related`, with the direction each takes.
- [ ] 3.3 `@spec` tags; `openspec validate query-filter-through-a-reference --strict`; set row `api-filter-related` to built once 3.1 passes.
