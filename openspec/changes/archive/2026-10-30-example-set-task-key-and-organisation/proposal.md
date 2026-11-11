## Why

Some apps read their tasks by engine key. dossiq reads a tenant's onboarding steps as tasks of kind `dossiq.onboarding`, each keyed by its step (`contract`, `go_live`, …), with the tenant as organisation and object. DqTenant draws "5 van 7 stappen klaar".

An example set could not seed those steps. The loader always keys a task `example-set:<app>/<key>` and has no field for the task's organisation, so the steps it wrote were not the ones dossiq looks for.

## What Changes

- A fragment's task MAY declare `taskKey`, the engine key the task is written with. Without it the set names the task as before.
- A fragment's task MAY declare `organisation`.
- The record keeps the task by uuid, so a declared key does not change how a later install finds it.
