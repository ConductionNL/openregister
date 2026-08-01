# Tasks — object-event-sync-async-split (openregister)

## Classification — 21 listener classes, 51 registrations

Recorded here so the sync/async verdict is auditable rather than folklore.
Format follows `actor-forwarded-listener-jobs/design.md` D4.
Categories are the four closed ADR-078 exception categories.

### Pre-event (`*ing`) — MUST STAY SYNCHRONOUS

| Listener | Events | Why |
|---|---|---|
| `LifecycleInitialStateListener` | Creating | `setObject()` seeds lifecycle state pre-persist |
| `CalculationOnSaveListener` | Creating, Updating | `setObject()` materialises computed fields; **consumes a sequence number on create** — must not run twice |
| `QualityScoreOnSaveListener` | Creating, Updating | `setObject()` patches quality score |
| `SurvivorshipRecomputeListener` | Creating, Updating | `setObject()` patches `goldenRecordField` / `provenanceField` |
| `HookListener` (`Application.php:2624-2626`) | Creating, Updating, Deleting | `HookExecutor::applyFailureMode` can **veto the save** here |

None of the five vetoes today: a grep for `stopPropagation` / `setErrors` /
`throw` across the post-event listeners returned zero hits, and the pre-event
four are pure mutators. `HookListener` is the only veto-capable registration.

### Post-event (`*ed`) — DEFERRED by this change

| Listener | Events | Payload / mechanism |
|---|---|---|
| `ObjectCleanupListener` | Deleted | Best candidate. 6 cleanups incl. a **full CalDAV calendar scan** (`TaskService::getTasksForObject` walks the whole calendar), calendar unlink, vCard rewrite, deck/email/comment deletes. All six private cleanups take **only `$objectUuid`** (`handle()` L160) → the hard-delete blocker does not apply. All six read `userSession->getUser()` → actor forwarding. **No extra payload needed.** |
| `HookListener` | Created, Updated, Deleted (`Application.php:2627-2629`) | Outbound HTTP: `HookExecutor::executeHooks()` → `adapter->executeWorkflow(timeout: 30)`, sync by default. `getObjectFromEvent()` already handles both halves → registration-level split, not a rewrite. **Deleted entry carries the serialized object.** |
| `FlowActionListener` | Created, Updated, Deleted | Mail (`IMailer`), CalDAV event create, federation share, agent dispatch. `CalendarEventService` uses `IUserSession->getUser()` → actor forwarding. **Deleted entry carries payload.** |
| `ContextChatSubmissionListener` | Created, Updated, Deleted | `allSeenUserIds()` walks every seen user as broadcast fallback. **Caveat:** at-least-once means a deferred `deleteContent` can land after the uuid was re-created → **job MUST check existence before removing**. |
| `ActionListener` | Created, Updated, Deleted | Outbound HTTP with per-action timeout; today only *failure* retries (`ActionRetryJob`), so the first attempt is synchronous. **Deleted entry carries payload.** |
| `SourceRecordChangeListener` | Created, Updated, Deleted | Heaviest inline work (reverse-FK index build via `findAll` of every schema → per-master `find` → full `saveObject` re-save, a recursive write cascade). **Strict payload contract:** the entry carries the **resolved new + old master UUIDs** (resolved inline), not the source uuid — Updated needs `getOldObject()` for re-parenting, Deleted reads `$data[$referenceField]` off a hard-deleted row. **Dedupe key = masterUuid.** ⚠ its `find(_rbac: true, _multitenancy: true)` runs under the job's **current** org authority, not captured authority — cross-org masters can resolve differently than inline (logged, proceeds under current authority). |

### Post-event (`*ed`) — BLOCKED, with the blocking fact

| Listener | Events | Verdict | Category | Blocking fact |
|---|---|---|---|---|
| `FlowTriggerListener` | C/U/D | fix, don't defer | `realtime` | Execution already deferred to `FlowRunWorker`; the 42.64 ms is **trigger resolution** (`OpenRegisterFlowResolver.php:181`). `FlowTriggerService::runInline()` (`FlowTriggerService.php:114`) deliberately runs `executionMode: sync` flows inside the triggering request — a **published read-after-write contract**. |
| `NotifyPushListener` | C/U/D | keep inline | `realtime` | It **is** the realtime channel. Holds per-request static state (`self::$seen`, `$batchMode`, `$batchedCollections`) whose semantics assume one request lifetime. |
| `GraphQLSubscriptionListener` | C/U/D | keep inline | `sapi-memory` | **Hard technical blocker.** `SubscriptionService::pushEvent` stores via `apcu_store` (`SubscriptionService.php:122`). APCu is per-SAPI → a cron/CLI worker writes a **different segment**; SSE readers would never observe the event. Deferring does not delay it, it **silently breaks** it. |
| `AggregationCacheInvalidationListener` | C/U/D | keep inline | `cheap-bounded` | 1 cache get + 1 set (version bump). A read later in the same request would see stale aggregations; deferring turns bounded 60 s staleness into cron-interval staleness. |
| `ObjectMetricsListener` | C/U/D | keep inline | `cheap-bounded` | One fail-soft INSERT. A job-list INSERT + cron round trip to save one INSERT is strictly worse, and blurs the metric timestamp. |
| `NotificationDedupePruneListener` | Deleted | keep inline | `correctness` | The prune exists so a re-created object with the same UUID re-arms cleanly; a prune landing after a same-uuid re-create wipes freshly-armed state. |
| `WebhookEventListener` | C/U/D | already async | — | Enqueues `WebhookDeliveryJob` per webhook (ADR-009 Rule 5). One micro-inefficiency fixed by task 1.3. |
| `ObjectChangeListener` | C/U | already async | — | Enqueues `ObjectTextExtractionJob`. Residual sync path is the admin opt-in `extractionMode === 'immediate'`. |
| `TranslationProjectionListener` | C/U/Transitioned | already deferred | — | `actor-forwarded-listener-jobs`; Deleted branch stays inline by design. |
| `AnnotationNotificationListener` | C/U/Transitioned | already deferred | — | `actor-forwarded-listener-jobs`. |
| `AggregationThresholdListener` | C/U/Transitioned | already deferred | — | `actor-forwarded-listener-jobs`; Deleted branch stays inline by design. |

