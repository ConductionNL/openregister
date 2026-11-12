# Tasks

## 1. Flow token

- [x] 1.1 Add `lib/Service/Flow/FlowToken.php` — a mutable run-level value bag
      (`get`/`set`/`has`/`all`/`merge`), `fromArray()` tolerating absent,
      malformed and already-hydrated input, and `jsonSerialize()`.
- [x] 1.2 Rehydrate `context['token']` into a `FlowToken` in
      `FlowRunService::execute()` before the engine runs.
- [x] 1.3 Serialise `context['token']` back to an array in
      `FlowRunService::persistResult()` before `setContext()`, so it is stored
      on every outcome including `suspended`.
- [x] 1.4 Unit tests: read-back within a run; absent token; malformed token;
      already-an-object token; token survives a serialise/rehydrate round trip.

## 2. Execution mode

- [x] 2.1 Add `executionMode` to the `flow` schema in
      `lib/Settings/flow_register.json` (enum `async`/`sync`, default `async`,
      description naming the critical-path trade-off).
- [x] 2.2 Resolve the flow document in `FlowTriggerService::fire()` and branch on
      `executionMode`: `sync` ⇒ `queue()` then `execute()` inline; anything else
      ⇒ `queue()` only.
- [x] 2.3 Keep `fire()`'s catch-all so an inline failure never unwinds the host
      app's save, and leave a suspended sync run for the worker.
- [x] 2.4 Unit tests: absent mode queues; `async` queues; invalid mode queues;
      `sync` executes inline; a throwing `sync` flow does not propagate out of
      `fire()`.

## 3. Sub-flow propagation and return

- [x] 3.1 In `SubFlowNode::execute()`, seed the child context with a *child*
      token carrying the parent's values (not the parent's instance).
- [x] 3.2 On `wait`, merge the completed child run's token back into the
      parent's, child values winning on conflict.
- [x] 3.3 Leave fire-and-forget seeding-only — nothing to merge back.
- [x] 3.4 Unit tests: child reads parent's value; waited child's value reaches
      the parent; conflict resolves to the child; parent's untouched keys
      preserved; fire-and-forget cannot mutate the parent.

## 4. Verification

- [x] 4.1 `openspec validate --strict` passes for this change.
- [x] 4.2 PHPCS clean on every changed file.
- [x] 4.3 PHPUnit green in the `nextcloud:34` container.
- [x] 4.4 Live-verify on 8080: a `sync` flow completes within its trigger; a
      token written before a `Wait` is readable after the worker resumes the run;
      a waited-on sub-flow returns a value to its parent.
- [x] 4.5 Confirm the ten existing nodes and both leaf apps (hermiq,
      openconnector) still register and run — the change must be signature-neutral.
