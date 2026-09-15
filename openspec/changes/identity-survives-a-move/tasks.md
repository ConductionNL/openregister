# Tasks: identity-survives-a-move

## 1. Move

- [ ] 1.1 `MoveObject` handler: RBAC on both sides, target validation with generated properties kept, transactional row move, pointer row in the source, `moved` audit entry.
- [ ] 1.2 Route `POST .../move`; timers superseded with reason `moved`; presence and locks carried.

## 2. Addresses

- [ ] 2.1 Resolver follows the pointer row and adds `@self.movedTo`; pointer pruned after one year.
- [ ] 2.2 Relation deep links rewritten on move.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/object-move.spec.ts`: move an object, open it at the old address, read the number.
- [ ] 3.2 Unit tests: identity kept, refusal, pointer, deep-link rewrite; Newman for the route.
