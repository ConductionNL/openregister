## Context

The foundation change `notification-actions-and-web-push` (hydra, `kind: config`) extended the `x-openregister-notifications` dialect (ADR-031) with `actions[]` (cap 2, four target kinds), `originApp`, and a `web-push` channel, plus the hex-icon convention and browser-degradation/permission rules. That change ships no runtime artifact — it is the contract. THIS change is the openregister engine that implements it, and is the middle of a three-change chain: foundation contract → **this engine** → the pipelinq exemplar (`pipelinq-open-client-on-contactmoment`).

Current state in openregister (real files):
- `lib/Service/Notification/NotificationAnnotationValidator.php` centralises shape validation with `VALID_CHANNELS = ['nc-notification','email','activity','webhook','talk']`, `VALID_TRIGGERS`, `VALID_RECIPIENT_KINDS`. No `web-push`, no `actions`, no `originApp`.
- `lib/Service/Notification/AnnotationNotificationDispatcher.php` — `emitNotification()` (~line 1839) builds the `INotification` with `setApp('openregister')`; per-channel emitters exist for nc-notification, email, activity, webhook, talk. No originApp stamping, no action-target resolution, no web-push routing.
- `lib/Notification/AnnotationNotifier.php` — `prepare()` calls `setIcon(...)` via `IURLGenerator::imagePath` and `addViewAction()` (the implicit "View"). No declared-action rendering, no originApp icon.
- `src/views/settings/sections/PushNotificationsConfiguration.vue` exists as an admin section to extend.

Push today is only the side effect of `notify_push` intercepting the `nc-notification` `IManager::notify()` call — delivered only while a Nextcloud tab is open. There is no true background channel and no VAPID/Service-Worker path.

## Goals / Non-Goals

**Goals:**
- Resolve the new dialect keys in the existing declarative engine (validator + dispatcher + notifier).
- Deliver rich, app-branded notifications over the Web Push protocol (VAPID + aes128gcm + Service Worker), including when all Nextcloud tabs are closed.
- Resolve the "Open client" relation-action deeplink server-side at dispatch through OR RBAC.
- Composite per-app hex icons; opt-in-only permission UX; admin + user settings.
- Keep every addition back-compatible (no `actions`/`originApp`/`web-push` ⇒ unchanged behaviour).

**Non-Goals:**
- Authoring any per-app `x-openregister-notifications` content (that is the pipelinq exemplar, the next chain member).
- Changing the existing trigger/recipient/subject contract.
- Introducing new OpenRegister business schemas (see Seed Data).
- Re-defining the dialect contract — that is fixed by the foundation change; this change implements it.

## Decisions

### Declarative-vs-imperative (ADR-031)

The **dialect resolution stays in the declarative engine.** Reading `actions[]`, `originApp`, and the `web-push` channel declaration, validating their shape, stamping the origin, and resolving action targets to deeplinks all live in the existing validator/dispatcher/notifier path that already interprets the schema-declared rule. No new "rule interpreter" service is introduced.

