# Design: run-scoped-object-locking

## D-1 · The lock record shape

This is the decision that is awkward to reverse, because the record is
already written into live `_locked` columns across every magic table on every
instance. It is settled here before any code is written.

### The record

```jsonc
{
  "kind":       "run",              // NEW. "run" | "user". ABSENT means "user".
  "runUuid":    "9f2c…",            // NEW. Present only when kind === "run".
  "user":       "alice",            // unchanged meaning: WHO. For a run lock, its runAs.
  "process":    "review-step",      // unchanged. Now actually persisted (see D-5).
  "created":    "2026-09-05T…",     // unchanged
  "duration":   3600,               // unchanged
  "expiration": "2026-09-05T…"      // unchanged
}
```

### Why this shape and not another

**Why a `kind` discriminator rather than "a `runUuid` key means a run lock".**
A present-key discriminator makes an absent key ambiguous between "user lock"
and "run lock whose writer forgot the key", and the second must be refused
loudly rather than silently downgraded to a user lock that the runAs user can
then walk straight through. With an explicit `kind`, a record that says `run`
and carries no `runUuid` is detectably malformed, and
`isLockedBySomeoneElse()` refuses every caller for it rather than admitting
one by accident. Failing closed on a malformed lock is the only safe
direction: the alternative silently converts a bug into an open door.

**Why `user` keeps its meaning in both cases instead of a nested holder
object.** A nested `{holder: {kind, id}}` would be tidier on a blank sheet
and is the wrong call here. `user` is read today by
`ObjectEntity::getLockedBy()`, by `LockHandler::callerMayUnlock()`, by
`LockHandler::getLockInfo()`'s `locked_by` projection, by
`ObjectsController`'s 423 body, by `RevertHandler`, and by two frontend
stores in opencatalogi and nextcloud-vue that render "locked by X". Moving it
breaks all of them for no gain. Keeping `user` populated on a run lock with
the run's `runAs` means every one of those readers keeps working and shows a
true statement: that identity really is who the run acts as. What changes is
only that `user` stops being the *authority* on ownership.

**Why no migration and no back-fill.** `_locked` is a `Types::JSON` column
(`MagicMapper::getMetadataColumns()`), so adding keys is a write-shape change
with no schema change. Every lock written before this change has no `kind`;
`kind ?? 'user'` reads it as a user lock, which is exactly what it is. There
is no record in existence that this default misclassifies, because no run has
ever been able to take a lock. A back-fill would have nothing to fill.

**Why not a lock table instead of the JSON column.** The lock has to be
readable in the same row read that the write guard already performs. Moving
it to a side table adds a query to every single object write to answer a
question that is `null` in the overwhelming majority of cases. The registry
in D-4 exists for a different question, asked by a cron job rather than by
every write.

### How ownership is compared

One production predicate, on `ObjectEntity`, and every guard calls it:

```php
public function isLockedBySomeoneElse(?string $userId, ?string $runUuid = null): bool
```

| Lock | `kind` | Held against | Admitted |
|---|---|---|---|
| user lock (legacy or new) | absent / `user` | everyone whose uid differs from `user` | that uid only |
| run lock | `run` | **everyone**, including the run's own `runAs` user | that `runUuid` only |
| malformed run lock | `run`, no `runUuid` | everyone | nobody |
| expired lock | any | nobody (`isLocked()` is false) | everyone |

A run lock refusing the run's own `runAs` user is decision 2 stated in code.
If the runAs user were admitted, a lock taken by a run under `alice` would be
no obstacle to alice, and on the many instances where flows run as a single
service account it would be no obstacle to anybody. The lock would be
decorative again, in a new way.

The predicate is what tests assert against. No test restates the table above;
`openregister#3428` is the standing reminder of what a test that reimplements
its subject is worth.

## D-2 · Suspend and retry reuses the heartbeat, and adds nothing

`openregister.lock-object` needs no new waiting mechanism. The engine already
has one, and the user-task node is the worked example.

- **Park**: throw `FlowSuspension(resumeAt: <non-null>, reason: …)`.
  `resumeAt` MUST be non-null. A null `resumeAt` means "waiting on an
  external signal", and `FlowRunMapper::findAbandonedSignals()` fails such a
  run after 14 days. A lock is not waiting on a signal, it is waiting on a
  clock.
- **Remember**: the node's own resume slot,
  `$context[FlowNodeResumeState::CONTEXT_KEY]`. It stores `deadline` (the
  wait budget's absolute expiry, stamped once on the first attempt and never
  restamped) and `attempts`. The slot is per-node, so two lock steps in one
  flow do not read each other's state.
- **Wake**: `FlowRunWorker` calls `FlowRunMapper::findDue()` every cron tick,
  which selects `suspended AND resume_at <= now`. The node runs again,
  retries the acquire, and either proceeds or parks again.
- **Forget**: the dispatcher clears the slot when `execute()` returns
  normally. A node that suspends skips that line. Since openregister#3358,
  `FlowRunService::keepResumeSlots()` preserves slots for every non-terminal
  end, so a pass that legitimately ends `queued` no longer loses the
  deadline and restart the budget from zero.

**Backoff is the node's job.** There is no attempt counter or backoff column
on `FlowRun`; `resume_at` is an absolute wake time. The node computes its own
next `resumeAt` from `attempts` in its slot, clamped to a floor of 60 seconds
because a wake finer than the cron period cannot happen anyway.

**When the budget runs out the node fails, naming the holder.** It does not
proceed, and it does not break the lock. Proceeding would be the data race
the lock exists to prevent; breaking it would let any flow author defeat
every other flow author's lock by waiting.

## D-3 · Three release layers, and what each one is for

