REVIEW: RBAC. Splits `ObjectService::searchObjectsPaginated` (83 LOC, McCabe 26, 7 params) into its steps. The public signature and the behaviour stay the same.

## What changed
- `searchObjectsPaginated()` goes from 83/26/7 to 32/5/7 (LOC/McCabe/params).
- New private steps, each at most 21 LOC, McCabe 7 and 2 params:
  - `scopeSearchQuery()`: context scoping, views, and dropping `_source`
  - `scopeQueryToContext()` and `isMultiSchemaSearch()`: the `_register` / `_schema` defaults and the cross-schema test
  - `searchMultitenancy()`: the public-read rule that switches multitenancy off
  - `extendSearchResult()` and `attachResultNames()`: the `_extend` objects and the `_names` map
- The method-level CyclomaticComplexity and NPathComplexity suppressions are removed; phpmd passes without them.

## Why REVIEW
This method decides the `_rbac` and `_multitenancy` flags the database search runs with, and runs the object-source read check before an external provider is asked. The golden pins:
- the database search gets the caller's `_rbac`, and multitenancy is switched off only when the current schema's `read` rule lists `public` (strict match: `true` and `0` in the list do not count, and a `public` update rule does not count);
- forged `_rbac`, `_multitenancy`, `userId`, `_owner`, `owner` and `@self.owner` keys in the query do not change either flag;
- the object-source read check runs for the session user: the permission double grants only to the session user "alice", and a forged `userId: alice` with session user "mallory" is denied. With no session user it is denied too.

## Verification
- Golden test `ObjectServiceSearchPaginatedGoldenTest`: 53 scenarios, covering register/schema scoping (explicit null, caller values, `_schemas`, `@self.schema` arrays, `@self.schemas`), views, `_source`, the public-read rule, flag pass-through, the source annotation, every `_extend` form, `_names` (lookup failure, empty and non-array results, strict match), the search-trail modes, and the object-source path. Recorded on the old code and re-run in plain mode.
- Capped mutants: old code 29 killed, new code 33 killed. On both, one equivalent survivor: skipping the names lookup for an empty result, where the lookup returns `[]` without calling the cache. A no-op control mutant survives on both.
- Targeted pcov over the 20 test files that build a real ObjectService: 335 tests, 0 risky, exit 0.
- `coverage-guard --deletion-neutral` against development: 77.88% to 83.52%, OK.
- phpcs, phpmd, phpstan and psalm on the touched file exit 0 (phpcs: 7 inherited @spec warnings, as on development).
- Gate 16 (diff): 0. Gate 119: 0 = development.
- The full suite is left to CI.

## SIG numbers (gate 122 reader, lib/Service/Object* + ObjectService.php, against development)
- size >15/30/60: 78.0/49.2/16.5 to 77.9/49.0/16.2
- McCabe >5/10/25: 54.9/22.3/4.9 to 54.7/22.0/4.7
- params >=3/5/7: 46.9/18.9/8.8 to 46.7/18.7/8.6

This PR was prepared with AI assistance (Claude Code) and reviewed by the lane before opening.
