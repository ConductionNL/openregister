# Retrofit — notificatie-engine (partial reverse-spec, 5 of N)

Reverse-engineers 5 net-new REQs from observed behavior in the annotation-driven notification dispatcher and its supporting services. This is a **partial pass** (first 5 REQs / 31 methods of the 88-method cluster) — the remaining 57 methods are deferred to a follow-up pass (see `future-pass:next` in tasks.md). Code already exists — this change retroactively specifies it.

## Scope of this pass

Five cohesive REQs covering the annotation-driven dispatch pipeline core:

- **REQ-101** — Dispatcher pipeline orchestration (schema annotation read → trigger match → per-recipient fan-out → broadcast channels)
- **REQ-102** — Recipient resolution across six recipient kinds (users / field / groups / relation / object-acl / expression) with `userExists()` fail-closed
- **REQ-103** — Token-bucket rate limiting per `(rule, recipient)` with per-rule override and kill switch
- **REQ-104** — Per-`(rule, recipient)` debounce coalescing with `windowSeconds` + `maxEvents` flush
- **REQ-105** — Idempotency-key claim-first dedup with database unique-index serialisation under concurrency

## Affected code units (31 methods, this pass)

### REQ-101 — Dispatcher pipeline
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::dispatch` (entry)
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::matches`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::dispatchBroadcastChannel`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::recordHistory`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::recordHistoryAcrossChannels`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::loadSchema`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::getAnnotation`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::numericConditionMatches`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::__construct`
- `lib/Listener/AnnotationNotificationListener.php::handle`
- `lib/Listener/AnnotationNotificationListener.php::extractObject`
- `lib/Listener/AnnotationNotificationListener.php::__construct`

### REQ-102 — Recipient resolution
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::resolveRecipients`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::resolveObjectAclRecipients`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::resolveExpressionRecipients`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::extractUidsFromRelation`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::userExists`
- `lib/Service/Notification/RecipientResolverInterface.php::resolve`

### REQ-103 — Token-bucket rate limiting
- `lib/Service/Notification/RateLimiter.php::__construct`
- `lib/Service/Notification/RateLimiter.php::tryConsume`
- `lib/Service/Notification/RateLimiter.php::isEnabled`
- `lib/Service/Notification/RateLimiter.php::resolveLimits`
- `lib/Service/Notification/RateLimiter.php::key`
- `lib/Service/Notification/RateLimiter.php::persist`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::rateLimitAllows`

### REQ-104 — Burst coalescing
- `lib/Service/Notification/NotificationCoalescer.php::__construct`
- `lib/Service/Notification/NotificationCoalescer.php::shouldDispatch`
- `lib/Service/Notification/NotificationCoalescer.php::inspect`
- `lib/Service/Notification/NotificationCoalescer.php::isEnabled`
- `lib/Service/Notification/NotificationCoalescer.php::resolveWindowSeconds`
- `lib/Service/Notification/NotificationCoalescer.php::resolveMaxEvents`
- `lib/Service/Notification/NotificationCoalescer.php::key`
- `lib/Service/Notification/NotificationCoalescer.php::persist`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::coalesceAllows`