| Layer | Covers | Latency |
|---|---|---|
| 1 · `FlowRunTerminalEvent` listener | every terminal outcome, including the reapers' | immediate |
| 2 · sweep in `FlowRunWorker` | a lock whose run row is gone, or terminal without a release | one cron tick |
| 3 · TTL (`expiration`, already exists) | everything else, including a database that lost layers 1 and 2 | the lock's duration |

**Layer 1 hooks one place.** `FlowRunMapper::update()` dispatches
`FlowRunTerminalEvent` whenever the persisted row `isTerminal()`. That is a
predicate, not a status whitelist, so all four terminal statuses fire it, and
so does every reaper (`reapStale`, `expireStaleQueued`,
`expireAbandonedSignals`) because they all terminate through the same
`update()`. The listener is idempotent by construction, which it has to be:
the event can fire more than once for one run.

> **Correction to the brief.** The four terminal statuses are `completed`,
> `stopped`, `failed` and **`dead_letter`**. There is no `cancelled` and no
> `expired` status: a cancellation and a queue-TTL expiry both land on
> `failed` with an explanatory `error`. The tests cover the four that exist.

**The listener must not rethrow.** For the stream-walk path the dispatch
happens inside `FlowRunCommit`'s open transaction, so a throw would unwind
the run's own terminal write. `TaskRunTerminalListener` is the established
shape and this listener copies it: log, never rethrow.

**Layer 2 is why the registry in D-4 exists.** A killed worker does reach
terminal eventually, when `reapStale()` observes `updated` going cold, so
layer 1 covers it after the stale window. What layer 1 cannot cover is a
release that itself failed, or a run row deleted out from under an
outstanding lock. The sweep finds those by asking the registry, not by
looking at objects.

## D-4 · The run-lock registry, and the 3.4-second alternative

`openregister_run_object_locks`, one row per (run, object): `run_uuid`,
`object_uuid`, `register_id`, `schema_id`, `node_id`, `locked_at`,
`expires_at`, unique on `(run_uuid, object_uuid)`.

**Why a table rather than reading the objects.** Locks live in the `_locked`
column of magic tables, one table per register-schema pair.
`MagicMapper::findAcrossAllMagicTables()` carries a measured note that an
instance-wide scan over 2,728 magic tables builds 690 KB of SQL and costs
about 3.4 seconds to *plan*, before a row is read. That is not a thing to put
on a cron tick, and it is not a thing to put in a terminal-event listener
that runs inside the run's own transaction. With the registry, "which locks
does this run hold" is one indexed read, and "which locks are orphaned" is
one `NOT IN` against the runs table, exactly the shape
`FlowClaimMapper::deleteOrphans()` already uses for place claims.

**Why not store the held locks in the run's context instead.** The context
dies with the run row, which is precisely the case layer 2 exists to catch.

**The registry is bookkeeping, never the authority.** The `_locked` column
remains the only thing a write guard consults. A registry row without a
matching `_locked` payload is stale bookkeeping and the sweep deletes it; a
`_locked` payload without a registry row still blocks writes and still
expires on its TTL. Making the registry authoritative would introduce a
second source of truth for the question the guard asks on every write, and
the two would drift.

## D-5 · Two defects fixed on the way past

**The write guard.** `SaveObject::findAndValidateExistingObject()` reads
`$lockData['userId']`; `lock()` writes `user`. `$lockOwner` is always null
and the guard's `!== null` short-circuits. Fixed by calling the D-1
predicate. `ObjectsController`'s update path already compared the right key
via `getLockedBy()` and so was the one live guard; it now delegates to the
predicate too, so there is one comparison rather than two spellings of it.

**The dropped process tag.** `LockHandler::lock()` accepts `$process` and
never passes it to `MagicMapper::lockObjectEntity()`, which hardcodes
`'MagicMapper lock'`. Both signatures now carry it. This is what lets a run
lock say which node took it.

**The post-save auto-unlock.** `ObjectsController` releases any lock after a
successful update, patch or post-patch. That is fine for the user lock it was
written for and wrong for a run lock, so it now releases only a lock the
writer actually holds. Without this a single admin write would silently strip
a run's lock as a side effect of a guard it had just passed.

## D-6 · The `lock()` caller sweep

Every caller of `lockObject()` in the fleet, checked for the shape that
breaks when locks become real: lock under one identity, write under another.

| Caller | Shape | Verdict |
|---|---|---|
| `ObjectsController::lock()` / `unlock()` (openregister) | HTTP, user session | unaffected |
| integriq `EndpointService::processLockingRule()` | locks or unlocks as the request's user; does not write in the same rule | unaffected, and its `process` UUID now survives |
| dossiq `DrcController::lock()` | locks, then `storeLockIdInData()` writes the same object **as the same user** | passes: the holder's own write is admitted. The write is already wrapped in a warn-only `try` |
| dossiq `DrcController::unlock()` | unlocks, then writes | unaffected, the lock is gone before the write |
| buildiq `ApplicationVersionsController::update()` | locks 15s, writes as the same user, releases in `finally` | passes, and **starts working**: the 409 branch it already has was unreachable |
| buildiq `AbstractToolHandler::saveVersionManifest()` | locks 30s, writes as the same user, releases in `finally` | same |
| buildiq `ApplicationCreationService` | advisory (pre-creation) lock, no object | unaffected |

**No caller locks and then writes under a different identity, so nothing
starts refusing.** Two callers start being *protected*: buildiq took its
locks explicitly "to prevent last-writer-wins data loss when two concurrent
MCP agents mutate the same version", and two agents under one account have
never been able to conflict. Both already handle the 409 that they will now
sometimes get.

Nothing in `lib/Repair/`, `lib/Command/`, `lib/Migration/` or any seed takes
an object lock, so no import or repair path is affected.
