# Tasks: access-follows-membership

## 1. The membership set

- [ ] 1.1 `MemberOfResolver::valuesFor(mixed $operand, ?string $userId): ?array` per design D-2 to D-6. Verify: `tests/Unit/Service/Rbac/MemberOfResolverTest.php` covers the caller's values, another user's rows ignored even when the search returns them, the `where` role filter, the `current` window (future, past, open-ended), array-valued `property`, an anonymous caller, each malformed operand, a schema that cannot be found, and that a second call with the same caller and operand does not read again while another caller does.

## 2. The operator

- [ ] 2.1 `ConditionMatcher::resolveDynamicValue()` turns `$memberOf` into `$in`, intersects with a sibling `$in`, and resolves the whole property to `null` when the operand is unresolvable. Verify: `tests/Unit/Service/ConditionMatcherMemberOfTest.php` for the single-object verdict: a member's administration is admitted, another administration is refused, an unresolvable operand refuses.
- [ ] 2.2 List query proof. Verify: `tests/Unit/Db/MagicMapper/MagicRbacMemberOfListTest.php` runs the raw-SQL predicate a list builds (`MagicSearchHandler::buildWhereConditionsSql()` over the real `MagicRbacHandler` and `ConditionMatcher`) against an SQLite table holding rows of three administrations: the caller sees only their two administrations' rows, `COUNT(*)` equals that number, the pages under `LIMIT`/`OFFSET` add up to it, and a caller with no membership gets zero. The QueryBuilder path (`applyRbacFilters()`) is asserted to emit an `in` over the same set.

## 3. Docs

- [ ] 3.1 Document `$memberOf` beside the other match operators in `docs/`.

Acceptance:
- `composer check:strict` green on the last branch.
- The single-object verdict and the list agree for the same caller and rows (2.1 and 2.2 use the same fixtures).
