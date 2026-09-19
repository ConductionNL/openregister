# Tasks: duplicate-merge-and-dismissed-pairs

## 1. Per-field merge

- [x] 1.1 `previewMerge()` returns, per property, the two values and the resolver's proposal.
- [x] 1.2 `executeMerge()` accepts a field decision map and refuses one that does not match the approved preview.
- [x] 1.3 A merge whose records carry properties the caller cannot read is refused, naming the properties.

## 2. Dismissed pairs

- [x] 2.1 A `dismissedPair` schema: the two uuids in canonical order, actor, reason, moment, value fingerprint.
- [x] 2.2 The dedup scorer excludes a pair whose fingerprint still matches a dismissal.
- [x] 2.3 A route to dismiss and to undismiss a pair, gated by the declared group.

## 3. Soft uniqueness

- [x] 3.1 `x-openregister-unique-hint` on a property, validated at schema save.
- [x] 3.2 The save path returns the matching record as a warning on create and on update.

## 4. Tests

- [x] 4.1 Unit tests for the decision map, the refusal on an unreadable property, the fingerprint match and the warning on update.
- [x] 4.2 Newman requests for the dismissal routes and the merge preview shape.
- [x] 4.3 Deduplication check (ADR-012) recorded in the PR body: `MergeService`, `SurvivorshipResolver` and the dedup scorer are reused, no second merge path is added.