### REQ-105 — Idempotency-key claim-first dedup
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::claimIdempotencyKey`
- `lib/Service/Notification/AnnotationNotificationDispatcher.php::resolveIdempotencyKey`

## Deferred to future passes (57 methods)

The remaining 57 methods sort cleanly into 7 additional REQs that should be drafted in a follow-on `retrofit-2026-05-25-notificatie-engine-pass-2` (or merged into this change once approved):

- **Channel emit surfaces** (`emitNotification`, `emitEmail`, `emitActivity`, `emitWebhook`, `emitTalk`) — refines existing channel REQ
- **Per-locale subject resolution + interpolation** (`resolveLocalizedSubject`, `resolveUserLocale`, `interpolate`) — refines i18n REQ
- **Organisation pinning** (`organisationGateAllows`) — refines multi-tenant REQ
- **Subscription gating** (`filterBySubscription`, `NotificationSubscriptionsController::*`, `notificationSubscriptions.js::*`, `NotificationSubscriptionToggle.vue::refresh`, `NotificationsSection.vue::save`) — refines user-preference REQ
- **Annotation validator** (`NotificationAnnotationValidator::validate`, `validateOrganisationGate`) — net-new "schema-save validation" REQ
- **Annotation installer** (`NotificationsAnnotationInstaller::*`) — net-new "persistent webhook materialisation" REQ
- **Scheduled + threshold + batch jobs** (`ScheduledNotificationJob::*`, `BatchNotificationJob::run`, `NotificationDigest::*`, `NotificationReadState::*`) — refines batching/scheduling REQ
- **History controller + audit query** (`NotificationHistoryController::*`) — refines audit REQ
- **VNG envelope** (`VngNotificatiesEnvelope::mapAction`) — refines VNG REQ
- **Renderer-side** (`AnnotationNotifier::prepare`, `getName`, `getID`, `__construct`; legacy `Notifier::__construct`, `getName`) — refines INotifier REQ

## DROP / sibling-cap

No methods dropped. The legacy `Notifier::__construct` and `Notifier::getName` carry an existing `@spec` tag pointing at the prior archived retrofit (`retrofit-2026-04-28-notificatie-engine`); those will be re-pointed during the future pass that drafts the renderer REQ.

## Notes & observations

- The existing `openspec/specs/notificatie-engine/spec.md` is named-requirement style (no REQ-IDs) and was authored as the original aspirational spec. This retrofit adds **REQ-101..REQ-105** as numbered IDs for traceability of code↔spec links without colliding with the named entries above. REQ-IDs start at 101 to leave 001–099 for any future renumbering of the named requirements during a follow-up cleanup.
- **`notificatie-engine#ISO-8601`** is **NOT** a REQ — confirmed: it appears only as a scenario-token referring to ISO 8601 timestamp format at lines 138 and 319 of the spec (the `aanmaakdatum` field in the VNG envelope and the webhook `timestamp` field). The triage flag is correct; no REQ rename or removal is needed in this pass.
- **Security-relevant observation (REQ-102)**: `resolveRecipients()` reads attacker-controlled object-data fields when `kind=field` or `kind=relation`. Every extracted UID is verified via `IUserManager::userExists()` (with a per-request cache that intentionally does NOT cache `\Throwable` results — transient LDAP / DB hiccups must not silently drop notifications for the rest of the request). This is a deliberate fail-closed gate that should be preserved by any future refactor.
- **Concurrency observation (REQ-105)**: the dispatcher claims the dedup row **before** sending. Prior order (check → send → record) had a TOCTOU window where two concurrent dispatchers could both send. The current order trades a "failed send leaves a dedup row that blocks retry within the window" for "no double-send under concurrency". The `Version1Date20260511120000` migration installs the unique `(notification_slug, idempotency_key)` index that serialises the claim.
- **Fail-open observation (REQ-103 / REQ-104)**: the limiter and coalescer both fail open — when the cache backend is missing or a state read throws, dispatch proceeds. This protects against a broken infrastructure layer silencing legitimate notifications. Operators who want a hard gate must monitor the warning logs (`[NotificationRateLimiter] cache backend unavailable`, `[NotificationCoalescer] cache backend unavailable`).
- **Annotation source observation (REQ-101)**: rules are read from `Schema::getConfiguration()['x-openregister-notifications']` — a schema-author-declared block, not a separate `NotificationRule` table as the existing aspirational spec describes. This is a concrete architectural decision the code already encodes; the existing spec's `NotificationRule` entity is not yet implemented and should be reconciled with the schema-annotation approach in a future design pass.

Source: `/tmp/or-scan/rspec-cluster-notificatie-engine.json` (88 methods, 19 files). See retrofit playbook.
