---
kind: code
---

# Proposal: run-scoped-object-locking

## Summary

Make an object lock mean something, and let a flow run hold one. Two new
nodes, `openregister.lock-object` and `openregister.unlock-object`, take and
release a lock in the name of the **run** rather than the name of the user
the run happens to execute as. A second run that wants the same object parks
and retries on the heartbeat until the lock frees or its wait budget runs
out. A person who tries to write to a locked object is refused with a message
naming the run that holds it. The engine releases every lock a run holds on
any terminal outcome, a sweep collects what a killed worker left behind, and
the existing TTL stays as the last backstop.

## Why

**No lock has ever blocked a write.** `ObjectEntity::lock()` writes the
holder under the key `user`. `SaveObject::findAndValidateExistingObject()`
reads it under the key `userId`, so `$lockOwner` is always `null`, and the
guard's `if ($lockOwner !== null && ...)` never fires. Every lock taken
through `POST /api/objects/{register}/{schema}/{id}/lock` since the endpoint
shipped has been decorative on that path. Measured on
`origin/development@c18e5c286`.

**The unit test agreed with the bug.** `SaveObjectTest::
testFindAndValidateExistingObjectThrowsOnLockedObject` hand-writes
`setLocked(['userId' => 'other-user'])`, a payload shape `lock()` has never
produced, and passes. It is why the defect survived: the test asserted the
guard's logic against the guard's own misreading rather than against a lock
the production writer actually made.

**Two runs under one identity cannot conflict today.** Ownership is compared
on the user alone: `if ($lock['user'] !== $userId) throw`. Two flow runs
executing under the same `runAs` therefore never collide. The second silently
takes the *extend* branch and pushes the first run's expiry out, and either
one can release the other's lock through `unlock()`, which repeats the same
comparison. A run-scoped lock is exactly the case the current shape cannot
express.

**The process tag is dropped on the floor.** `LockHandler::lock()` accepts a
`$process` argument and never forwards it; `MagicMapper::lockObjectEntity()`
hardcodes `'MagicMapper lock'`. integriq's `processLockingRule()` mints a
fresh UUID per lock and passes it in, and it is discarded before it reaches
the payload, so no caller has ever been able to say what it locked *for*.

## What Changes

- **The lock record grows a holder kind.** `kind: 'run'|'user'` and
  `runUuid`, added beside the existing keys. `_locked` is a JSON column, so
  this is additive with no migration and no back-fill: a record written
  before this change has no `kind` and reads as a user lock, which is what it
  is. See design.md D-1.
- **One production predicate owns ownership.**
  `ObjectEntity::isLockedBySomeoneElse(?string $userId, ?string $runUuid)`.
  Every guard calls it. No caller restates the comparison, and no test
  restates it either.
- **The write guard is fixed** in `SaveObject`, in the `ObjectsController`
  update path, and in `RevertHandler`, and the refusal names the holder.
- **Two nodes** in `lib/Service/Flow/Nodes/`, registered through
  `FlowNodeRegistrationListener` like every other built-in. `lock-object`
  parks the run with a non-null `resumeAt` and retries, keeping its attempt
  count and deadline in its own resume slot.
- **A run-lock registry**, `openregister_run_object_locks`, one row per
  (run, object). It is what lets the terminal listener release a run's locks
  without scanning, and what makes the sweep a single indexed query. See
  design.md D-4 for why the alternative costs 3.4 seconds.
- **Three release layers**: a `FlowRunTerminalEvent` listener, a sweep in
  `FlowRunWorker`, and the TTL that already exists.
- **An admin break-lock**, recorded in the audit trail as `lock.broken`.

## Impact

- **Affected specs**: `object-interactions`, `flow-engine`.
- **Affected code**: `lib/Db/ObjectEntity.php`, `lib/Db/MagicMapper.php`,
  `lib/Service/Object/LockHandler.php`, `lib/Service/Object/SaveObject.php`,
  `lib/Service/Object/RevertHandler.php`,
  `lib/Controller/ObjectsController.php`, `lib/Service/ObjectService.php`,
  two new nodes, one new entity, one new mapper, one new migration, one new
  listener, `lib/BackgroundJob/FlowRunWorker.php`.
- **Behaviour that changes for existing callers**: a lock now refuses a
  write. The caller sweep in design.md D-6 found no caller that locks and
  then writes under a different identity, so nothing in the fleet starts
  failing. integriq's `processLockingRule()` gains a working `process` tag.