The **web-push SEND path is a justified IMPERATIVE exception** under ADR-031. VAPID JWT signing, aes128gcm payload encryption, the push-service POST to FCM/Mozilla/Apple endpoints, subscription lifecycle (subscribe/store/expire/prune), the background dispatch job, and hex-icon image compositing are imperative code because they are (a) **external integration** — browser push services are outside systems the schema engine cannot reach; (b) **cryptography** — VAPID + aes128gcm are not expressible as derived schema fields; and (c) **scheduled/bulk delivery** that genuinely needs a background job, not a read-time derived field. This is pre-justified here so the downstream reviewer sees the exception documented (the foundation change's design.md scoped what the contract does NOT make declarative; this change carries the engine-side justification).

### Seed Data (ADR-001)

N/A — no new OpenRegister object schemas. `PushSubscription` is infra DB state (endpoint + p256dh/auth keys per user/browser), not domain data, so it is a real DB table (`oc_openregister_push_subscriptions`) with its own entity + mapper + migration — explicitly NOT modelled as an OR object/register. There is therefore nothing to seed.

### web-push-delivery as its own capability

The send path is cleanly separable from the dialect engine and qualifies as the ADR-031 imperative exception, so it is a new `web-push-delivery` capability rather than bolted into `notificatie-engine`. The dialect-resolution additions (validator/dispatcher/notifier) are requirement-level modifications to the existing `notificatie-engine` capability. (See DEFERRED_QUESTIONS.)

### PushSubscription as a DB table, not an OR object

Per the foundation contract and ADR-001 reasoning, subscriptions are transient cryptographic endpoints with no business meaning, no RBAC needs beyond "owner only", and no audit/relation value. A dedicated table + `QBMapper` is the correct shape; modelling them as OR objects would pollute registers and abuse the object engine.

### Reuse `minishlink/web-push`

VAPID signing + aes128gcm encryption are reused from the established `minishlink/web-push` library rather than hand-rolling crypto. Added via composer; subject to `composer audit` (hydra gate).

### Background job registered correctly

The dispatch job is registered via `IRegistrationContext::registerJob(...)` (or a properly-wired `QueuedJob`) so the scheduler actually runs it. The fleet has a known recurring bug (docudesk/procest/shillinq) where jobs were registered via an invalid `IRegistrationContext` call and never ran — this change does it right and asserts runnability in a test. Web-push I/O runs in the job so the originating object-save request never blocks.

### Hex icon compositing + caching

The hex-icon endpoint composites the originApp's white `img/app.svg` glyph onto `design-system/brand/assets/hexes/hex-cobalt.svg` (`#21468B`) using Imagick (or the NC image abstraction), serving a cached PNG keyed by appId, plus a monochrome badge. Notifications require a raster image URL, so a PNG endpoint (not an inline SVG) is the right surface; caching avoids recompositing per notification.

### Always-loaded client + Service Worker shipped in openregister

The subscribe client is registered via a `BeforeTemplateRenderedEvent` listener so it loads on every Nextcloud page (push must work regardless of which app the user is viewing). The Service Worker (`js/openregister-push-sw.js`) is served at a registrable scope. Both ship in openregister because openregister owns the engine and is a foundation app present on every Conduction instance. (See DEFERRED_QUESTIONS on whether a separate always-on bundle is cleaner.) Permission is opt-in only — never prompted on load — to avoid Chrome's prompt-abuse penalties; subscription happens on a user gesture or the settings toggle.

### Duplicate suppression

When web-push is active for a recipient AND a tab is open, the stock notifications-app popup is suppressed so the recipient sees only the rich version. Mechanism: a shared notification `tag` plus a foreground-client flag the open tab reads (the foreground client, knowing it has an active push subscription, declines to render the plain popup for tagged notifications). The closed-browser path is single-source by construction and untouched. Alternative considered: server-side open-tab detection via `notify_push` presence — rejected as more fragile than the client-side tag/flag.

## Reuse Analysis

- **Validator** — extend `NotificationAnnotationValidator` in place (add to `VALID_CHANNELS`, add `actions`/`originApp` branches); no new validator class. Per ADR-011, validation utilities are reused, not duplicated.
- **Dispatcher** — extend `AnnotationNotificationDispatcher::emitNotification`; reuse its existing object/relation data access and RBAC for relation-target resolution rather than re-querying.
- **Notifier** — generalise `AnnotationNotifier::addViewAction()` to render declared actions; reuse its existing `addAction()`/`setIcon()` calls.
- **Admin settings** — extend the existing `PushNotificationsConfiguration.vue` rather than a new panel; the per-user toggle mirrors decidesk's `NotificationPreferencesSection.vue` pattern.
- **Crypto** — `minishlink/web-push`, not hand-rolled.
- **Hex asset** — reuse `design-system/brand/assets/hexes/hex-cobalt.svg` and every app's existing white `img/app.svg`.

## Risks / Trade-offs

- [Browser support gaps — Safari only as installed PWA] → contract-mandated graceful fallback to the foreground `nc-notification` popup when no active subscription; Chrome/Edge/Firefox get full background push.
- [Permission-prompt abuse penalties (Chrome quiets origins that prompt on load)] → never prompt on load; opt-in only via user gesture / settings toggle, enforced in the always-loaded client.
- [VAPID key rotation] → keys live in app config, generated by the `occ` command; rotation = regenerate + the next subscribe re-keys. Existing subscriptions signed with the old key fail and are pruned on `404/410`. Document the rotation procedure.
- [Payload privacy / encryption] → all payloads are aes128gcm-encrypted end-to-end to the browser; the server never sends cleartext to push services. Relation deeplinks are built only for objects the recipient may read (RBAC at dispatch).
- [New composer dependency] → `minishlink/web-push` is subject to `composer audit` (hydra gate); pin a maintained version and re-audit on bump.
- [Known IRegistrationContext job-registration bug] → the dispatch job is registered the correct way and a test asserts it is reachable/runnable, so it does not silently never-run like the docudesk/procest/shillinq jobs did.
- [Relation-target resolution leaking objects the recipient can't see] → resolution runs through OR RBAC at dispatch; deeplink built only for readable objects.
- [Duplicate notifications if suppression mis-detects an open tab] → suppression is best-effort on the tab-open path only; the closed-browser path (the primary value) is single-source by construction and unaffected.
- [VAPID secrets in docs] → only safe placeholders (`<VAPID_PUBLIC_KEY>`, `<VAPID_PRIVATE_KEY>`, `EXAMPLE_HOST`, nil UUID) appear in these artifacts; real keys are generated at runtime and never committed (gitleaks-safe).

## Migration Plan

- **Deploy**: add the `minishlink/web-push` dep; run the migration to create `oc_openregister_push_subscriptions`; deploy the code; run `occ openregister:web-push:generate-vapid` once per instance; enable the browser-push flag in admin settings.
- **Back-compat**: existing rules with no `actions`/`originApp`/`web-push` are unaffected — implicit "View", openregister icon, existing channels keep working.
- **Chain**: the pipelinq exemplar (`depends_on` this engine) is the first consumer once this ships.
- **Rollback**: revert the code; drop the `minishlink/web-push` dep; down-migration drops the subscriptions table; remove the VAPID config keys. No existing channel is touched, so non-web-push notifications continue unchanged.

## Open Questions

- Capability boundary: `web-push-delivery` (new) vs folding into `notificatie-engine` — see DEFERRED_QUESTIONS.
- Whether to split into `openregister-web-push-backend` + `openregister-web-push-client` if tasks pressure the 20-checkbox cap (ADR-032) — see DEFERRED_QUESTIONS.
- Whether the Service Worker + always-loaded script belong in openregister or a separate always-on bundle — see DEFERRED_QUESTIONS.
