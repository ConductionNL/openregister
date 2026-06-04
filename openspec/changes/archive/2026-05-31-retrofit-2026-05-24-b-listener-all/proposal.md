# Retrofit — listener bundle (3 sub-clusters)

Reverse-specs the `lib/Listener/` event-listener cluster: 4 files / 14 methods across 3 sub-clusters. All 3 are `--extend` of an existing capability — listeners are the reactive (event-driven) half of a pipeline whose synchronous half is already specced. Code already exists; this change retroactively specifies the observed behaviour and back-annotates the methods.

Source: `/tmp/or-scan/bundle-listener-all.json` (parent cluster `listener`, generated 2026-05-24). See [retrofit playbook](../../../.github/docs/claude/retrofit.md).

## Affected code units

### Sub-cluster `computed-fields-on-save` → extend `computed-fields` (6 methods)
- `lib/Listener/CalculationOnSaveListener.php::__construct`
- `lib/Listener/CalculationOnSaveListener.php::handle`
- `lib/Listener/CalculationOnSaveListener.php::process` (private)
- `lib/Listener/CalculationOnSaveListener.php::serialise` (private)
- `lib/Listener/CalculationOnSaveListener.php::loadSchema` (private)
- `lib/Listener/CalculationOnSaveListener.php::getCalculations` (private)

### Sub-cluster `realtime-updates-fanout` → extend `realtime-updates` (6 methods, 2 files)
- `lib/Listener/NotifyPushListener.php::resetStaticState` (static)
- `lib/Listener/NotifyPushListener.php::resolveQueue` (private)
- `lib/Listener/NotifyPushListener.php::resolveRegisterSlug` (private)
- `lib/Listener/NotifyPushListener.php::resolveSchemaSlug` (private)
- `lib/Listener/RealtimeEventListener.php::__construct`
- `lib/Listener/RealtimeEventListener.php::handle`

### Sub-cluster `translation-projection-on-write` → extend `register-i18n` (2 methods)
- `lib/Listener/TranslationProjectionListener.php::__construct`
- `lib/Listener/TranslationProjectionListener.php::handle`

## Approach

### computed-fields-on-save
`CalculationOnSaveListener` subscribes to `ObjectCreatingEvent` + `ObjectUpdatingEvent` and, for every `materialise: true` entry in the schema's `x-openregister-calculations` block, runs the `CalculationEvaluator` and **patches the result into the object payload before persistence**. This is the only listener in the bundle that mutates the persisted payload. The `computed-fields` spec already covers the equivalent declarative surface (`computed.expression` + `evaluateOn: save`, read-only on input, sandboxed evaluator, graceful per-field error handling) under "Save-Time Evaluation". The observed behaviours **not** yet captured there are the evaluator-context plumbing specific to this on-save listener:

- a synthetic `@self` system-metadata block (`id`, `uuid`, `register`, `schema`, `owner`, ISO-8601 `created`/`updated`) is injected into the evaluation context so expressions can reference `@self.created` etc., then stripped before persist;
- the payload is only re-set (`setObject()`) when at least one value actually changed (idempotent on no-op saves);
- declaration-order iteration with a documented acyclic-graph guarantee from the validator's cycle check;
- per-calculation `EvaluationException` is caught, logged at WARNING, and the field skipped without aborting the save.

Drafted as one new REQ on `computed-fields`. The listener implements the JSON-AST `x-openregister-calculations` annotation (archived change `2026-04-29-calculations-annotation`, not yet promoted to its own capability spec) — but the on-save materialisation behaviour is the same derived-field capability and belongs in `computed-fields`.

### realtime-updates-fanout
Both listeners are reactive plumbing for `realtime-updates`. The in-flight `add-live-updates` change already specs `NotifyPushListener`'s per-object / per-collection fan-out, dedup, soft-fail, and batch mode (those methods — `handle`, `dispatchPushes`, `setBatchMode`, `flushBatch`, `__construct` — already carry `@spec add-live-updates/tasks.md#task-4`). The 4 NotifyPushListener methods in *this* bundle are the resolver / lifecycle helpers not covered by that delta:

