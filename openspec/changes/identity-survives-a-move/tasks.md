# Tasks: identity-survives-a-move

## 1. Move

- [x] 1.1 `MoveObject` handler: RBAC on both sides, target validation with generated properties kept, transactional row move, pointer row in the source, `moved` audit entry.
- [x] 1.2 Route `POST .../move`; timers superseded with reason `moved`; presence and locks carried.
      The route is `objects#move`. Presence and locks are keyed on the uuid, so they come along (D-1), and a locked object is refused before the move. Timers are keyed on the uuid too, except for the one thing a schema decides: a timer whose working calendar was named by the record type it was armed on (`WorkingCalendarScope::SCOPE_RECORD_TYPE`). After a completed move `MoveObject` calls `FlowTimerControlInterface::recalendarForSubject()`. An armed timer of that kind is superseded with reason `moved` onto the calendar the target schema names (falling back to the unit or instance calendar when it names none), keeping its anchor, running segment and consumed time, so the deadline is re-projected. A suspended one is pointed at the new calendar in place, since resume measures against it. A failure is logged and does not undo the move. Tests: `FlowTimerRecalendarTest` (7) and `MoveObjectTest` (+3).

## 2. Addresses

- [x] 2.1 Resolver follows the pointer row and adds `@self.movedTo`; pointer pruned after one year. Built without a new table: the `moved` audit entry MoveObject writes names both addresses and when, so it is the pointer. `MovedObjectLookup` reads it on a miss only (no change to the read path every object takes), and `ObjectsController::show()` re-reads the object through show() at its new address, so purpose binding, RBAC and rendering of that address apply, then adds `@self.movedTo`. The one-year window is a filter on the entry's date rather than a prune. Tests: `MovedObjectLookupTest` (6), `ObjectsControllerMovedAddressTest` (2).
- [x] 2.2 Relation deep links rewritten on move. A relation row stores the register and schema of both of its ends, which is what its deep link and graph node are built from; those are the part of the address a move changes. `RelationAddressRewrite` points every row touching the moved uuid at the new address (source end, target end, or both for a self-link), saving only rows that change. `MoveObject` calls it after the write and the removal, so a refused or failed move never touches the rows, and a failed rewrite is logged without undoing the move (the old address still answers). Tests: `RelationAddressRewriteTest` (7), `MoveObjectTest` (+3: rewrite after the move with the target ids, none on a refusal, a failed rewrite still reports the move).

## 3. Tests

- [x] 3.1 `tests/e2e/ci/object-move.spec.ts`: move an object, open it at the old address, read the number.
      2026-11-25: two tests, green on a sandbox Nextcloud 34 with MariaDB (2 passed). An object with a generated number, a create entry and a relation row is moved to a second schema declaring the same number; at the new address it keeps the number, its history holds `create` and `moved`, and the row points at the new schema. At the old address it answers 200 with the number and `@self.movedTo`.
      🔴 THE OLD ADDRESS WAS NOT A MISS, AND THE E2E IS WHAT FOUND IT. `find()` resolves a uuid across every table, so the old URL came back 200 with the object from its new table and no `@self.movedTo`: the bookmark opened, and was never told to update. `MovedObjectLookup` was only asked on a miss, and the unit tests drove a mocked miss. Fixed in `ObjectsController::movedShowOfFound()`: an object whose own register or schema is not the one asked for is checked against the move record and, when it moved from here, read again through show() at its new address, as a miss would be; an object that lives where it was asked for never reaches the lookup. Tests: `ObjectsControllerMovedAddressTest` (+3; removing the call turns two red), and the e2e's second test is red on main and green with the fix.
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
  design D-1. The one exception, a timer whose calendar was named by its
  record type, was built later: see 1.2 above.
- **2.2, relation deep-link rewriting**, was built in the second pass, once
  the old address answered (2.1): see 2.2 above.
- **3.1, the e2e, and the Newman request of 3.2.** Both need a live instance.
  This lane writes no e2e it cannot run.
