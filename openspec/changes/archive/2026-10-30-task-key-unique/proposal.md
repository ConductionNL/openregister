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
