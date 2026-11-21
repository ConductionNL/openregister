# Tasks: query-filter-through-a-reference

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Parse

- [x] 1.1 `lib/Service/Query/ReferenceFilterParser.php`: parse `_ref` blocks (D-1), resolve the referenced schema (D-4), refuse per D-2. Verify: `tests/Unit/Service/Query/ReferenceFilterParserTest.php` for each refusal and a valid block with an operator.

## 2. Apply

- [~] 2.1 `lib/Service/Query/ReferenceFilterApplier.php` and its call in `lib/Db/MagicMapper/MagicSearchHandler.php` beside the `_related` call: `EXISTS` with the referenced schema's RBAC and multitenancy conditions (D-3), single and list references, Postgres and MariaDB. Verify: integration test on both databases in CI.
  - Built for single references (second pass): no separate applier class. `RelatedRowQueryApplier::apply()` takes the queried schema and turns `_ref` blocks (through `ReferenceFilterParser`) into the forward filters `_referenced` uses, so they run through the same `EXISTS` clause under the referenced schema's own access predicate and organisation scope on Postgres and MariaDB. `MagicSearchHandler::applyRelatedRowFilters()` passes the schema; the UNION route refuses `_ref` and `shouldUseUnionQuery()` keeps it on the builder route. Unit tests: `ReferenceFilterApplyTest`, `MagicSearchHandlerReferenceFilterTest`. List references (third pass): a `_ref` block on a property of type `array` joins through the list instead of being refused: `RelatedRowFilter::$throughList` makes `RelatedRowExistsClause` join by JSON containment (`CAST(list AS jsonb) @> jsonb_build_array(uuid)` on Postgres, `JSON_CONTAINS(list, JSON_QUOTE(uuid))` on MariaDB, for a magic-table column and for the objects-table document). Unit tests: `RelatedRowExistsClauseListTest`, `ReferenceFilterApplyTest::testAListReferenceJoinsThroughTheList`. Another register (fourth pass): a reference property naming a `register` (on the property or its `items`, the key the save path reads) reads the referenced schema's table and organisation scope in that register; a register that cannot be found is refused naming it, never swapped for the queried one (`RelatedRowFilter::$register`, `RelatedRowQueryApplier::registerFor()`, `RelatedRowLookups::$registerMapper`). Unit tests: `ReferenceFilterRegisterAndFacetTest`. Open: the integration test on both databases needs CI with real databases.
- [x] 2.2 Facets over `_ref[<property>][<field>]` in the facet builder. Verify: unit test.
  - `_facets[_ref][<property>][<field>]` (object with `type`, a bare `terms`, or a list of fields; `ReferenceFacetConfig`) on the single-schema facet path: `MagicSearchHandler::referenceFacetQuery()` takes the list's filtered query and `RelatedRowQueryApplier::joinReferenceForFacet()` INNER JOINs the referenced row under the same join (equality or through the list), soft-delete exclusion, access predicate and organisation scope as the `_ref` filter, grouping by the referenced field and counting each object once (`COUNT(DISTINCT t._uuid)`). The result sits under `facets._ref.<property>.<field>`. Names are refused as the filter refuses them (400); an unreadable property or field omits the facet; another facet type and `_facets[_ref]` across several schemas are refused. Unit tests: `ReferenceFacetConfigTest`, `MagicFacetHandlerReferenceFacetTest`, `ReferenceFilterRegisterAndFacetTest`, `MagicSearchHandlerReferenceFilterTest`, `FacetResultsTest`.
- [x] 2.3 Solr path: join query where the backend supports it, database fallback otherwise. Verify: unit test of the translation.
  - Nothing to translate: the external Solr/index tier has been removed (`SearchBackendHandler`, `QueryHandler`: "the only search backend"), so every `_ref` filter and facet runs on the database path built in 2.1 and 2.2, which is the fallback this task names. Noted in `docs/features/search-and-faceting.md`.

## 3. Tests and docs

- [ ] 3.1 Newman: list with `_ref`, with an operator, a list reference, a refused field (400), and a caller who may not read the referenced object.
- [x] 3.2 `docs/`: the `_ref` filter beside `_related`, with the direction each takes. Fourth pass: another register, facets through a reference, the search backend.
- [ ] 3.3 `@spec` tags; `openspec validate query-filter-through-a-reference --strict`; set row `api-filter-related` to built once 3.1 passes.
  - `@spec` tags are on every new class and method of this change. Open: the strict validate run, and the matrix row, which waits on 3.1.
