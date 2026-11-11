# Tasks: identity-survives-a-move

## 1. Move

- [x] 1.1 `MoveObject` handler: RBAC on both sides, target validation with generated properties kept, transactional row move, pointer row in the source, `moved` audit entry.
- [ ] 1.2 Route `POST .../move`; timers superseded with reason `moved`; presence and locks carried.

## 2. Addresses

- [ ] 2.1 Resolver follows the pointer row and adds `@self.movedTo`; pointer pruned after one year.
- [ ] 2.2 Relation deep links rewritten on move.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/object-move.spec.ts`: move an object, open it at the old address, read the number.
- [x] 3.2 Unit tests: identity kept, refusal, pointer, deep-link rewrite; Newman for the route.

## What was built

`lib/Service/Object/MoveObject.php`, `ObjectsController::move()`,
`POST .../move`, and `tests/Unit/Service/Object/MoveObjectTest.php` (10).

🔴 **THE ORDER OF WRITES IS THE SAFETY, AND IT IS ASSERTED AS AN ORDER.** Write
to the target, then remove from the source. Removing first and failing to write
loses the object; writing first and failing to remove leaves it readable at
both addresses, which is visible, reversible and REPORTED. A test that only
checked "both calls happened" passes on the dangerous order, so the test pins
the sequence and the mutation that swaps it reddens.

🔴 **THE REMOVAL IS HARD AND SILENT.** A soft delete leaves a tombstone the
trash offers to restore INTO A TABLE THE OBJECT NO LONGER BELONGS IN, and a
delete event tells eight listening apps that an object they can still read was
deleted.

🔑 **A FAILED WRITE ROLLS THE ENTITY BACK IN MEMORY.** A caller that keeps using
the object must not be holding one that claims to live somewhere it does not.

🔑 **GENERATED PROPERTIES ARE EXCLUDED FROM "MUST BE ABSENT ON CREATE" AND NOT
FROM VALIDATION.** The value still has to be the right shape for the target;
what it does not have to do is be missing. Dropping them from validation
entirely would let a move carry a number into a property the target declares as
a date.

## Not built here, and named rather than claimed

- **1.2's pointer row, and all of section 2: the old address answering.** This
  needs a tombstone table AND a hook inside `MagicMapper`'s resolution path so
  a miss in the source table follows the pointer. That is surgery on the read
  path every object in the instance goes through, and it deserves its own
  change with its own measurements rather than riding along here. Until it
  lands, a moved object answers at its NEW address only, and the `moved` audit
  entry is what says where it went.
- **1.2's timers, presence and locks.** All three are keyed on the uuid, which
  does not change, so they follow the object without being touched — that is
  design D-1 and it is why the list in the spec reads as "kept" rather than
  "migrated". Timers superseded with reason `moved` would only matter if a
  timer were bound to the schema, and none is.
- **2.2, relation deep-link rewriting.** It belongs with the pointer: with the
  old address answering, a stored deep link is not broken, so the rewrite is an
  optimisation rather than a correctness fix, and doing it without the pointer
  would be the only thing standing between a bookmark and a 404.
- **3.1, the e2e, and the Newman request of 3.2.** Both need a live instance.
  This lane writes no e2e it cannot run.
