## 1. Copies of a withheld property

- [x] 1.1 `RenderObject::withholdEntityCopies()` / `withholdRowCopies()`: drop the relations keys and blank the `@self` metadata copies of every property the read filter removed; call them on `renderEntity()` and both branches of `redactWriteOnlyFromRows()`.

## 2. Facets

- [x] 2.1 `MagicFacetHandler::getSimpleFacets()`: an explicit object-field facet is skipped when `callerMayFacet()` refuses.
- [x] 2.2 `getSimpleFacetsUnion()`: skipped when any table's schema refuses (`callerMayFacetOnEveryTable()`).

## 3. Verification

- [x] 3.1 `RenderObjectPropertyReadCopiesTest`: real RenderObject and PropertyRbacHandler; an anonymous render (single, entity rows, array rows) carries no withheld value anywhere in the JSON; a signed-in render keeps them. 3 of 4 fail on the old code.
- [x] 3.2 `ExplicitFacetsObeyThePropertyReadRuleTest`: real PropertyRbacHandler; an explicit facet on a withheld property never reaches the database, single-table and union. Both fail on the old code.
- [x] 3.3 Object, Db and Controller unit suites: no new failures (the 8 + 8 errors in Db and Controller are identical on origin/development).
- [x] 3.4 Live on :8096: lane oc-pub's anonymous calls re-run (see the PR).
