---
kind: code
---

## Why

`TaskService::findByKey` lets a caller ask whether a keyed task exists before adding it, but two saves at the same
moment can both see the key free and both add the task. Dossiq's case type rules add a task once per case this way.

## What changes

- A unique index on `openregister_tasks.task_key` (NULL keys stay allowed). The second of two concurrent inserts with
  the same key is refused by the database.
- An instance whose stored tasks already share a key does not get the index: the upgrade warns with the count and
  leaves the table as it was, rather than failing or rewriting keys.
- Nextcloud runs a migration once, so the post-migration repair step `EnsureTaskKeyUniqueIndex` adds the index later:
  on every upgrade and on `occ maintenance:repair` it counts the shared keys again and, once there are none, adds it.
  The warnings tell the operator to give the tasks distinct keys and then run `occ maintenance:repair`. Once the index
  exists an app-config flag stops the step from looking again.
- The repair step builds a plain (not concurrent) unique index, so on PostgreSQL writes to `openregister_tasks` wait
  while it is built; the table is small and the build runs once, during an upgrade or a repair run.
