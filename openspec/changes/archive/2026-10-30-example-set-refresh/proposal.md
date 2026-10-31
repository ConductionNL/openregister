# Proposal: example-set-refresh

## Why

`openregister:example-set:install` keeps an object it wrote in an earlier run, and only re-resolves the fields that quote another object or a lookup. Two consequences:

- A fragment change to an object's values never reaches an instance that already has the object.
- Values that count from the install day go stale: for example, a field inspection's `scheduledAt` stays on the first install day.

EXSET counted 37 stale fields on 26 objects on :8080. Today the only fix is `example-set:remove` followed by an install, and the remove is held back by delete guards (for example, a published case type).

## What changes

- `occ openregister:example-set:install <set> --refresh` rewrites every object the set's record holds to the values its declaration gives today. Dates count from today, and cast, lookups and objects are read as they are now.
- Declared fields go over the stored row. A field the declaration does not name stays as it is.
- Objects the set did not write are never touched, and nothing is deleted, so delete guards are not involved.
- The report counts the objects it rewrote per type ("1 already there (1 refreshed)"), and the proof checks them like newly created objects.
- The saved views, tasks, notes and read states the set declares are written again too. The earlier row is taken away first; if it cannot be, the old row stays and is named under `dropped`. Watchers, parties and flows are not refreshed.
- Without `--refresh`, nothing changes.
- The rewrite lives in `ExampleSetRewriter`; the install path passes the plan's `refresh` flag to it.
