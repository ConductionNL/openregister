Test only. Pins the scope flags that a non-admin object listing runs with. Nothing in `lib/` changes.

`ObjectService::searchObjectsPaginated()` and the magic-mapper searches trust the `_rbac` and `_multitenancy` they receive. What stops a caller from sending them as `false` is `ObjectsController` deriving them from admin status: `buildListingScope()`, the overwrite in `indexFromMagicTable()`, and the overwrite in `crossTableSearch()`. No controller-level test pinned that before.

## What the test does
`ObjectsControllerForgedScopeFlagsTest` sends `GET /objects/zaken/persoon` as a non-admin, with `_rbac`, `_multitenancy`, `rbac`, `multi` and `_multi` set to `false` through both `getParam()` and `getParams()`. The query that `buildSearchQuery()` returns carries the forged keys too. It covers the three routes of `index()`:
- ObjectService route: `searchObjectsPaginated()` receives `_rbac: true, _multitenancy: true`;
- magic-table route: the query `searchObjectsInRegisterSchemaTable()` receives has `_rbac` and `_multitenancy` true, and `_multitenancy_explicit` false;
- cross-table route (`schemas=persoon,besluit`): the query `searchAcrossMultipleTables()` receives has both true.

An admin gets both flags off on all three routes, so the test also proves it tells the two apart. The group double answers only for the session user.

## Verification
- Passes on development: 4 tests.
- Capped mutants on `ObjectsController`: 14/14 killed. That is a `getParam()` form and a `getParams()` form of each flag at each of the three sites (12), plus dropping either overwrite (2). A no-op control mutant survives.
- pcov: 4 tests, 0 risky.

This PR was prepared with AI assistance (Claude Code) and reviewed by the lane before opening.
