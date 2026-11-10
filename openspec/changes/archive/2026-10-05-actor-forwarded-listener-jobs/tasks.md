# Tasks — actor-forwarded-listener-jobs

## 1. Contract

- [x] 1.1 `DeferredListenerContext` value object (userId, organisationUuid, entries; `toJobArguments()`/`fromJobArguments()` round-trip) — `lib/Service/Deferral/DeferredListenerContext.php`.
- [x] 1.2 `ListenerDeferralService` — actor capture (fail-soft), per-job-class entry buffers with chunk-size flush + shutdown flush, per-entry dedupe keys, `listenerDeferral` kill switch — `lib/Service/Deferral/ListenerDeferralService.php`.
- [x] 1.3 `ActorForwardedJob` abstract QueuedJob — resolve captured user, `setUser()`, org-drift log, `finally` restore, skip-on-unresolvable-user — `lib/BackgroundJob/ActorForwardedJob.php`.
- [x] 1.4 `DeferredEntryObjectResolver` — stale-safe entry re-fetch (scoped raw lookup; gone/soft-deleted → null) shared by all three jobs — `lib/Service/Deferral/DeferredEntryObjectResolver.php`.

## 2. Deferred listeners

- [x] 2.1 `TranslationProjectionJob` (re-fetch, skip gone/deleted, `project()` current state) + slim `TranslationProjectionListener` to gate + enqueue for C/U/Transitioned; delete purge stays inline; inline fallback for kill switch.
- [x] 2.2 `AnnotationNotificationDispatchJob` (re-fetch, `_newData` = current, `_oldData` = captured snapshot, `updated`+`calculatedChange` parity, `transition` context) + slim `AnnotationNotificationListener` to gate + enqueue; inline fallback.
- [x] 2.3 Extract `ThresholdEvaluationService` from `AggregationThresholdListener` (evaluate/compare/state-cache verbatim); `AggregationThresholdJob` for C/U/Transitioned with (register, schema) dedupe; deletes evaluate inline via the service; inline fallback.

## 3. Tests

- [x] 3.1 `tests/Unit/Service/Deferral/DeferredListenerContextTest.php` — arguments round-trip incl. null actor and payload snapshots.
- [x] 3.2 `tests/Unit/Service/Deferral/ListenerDeferralServiceTest.php` — chunk flush, shutdown-remainder flush, dedupe coalescing, kill switch, fail-soft capture.
- [x] 3.3 `tests/Unit/BackgroundJob/ActorForwardedJobTest.php` — impersonate + restore round-trip, finally-restore on exception, unresolvable-user skip, null-actor no-impersonation.
- [x] 3.4 `tests/Unit/BackgroundJob/TranslationProjectionJobTest.php` — projects re-fetched object under forwarded actor; deleted/gone entry no-ops.
- [x] 3.5 `tests/Unit/BackgroundJob/AnnotationNotificationDispatchJobTest.php` — created/transition/updated(+calculatedChange) dispatch parity with the inline listener; stale no-op.
- [x] 3.6 `tests/Unit/BackgroundJob/AggregationThresholdJobTest.php` + `tests/Unit/Service/Aggregation/ThresholdEvaluationServiceTest.php` — rising-edge semantics preserved through the job; deleted entry no-op.
- [x] 3.7 Listener tests: `TranslationProjectionListenerTest` (new), `AnnotationNotificationListenerTest` (new), `AggregationThresholdListenerTest` (updated) — request path enqueues instead of running heavy work; gates suppress enqueue; delete paths stay inline; kill switch runs inline.

## 4. Verification

- [x] 4.1 phpcs/phpmd/phpstan/psalm clean on touched files.
- [x] 4.2 Full `tests/Unit/Listener` + `tests/Unit/BackgroundJob` suites green.
- [x] 4.3 `openspec validate actor-forwarded-listener-jobs --strict` passes.
