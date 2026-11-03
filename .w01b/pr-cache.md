REVIEW: visibility (which caller gets an object's or organisation's name).

## What changes
`CacheHandler::getSingleObjectName()` is split into three steps. Behaviour is unchanged.

- `cachedSingleName()`: for a UUID only, the memory cache and then the distributed cache. A hit is final: the name goes through `visibleNames()`, and a hidden hit answers null without a database fallback, as before.
- `organisationSingleName()`: the organisation lookup, which takes priority. Any exception still means "no such organisation".
- `objectSingleName()`: the unscoped `findAcrossAllSources()` lookup (`_rbac: false`, `_multitenancy: false`), the source check, caching under the identifier and the UUID, and the read-path answer via `visibleNames()`.
- `beginNameScope()` still runs first on every call. The helpers take one parameter each; the method-level complexity suppressions are gone.

## Numbers (gate 122 reader)
| Unit | Before (LOC/McCabe/params) | After |
|---|---|---|
| getSingleObjectName | 83 / 16 / 1 | 28 / 6 / 1 |
| cachedSingleName | | 30 / 7 / 1 |
| organisationSingleName | | 19 / 4 / 1 |
| objectSingleName | | 26 / 6 / 1 |

Scope `lib/Service/Object*/**` + ObjectService.php, development to this branch:
- size >15/30/60: 78.8/50.9/19.4 to 78.7/50.5/18.8 (5-star 23.6/11.6/4.2)
- McCabe >5/10/25: 56.3/24.9/6.1 to 56.3/24.2/6.0 (5-star 10.1/3.7/0.55)
- params >=3/5/7: 49.0/20.5/9.4 to 48.8/20.3/9.2 (share effect only; no parameter list changed)

## Verification
- Golden test `CacheHandlerSingleNameGoldenTest` with 33 scenarios, recorded on the old code and re-run in plain mode: memory and distributed hits (visible and hidden), a bad envelope, a failing and a missing distributed cache, organisations in and out of scope, objects by uuid, id, numeric string and slug, sourceless and nameless objects, failing lookups and read path, and second calls on the same handler.
- Acting user: the session user is pinned through `IUserSession`; the read path (`filterReadableUuids`) answers only for the session user it is asked under and records it, and the organisation scope is the session user's own. "Another session user", "organisation as another user", "second call as another user" and "organisation, then as another user" show nothing carries over between callers (the last one kills a mutant that drops `beginNameScope()`).
- Capped mutants: old code 26/26 killed (two survivors on the first run, the scope reset and the memory write after a distributed hit, were closed by two added scenarios), new code 28/28 killed.
- Targeted pcov over the 109 test files that touch CacheHandler: 3,107 tests, 0 failures. 11 risky cases are inherited, in `RenderObject*WriteOnly*` and `ObjectsControllerWriteOnlyListLeakTest` (`ExplicitReveal` and `RenderContext` missing from `@uses`); this PR adds no classes.
- Touched file: php -l, phpcs, phpmd, phpstan and psalm exit 0. Gate 119 0 = 0, gate 16 0.
- Not run locally: the full suite and coverage-guard. CI decides.

The method still has no caller in `lib/`, as its docblock says.

AI assistance: written with Claude Code (claude-opus-5-5) and reviewed before submission.