## 1. Query-level fixes (remove work, do not relocate it)

- [ ] 1.1 Rewrite `OpenRegisterFlowResolver::flowsForTrigger()` (`lib/Service/Flow/OpenRegisterFlowResolver.php:181`) to filter by trigger + register + schema at query time, apply a bound, skip rendering, and memoize the resolved set per (trigger, register, schema) for the request. Do NOT defer `FlowTriggerService::runInline()`.
- [ ] 1.2 Parity test for 1.1: for a fixture set covering matching and non-matching triggers/registers/schemas, the filtered query result must equal the previous PHP-side filtered result.
- [ ] 1.3 Move `dispatchEvent`'s zero-webhook guard **before** `extractPayload()` in `lib/Service/WebhookService.php:660`, so a write on an instance with no webhooks pays no `jsonSerialize()`.

## 2. Deferred post-event listeners (existing contract only)

- [ ] 2.1 `ObjectCleanupListener` → `ObjectCleanupJob` (uuid-only entries, no extra payload); inline interest gate + kill-switch fallback.
- [ ] 2.2 Split `HookListener` registrations in `lib/AppInfo/Application.php`: L2624-2626 (Creating/Updating/Deleting) stay inline and veto-capable; L2627-2629 (Created/Updated/Deleted) → `HookDispatchJob`. Deleted entry carries the serialized object. Comment the split with an ADR-078 reference so a future edit cannot silently re-merge it.
- [ ] 2.3 `FlowActionListener` (C/U/D) → `FlowActionJob`; Deleted entry carries payload; verify `CalendarEventService` resolves the forwarded actor.
- [ ] 2.4 `ContextChatSubmissionListener` (C/U/D) → `ContextChatSubmissionJob`; the `deleteContent` path MUST check object existence before removing (same-uuid re-create race).
- [ ] 2.5 `ActionListener` (C/U/D) → `ActionDispatchJob`; Deleted entry carries payload; keep `ActionRetryJob` as the failure path unchanged.
- [ ] 2.6 `SourceRecordChangeListener` (C/U/D) → `SourceRecordRecomputeJob` under the D3 payload contract: resolve new + old master UUIDs **inline**, carry those (not the source uuid), dedupe on masterUuid, log captured-vs-current org drift and no-op rather than partially writing.

## 3. Inline exceptions

- [ ] 3.1 Annotate the six inline-by-exception post-event handlers with `@listener-placement inline <category> — <reason>` using the categories in the classification table above (`realtime` ×2, `sapi-memory` ×1, `cheap-bounded` ×2, `correctness` ×1).

## 4. Tests

- [ ] 4.1 Job unit tests for the six new jobs: forwarded-actor execution, stale/gone entry no-op, and — for `ContextChatSubmissionJob` — the same-uuid re-create existence check.
- [ ] 4.2 Listener unit tests: request path enqueues instead of running heavy work; interest gates suppress the enqueue; `listenerDeferral=inline` runs the full inline path.
- [ ] 4.3 `SourceRecordChangeListener` payload tests: re-parenting refreshes **both** masters; a bulk edit of 500 source records on one master collapses to one recompute; deleted-path master uuid resolved inline.
- [ ] 4.4 `HookListener` split test: a pre-event hook veto still aborts the save and enqueues **no** post-event job.

## 4b. Filtered subscription (D6 — resolves hydra Open Question 2)

- [x] 4b.1 `ObjectEventSubscription::register()` — registration-site declaration
      of register/schema interest; registers one shared proxy per event class,
      idempotently; `null` declarations mean "all" so adoption is opt-in.
- [x] 4b.2 `ObjectEventProxyListener` — resolves the written object's
      register/schema off the `ObjectEntity` once per dispatch and invokes only
      matching subscriptions. Resolution from the server container and the
      `QueryException` skip both mirror `ServiceEventListener` exactly;
      handler exceptions propagate as Symfony's dispatcher already lets them.
