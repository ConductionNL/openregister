# Tasks: run-scoped-object-locking

## 1 · The lock record and its predicate

- [ ] 1.1 Add `LOCK_KIND_RUN` / `LOCK_KIND_USER`, `isLockedBySomeoneElse()`,
  `describeLockHolder()` and `getLockedByRun()` to `ObjectEntity`.
  **files**: `lib/Db/ObjectEntity.php`
- [ ] 1.2 Thread `?string $runUuid` through `ObjectEntity::lock()` and
  `unlock()`, and route both through the predicate.
  **files**: `lib/Db/ObjectEntity.php`
- [ ] 1.3 Replace `SaveObjectTest`'s two `['userId' => …]` fixtures, which
  pin a payload shape `lock()` never wrote.
  **files**: `tests/Unit/Service/Object/SaveObjectTest.php`

## 2 · The write guard

- [ ] 2.1 Fix `findAndValidateExistingObject()` to call the predicate and
  name the holder.
  **files**: `lib/Service/Object/SaveObject.php`
- [ ] 2.2 Route the controller's 423 guard and `RevertHandler` through the
  same predicate.
  **files**: `lib/Controller/ObjectsController.php`, `lib/Service/Object/RevertHandler.php`
- [ ] 2.3 Make the post-save auto-unlock release only the writer's own lock.
  **files**: `lib/Controller/ObjectsController.php`

## 3 · Plumbing the run identity and the process tag

- [ ] 3.1 Forward `$process` and `$runUuid` through
  `MagicMapper::lockObjectEntity()` / `unlockObjectEntity()`.
  **files**: `lib/Db/MagicMapper.php`
- [ ] 3.2 Thread them through `LockHandler`, `ObjectService` and the contract.
  **files**: `lib/Service/Object/LockHandler.php`, `lib/Service/ObjectService.php`, `lib/Contract/ObjectServiceInterface.php`
- [ ] 3.3 Make `callerMayUnlock()` run-aware and add an audited `breakLock()`.
  **files**: `lib/Service/Object/LockHandler.php`

## 4 · The run-lock registry

- [ ] 4.1 `RunObjectLock` entity and `RunObjectLockMapper` with
  `findByRun()`, `releaseByRun()`, `deleteOrphans()`.
  **files**: `lib/Db/RunObjectLock.php`, `lib/Db/RunObjectLockMapper.php`
- [ ] 4.2 Migration creating `openregister_run_object_locks`.
  **files**: `lib/Migration/Version1Date20260905120000.php`

## 5 · The nodes

- [ ] 5.1 `LockObjectNode` with suspend-and-retry over the resume slot.
  **files**: `lib/Service/Flow/Nodes/LockObjectNode.php`
- [ ] 5.2 `UnlockObjectNode`.
  **files**: `lib/Service/Flow/Nodes/UnlockObjectNode.php`
- [ ] 5.3 Register both; bump the registration test's node count.
  **files**: `lib/Listener/FlowNodeRegistrationListener.php`, `tests/Unit/Listener/FlowNodeRegistrationListenerTest.php`

## 6 · Release

- [ ] 6.1 `FlowRunLockReleaseListener` on `FlowRunTerminalEvent`, idempotent,
  never rethrowing.
  **files**: `lib/Listener/FlowRunLockReleaseListener.php`, `lib/AppInfo/Application.php`
- [ ] 6.2 Sweep orphaned run locks from `FlowRunWorker`.
  **files**: `lib/BackgroundJob/FlowRunWorker.php`

## 7 · Tests that can fail

- [ ] 7.1 Two runs under one user conflict, proven red against the old
  predicate first.
  **files**: `tests/Unit/Db/ObjectEntityRunLockTest.php`
- [ ] 7.2 Release proven separately for each of the four terminal statuses.
  **files**: `tests/Unit/Listener/FlowRunLockReleaseListenerTest.php`
- [ ] 7.3 Holder gone, sweep releases; live run's lock survives.
  **files**: `tests/Unit/Db/RunObjectLockMapperTest.php`, `tests/Unit/Listener/FlowRunLockReleaseListenerTest.php`
- [ ] 7.4 TTL backstop.
  **files**: `tests/Unit/Db/ObjectEntityRunLockTest.php`
- [ ] 7.5 A human write refused while a run holds the lock; the admin break
  works and is audited.
  **files**: `tests/Unit/Service/Object/SaveObjectTest.php`, `tests/Unit/Service/Object/LockHandlerBreakLockTest.php`
- [ ] 7.6 Node config validation and the park/retry/budget behaviour.
  **files**: `tests/Unit/Service/Flow/LockObjectNodeTest.php`, `tests/Unit/Service/Flow/UnlockObjectNodeTest.php`
