# Tasks

## 1. Remove the layer

- [x] 1.1 Adapters (`N8nAdapter`, `WindmillAdapter`), `WorkflowEngineInterface`,
      `WorkflowResult`, `WorkflowEngineRegistry`.
- [x] 1.2 Engines table, entity, mapper, controller and the n8n settings screen.
- [x] 1.3 Actions and hooks: executors, listeners, retry and schedule jobs.
- [x] 1.4 Deployed and scheduled workflows, entities, mappers, controllers.
- [x] 1.5 The workflow deploy/export paths in the configuration handlers.
- [x] 1.6 Routes (20), background-job registrations (3), DI and listener wiring.
- [x] 1.7 Frontend: the n8n settings section, the hook UI, the execution and
      schedule panels.

## 2. Keep what is ours

- [x] 2.1 `RunFlowOperation` and `RegisterObjectEntity` stay. They live in
      `lib/WorkflowEngine/` but integrate OUR engine into NEXTCLOUD's native
      workflow engine. The directory name is the trap.
- [x] 2.2 `SchemaWorkflowTab` is stripped, not deleted: `TaskSequencePanel` is
      our approval flow's UI and would have gone with the tab.

## 3. Storage

- [x] 3.1 A migration drops the six tables, idempotently.
- [x] 3.2 It REPORTS each scheduled workflow first. A schedule is a statement of
      intent, and dropping it without printing it loses that with no trace.
- [x] 3.3 Built through the query builder. The first version used backticks,
      which is MySQL syntax: on Postgres it threw, the catch swallowed it, and
      the report never ran. Verified on Postgres both ways.

## 4. Verified

- [x] 4.1 Full suite: 18,797 tests, 0 errors.
- [x] 4.2 Full-tree phpstan clean; 11 now-dead baseline entries removed.
- [x] 4.3 The migration drops all six tables on a live instance, and reports the
      schedules it destroys.
- [x] 4.4 No reference to any removed symbol remains in `lib/`, `src/`,
      `tests/` or `appinfo/`.

## 5. Not done here

- [ ] 5.1 The `n8n-nextcloud` ExApp is a separate repo and keeps working as a
      way to RUN n8n. OpenRegister simply no longer drives it.