- `resetStaticState()` — test-isolation reset of all four static accumulators (`$batchMode`, `$batchedCollections`, `$seen`, `$queueUnavailable`); `@internal` for unit tests;
- `resolveQueue()` — lazy `IQueue` resolution from the container with a per-request single-DEBUG soft-fail latch (`$queueUnavailable`), never WARNING/ERROR;
- `resolveRegisterSlug()` / `resolveSchemaSlug()` — UUID→slug lookups that **bypass RBAC + multitenancy** (`_rbac: false, _multitenancy: false`) because the listener runs system-level, not user-scoped (issue #1454). Surfaced as a Notes item, not fixed.

`RealtimeEventListener` records every `ObjectCreated/Updated/Deleted/Transitioned` as a CloudEvent in the realtime event log via `RealtimeService::record()` — it is the recorder that feeds the SSE controller's buffer. This recorder-listener was DROPPED from `nested-aggregations#NAG-005` and is not currently specced as a listener anywhere, so its behaviour is drafted here.

Two new REQs on `realtime-updates`: one for the static-state / soft-fail / system-level-resolver semantics of `NotifyPushListener`, one for the `RealtimeEventListener` CloudEvent recorder.

### translation-projection-on-write
`TranslationProjectionListener` is the event-driven write-side of the `register-i18n` sidecar projection: on `ObjectCreated/Updated/Transitioned` it calls `TranslationProjectionService::project($object)`; on `ObjectDeleted` it calls `purge($object)`. The `register-i18n` spec currently covers the synchronous save/render path (`TranslationHandler::normalizeTranslationsForSave()` / `resolveTranslationsForRender()`) but does not specify the asynchronous projection of translatable content into the `openregister_translations` sidecar via lifecycle events. The service + sidecar table are named in the in-flight `i18n-source-of-truth` / `i18n-api-language-negotiation` changes. One new REQ on `register-i18n` for the event-driven projection/purge write-side.

## New requirements

- `computed-fields` — **Save-Time Materialisation of Declared Calculations** (1 REQ)
- `realtime-updates` — **The system MUST manage NotifyPushListener static state and resolve slugs at system level** (1 REQ)
- `realtime-updates` — **The system MUST record object lifecycle events as CloudEvents in the realtime log** (1 REQ)
- `register-i18n` — **The system MUST project translatable content into the sidecar on object lifecycle events** (1 REQ)

4 new REQs total. The remaining methods that map to already-specced behaviour (NotifyPushListener `handle`/`dispatchPushes`/`setBatchMode`/`flushBatch`/`__construct`) are out of scope here — they already carry their `add-live-updates` annotations.

## Notes

- **RBAC / multitenancy bypass (surfaced, not fixed)** — `NotifyPushListener::resolveRegisterSlug()` and `::resolveSchemaSlug()` call `RegisterMapper::find()` / `SchemaMapper::find()` with `_rbac: false, _multitenancy: false`. The in-code comment attributes this to issue #1454: without the bypass, cross-tenant lifecycle events leave the push payload's `register`/`schema` slug fields null because the request user's tenant does not own the register/schema. `CalculationOnSaveListener::loadSchema()` similarly passes `_multitenancy: false` to `SchemaMapper::find()`. This is observed behaviour and is documented in the REQ scenarios; it is a deliberate system-level-listener design choice but a multitenancy-boundary concern worth a follow-up review — not changed in this retrofit.
- `serialise()`, `loadSchema()`, `getCalculations()`, `resolveQueue()`, `resolveRegisterSlug()`, `resolveSchemaSlug()` are private; `resetStaticState()` is static + `@internal`. Method-level annotations are added for completeness.
- `RealtimeEventListener::handle` reads the new-state object from `getNewObject()` on update (and `getObject()` on create/delete/transition) — same accessor pattern as the other lifecycle listeners.

Source: `/tmp/or-scan/bundle-listener-all.json`. See retrofit playbook.
