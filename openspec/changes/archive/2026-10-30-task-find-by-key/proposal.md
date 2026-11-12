---
kind: code
---

## Why

A task's `key` is an external reference, and nothing could ask for the tasks carrying one. An app that adds a task once
per thing (dossiq: a case type rule adds "Welstandsadvies vragen" once per case) has to ask whether the key is taken
before adding the task again.

## What changes

- `TaskMapper::findByKey(key)` and `TaskService::findByKey(key)`: every task with that key, terminal ones included.

## Not in scope

- A unique index on `task_key`. Existing keys are not unique by contract, so arbitration stays with the caller; two
  simultaneous adds can still both see the key free.
