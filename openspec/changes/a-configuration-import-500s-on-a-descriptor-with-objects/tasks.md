# Tasks

## 1. The fix

- [x] 1.1 `idsOf()` maps an entity through `getId()`, passes a bare id through,
      and drops anything else.
- [x] 1.2 `import()` catches `Throwable`, so a bug answers in JSON rather than
      as an HTML 500.

## 2. Verification

- [x] 2.1 Unit tests for entities, bare ids, a MIXED list (the shape that
      distinguishes the fix — entities-only and ids-only both passed before it),
      unusable entries, and a non-array.
- [x] 2.2 A test that the catch is `Throwable`, read off the source, because the
      difference is invisible to a test that only exercises the happy path.
- [x] 2.3 Live: the descriptor that returned 500 returns 200 and links its
      register.

## 3. Not in this change

- [ ] 3.1 `ImportHandler`'s inconsistent return shape (two append sites push
      entities, two push ids). Normalising it there is the better fix; other
      callers read the same array and would have to move with it.