- [x] 4b.3 `RegisterMapper::findIdsBySlugs()` / `SchemaMapper::findIdsBySlugs()`
      — bounded `IN` lookup, one query per table per request, NOT the existing
      `getSlugToIdMap()` which materialises all 1,931 schema rows.
- [x] 4b.4 Per-request memoisation plus a 60 s local (APCu) cache of the
      resolved slug→id maps. Neither table has an index on `slug`, so an
      uncached resolution is a sequential scan (measured 1,137 us for the pair).
- [x] 4b.5 `objectEventFilter` app-config kill switch restores unfiltered
      invocation instance-wide; also the A/B knob.
- [x] 4b.6 Proxy self-instrumentation behind the existing
      `/tmp/or-trace-write-phases` flag, writing per-request
      dispatch/invoked/skipped counts and its own microsecond cost to
      `/tmp/or-event-proxy.log`.
- [ ] 4b.7 Unit tests for `ObjectEventSubscription` (declaration normalisation,
      proxy registered once per event, per-request scoping) and for the proxy's
      match logic. NOT DONE in the pilot change.
- [ ] 4b.8 Convert OpenRegister's own schema-specific listeners. NOT DONE —
      OpenRegister's listeners are mostly not schema-specific, which is why the
      pilot is a leaf app.

### Pilot (procest) — falsifiable evidence

- [x] 4b.9 `BezwaarLifecycleListener` (Created + Updated) and
      `BezwaarLegalHoldListener` (Created) converted, declaring
      `registers: ['procest']` and their own schema slug lists.
- [x] 4b.10 Direct non-invocation proof, with a temporary counter inside
      `handle()` that was removed afterwards. Same write
      (`larpingapp/character`, register 8 / schema 18) in both arms, toggled at
      runtime rather than redeployed:
      - kill switch `off` → `handle()` INVOKED (proxy: `invoked=4 skipped=0`)
      - kill switch `on` → `handle()` NOT invoked (proxy: `invoked=0 skipped=4`)
      - positive control, `procest/bezwaar` (register 17 / schema 116) with the
        switch `on` → `handle()` INVOKED (proxy: `invoked=2 skipped=2`)
- [x] 4b.11 Measured cost, n=18 requests per arm, interleaved ON/OFF/ON/OFF at
      host load 16-21 throughout:

      | | filter off | filter on |
      |---|---|---|
      | invocations | 72 | 0 |
      | proxy cost / request | 1,845 us (median ~1,100) | 308 us (median ~168) |
      | proxy cost / dispatch | 922 us | 154 us |
      | listener handler time / request | 536 us | 0 us |

      Converting two leaf listeners removes ~0.9-1.5 ms per object write for a
      filter cost of ~0.17-0.31 ms. Most of the saving is not handler body but
      the DI construction of the two listeners, which no longer happens.

## 5. Verification

- [ ] 5.1 Hydra gate 61 (`listener-work-placement`) reports PASS on openregister with only annotated exclusions — no blanket suppression.
- [ ] 5.2 Prove the gate can fail: add a temporary post-event listener doing inline outbound HTTP with no annotation and confirm `run-hydra-gates.sh` **exits non-zero**; a FAIL line on stdout is not sufficient evidence. Remove the probe afterwards.
- [ ] 5.3 `composer check:strict` (phpcs/phpmd/psalm/phpstan) clean on all touched files; full `tests/Unit/Listener` + `tests/Unit/BackgroundJob` suites green.
- [ ] 5.4 Re-measure `mm:EVENT-DISPATCH` at n≥32 on the same instance and record before/after next to the 2026-07-30 baseline (min 42 / median 133 / p95 175 / max 206 ms).
- [ ] 5.5 Regression-check opencatalogi and softwarecatalog object writes, specifically hook-driven workflows and source-record master recompute.
- [ ] 5.6 `openspec validate object-event-sync-async-split --strict` passes.

## Acceptance criteria

- All 21 listener classes and 51 registrations appear in the classification table above with a verdict; every non-deferred post-event listener names one of the four ADR-078 categories plus a specific blocking fact.
- The five pre-event registrations and `HookListener`'s Creating/Updating/Deleting group are unchanged and still veto-capable; `CalculationOnSaveListener` still consumes exactly one sequence number per create.
- No new deferral mechanism is introduced; every deferred listener uses `ListenerDeferralService` + an `ActorForwardedJob` subclass and honours `listenerDeferral=inline`.
- `GraphQLSubscriptionListener` is NOT deferred, and the APCu per-SAPI reason is recorded in both its annotation and the table.
- `FlowTriggerService::runInline()` still executes `executionMode: sync` flows inside the triggering request.
- `flowsForTrigger()` issues a filtered, bounded, unrendered query and its result is proven equal to the previous PHP-side filter on the fixture set.
- Every deferred delete-path job carries the state it needs in its entry payload and no-ops when the target object is absent or soft-deleted.
- Gate 61 fails a deliberately violating listener with a non-zero exit code, verified by exit code and not by stdout text.
- Example values in specs and docs use obviously-fake placeholders (`YOUR_API_KEY_HERE`, `00000000-0000-0000-0000-000000000000`).
