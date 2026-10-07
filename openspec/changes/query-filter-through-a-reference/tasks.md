# Tasks: query-filter-through-a-reference

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 7. -->

## 1. Parse

- [ ] 1.1 `lib/Service/Query/ReferenceFilterParser.php`: parse `_ref` blocks (D-1), resolve the referenced schema (D-4), refuse per D-2. Verify: `tests/Unit/Service/Query/ReferenceFilterParserTest.php` for each refusal and a valid block with an operator.

## 2. Apply

- [ ] 2.1 `lib/Service/Query/ReferenceFilterApplier.php` and its call in `lib/Db/MagicMapper/MagicSearchHandler.php` beside the `_related` call: `EXISTS` with the referenced schema's RBAC and multitenancy conditions (D-3), single and list references, Postgres and MariaDB. Verify: integration test on both databases in CI.
- [ ] 2.2 Facets over `_ref[<property>][<field>]` in the facet builder. Verify: unit test.
- [ ] 2.3 Solr path: join query where the backend supports it, database fallback otherwise. Verify: unit test of the translation.

## 3. Tests and docs

- [ ] 3.1 Newman: list with `_ref`, with an operator, a list reference, a refused field (400), and a caller who may not read the referenced object.
- [ ] 3.2 `docs/`: the `_ref` filter beside `_related`, with the direction each takes.
- [ ] 3.3 `@spec` tags; `openspec validate query-filter-through-a-reference --strict`; set row `api-filter-related` to built once 3.1 passes.
