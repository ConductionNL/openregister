# Tasks

- [x] task-1: notificatie-engine#REQ-101 — Annotation dispatcher pipeline (dispatch, matches, dispatchBroadcastChannel, recordHistory, recordHistoryAcrossChannels, loadSchema, getAnnotation, numericConditionMatches, constructor) + listener wiring (AnnotationNotificationListener::handle, extractObject, constructor) (retroactive annotation)
- [x] task-2: notificatie-engine#REQ-102 — Recipient resolution across six kinds (resolveRecipients, resolveObjectAclRecipients, resolveExpressionRecipients, extractUidsFromRelation, userExists; RecipientResolverInterface::resolve contract) (retroactive annotation)
- [x] task-3: notificatie-engine#REQ-103 — Token-bucket rate limiter (RateLimiter::tryConsume, isEnabled, resolveLimits, key, persist, constructor; AnnotationNotificationDispatcher::rateLimitAllows wrapper) (retroactive annotation)
- [x] task-4: notificatie-engine#REQ-104 — Per-(rule, recipient) coalescer (NotificationCoalescer::shouldDispatch, inspect, isEnabled, resolveWindowSeconds, resolveMaxEvents, key, persist, constructor; AnnotationNotificationDispatcher::coalesceAllows wrapper) (retroactive annotation)
- [x] task-5: notificatie-engine#REQ-105 — Idempotency-key claim-first dedup (AnnotationNotificationDispatcher::claimIdempotencyKey, resolveIdempotencyKey) (retroactive annotation)

## future-pass:next

The following 57 methods from `/tmp/or-scan/rspec-cluster-notificatie-engine.json` are deferred to a follow-up pass. They sort cleanly into 8 additional REQs (suggested IDs only — confirm at draft time):

- **future-task-6: notificatie-engine#REQ-106** — Channel emit surfaces: `AnnotationNotificationDispatcher::emitNotification`, `emitEmail`, `emitActivity`, `emitWebhook`, `emitTalk`
- **future-task-7: notificatie-engine#REQ-107** — Per-locale subject resolution + interpolation: `resolveLocalizedSubject`, `resolveUserLocale`, `interpolate`
- **future-task-8: notificatie-engine#REQ-108** — Organisation pinning gate: `organisationGateAllows`; `NotificationAnnotationValidator::validateOrganisationGate`
- **future-task-9: notificatie-engine#REQ-109** — Subscription gating: `filterBySubscription`; `NotificationSubscriptionsController::index`, `create`, `destroy`, `resolveUserId`, `coerceNullableInt`; `notificationSubscriptions.js::listSubscriptions`, `subscribe`, `unsubscribe`, `hasSubscription`; `NotificationSubscriptionToggle.vue::refresh`; `NotificationsSection.vue::save`
- **future-task-10: notificatie-engine#REQ-110** — Schema-save annotation validation: `NotificationAnnotationValidator::validate`
- **future-task-11: notificatie-engine#REQ-111** — Persistent-webhook materialisation: `NotificationsAnnotationInstaller::__construct`, `installSchema`, `handle`, `upsertWebhook`, `findByName`
- **future-task-12: notificatie-engine#REQ-112** — Scheduled / threshold / batch jobs + digest queue + read-state: `ScheduledNotificationJob::isDue`, `markFired`, `matchesFilter`, `__construct`, `run`, `processSchema`, `stateKey`, `fire`; `BatchNotificationJob::run`; `NotificationDigest::recipientCount`, `totalPending`, `pendingCount`, `flush`; `NotificationReadState::markUnread`, `readCount`, `key`, `isRead`
- **future-task-13: notificatie-engine#REQ-113** — Notification history query API: `NotificationHistoryController::extractFilters`, `resolveLimit`, `resolveOffset`
- **future-task-14: notificatie-engine#REQ-114** — VNG envelope shape: `VngNotificatiesEnvelope::mapAction`
- **future-task-15: notificatie-engine#REQ-115** — Renderer-side (INotifier) refinement: `AnnotationNotifier::__construct`, `getName`, `getID`, `prepare`; legacy `Notifier::__construct`, `getName` (re-point existing `@spec` to new REQ)
