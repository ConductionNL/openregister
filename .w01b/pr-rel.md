REVIEW: RBAC filter (access control on the `/uses` and `/used` relation lists).

## What changes
`RelationHandler::getUses()` and `getUsedBy()` are split into small steps. Behaviour is unchanged.

- `getUses`: load the pinned register and schema, collect the related uuids (without the object's own), locate them in one UNION query, read each table into a `RelatedObjectSet`, fall back to located rows, filter by RBAC, page, and label each row.
- `getUsedBy`: same pinned load, then each table is read into a `ReferrerPage` that tracks the limit, the offset and how many referrers earlier tables returned.
- Shared by both: `pinnedTable()` (register and schema without RBAC, first failure kept for the caller to log) and `emptyPage()`.
- `withRelation()` takes a `RelationLink` (schema, path, direction) instead of three loose values.
- New value objects: `PinnedTable`, `RelatedObjectSet`, `RelationSource`, `RelationLink`, `ReferrerPage`. Every constructor and helper takes at most 2 parameters.
- `filterByRbac()` is untouched.

## Numbers (gate 122 reader)
| Unit | Before (LOC/McCabe/params) | After |
|---|---|---|
| getUses | 162 / 32 / 6 | 60 / 6 / 6 |
| getUsedBy | 108 / 13 / 6 | 50 / 7 / 6 |
| withRelation | 15 / 5 / 4 | 15 / 5 / 2 |
| largest new helper | | readReferrerTable 44 / 5 / 2 |

Scope `lib/Service/Object*/**` + ObjectService.php, development to this branch:
- size >15/30/60: 78.8/50.9/19.4 to 78.5/50.5/18.6 (5-star 23.6/11.6/4.2)
- McCabe >5/10/25: 56.3/24.9/6.1 to 55.9/24.0/5.5 (5-star 10.1/3.7/0.55)
- params >=3/5/7: 49.0/20.5/9.4 to 48.4/20.0/9.4 (5-star 7.5/1.65/0.45)

## Verification
- Golden test `RelationHandlerLookupsGoldenTest` with 49 scenarios (32 for getUses, 16 for getUsedBy, one with both on one handler), recorded on the old code and re-run in plain mode. It records every mapper, RBAC and resolver call with its arguments, every log line (without file and line), and the result.
- Acting user: the RBAC double answers `isAdmin`, `getCurrentUserId` and `hasPermission` only for the session user. Scenarios cover alice, bob, anonymous and an administrator. Forged `userId`, `_owner`, `owner`, `requestedBy`, `_user` and `@self.owner` travel in the query, and forged `_owner`/`_organisation` in the stored object data; the recorded `hasPermission` arguments show the stored owner and organisation win.
- Capped mutants (prlimit 1.5 GB, timeout 120 s): old code 41/41 killed, new code 52/52 killed (RelationHandler 39, RelatedObjectSet 5, ReferrerPage 6, RelationLink 2).
- `RelationHandlerLabelsTest` reflects into `withRelation()`; its calls now pass a `RelationLink`.
- Targeted pcov over the 27 test files that touch RelationHandler: 484 tests, 0 failures. 2 risky cases are inherited, in `ObjectServiceDraftWriteTest` (`ExplicitReveal`/`SystemOperationContext` missing from `@uses`; #5281 adds them). `RelationHandlerTest` got `@uses` for the new classes.
- Touched files: php -l, phpcs, phpmd, phpstan and psalm exit 0. phpstan over lib: the 3 inherited OrganisationController return.type errors. Gate 119 0 = 0, gate 16 0.
- Not run locally: the full suite and coverage-guard. CI decides.

## Inherited, not fixed
`getUsedBy()` applies no RBAC filter of its own; it relies on the per-table query. The `$_rbac` and `$_multitenancy` flags are unused there, as before.

AI assistance: written with Claude Code (claude-opus-5-5) and reviewed before submission.
