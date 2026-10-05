---
kind: code
depends_on: [notification-actions-and-web-push]
---

## Why

The `x-openregister-notifications` dialect can now declare `actions[]`, `originApp`, and a `web-push` channel (foundation change `notification-actions-and-web-push`, hydra, `kind: config`) — but OpenRegister has no engine that *executes* that contract. Today notifications are dispatched anonymously as `setApp('openregister')`, carry only the implicit "View" action, and never reach a user whose Nextcloud tabs are all closed. This change builds the engine that resolves the new dialect keys and delivers a rich, app-branded notification over the Web Push protocol — so the fleet's headline pattern ("an incoming Contactmoment pops a notification with an *Open client* button, even when the browser is in the background") works for real.

This change is the **MIDDLE of a three-change chain** (ADR-032):

1. `notification-actions-and-web-push` (hydra, `kind: config`) — the dialect/contract delta + ADR-031 update. **(authored, validated; this change's `depends_on`)**
2. **THIS change** `openregister-web-push-engine` (openregister, `kind: code`) — the imperative engine that implements the contract.
3. `pipelinq-open-client-on-contactmoment` (pipelinq, `kind: config`) — the exemplar: a `Contactmoment` rule with an "Open client" relation action on the `web-push` channel. `depends_on` this engine.

## What Changes

- **Dialect resolution (declarative engine path).** Extend `NotificationAnnotationValidator` to accept the new keys (`web-push` channel, `actions[]` with the hard cap of 2, `originApp`). Extend `AnnotationNotificationDispatcher::emitNotification` to stamp `originApp` and resolve each action's `target` to a concrete deeplink (including the relation-resolved "Open client" case). Extend `AnnotationNotifier` to render declared actions and set the originApp hex icon.
- **Web Push backend (justified imperative — ADR-031 exception).** Add `minishlink/web-push`; an `occ` VAPID-keypair command; a `PushSubscription` DB entity + mapper + migration (infra state, NOT an OR object); a `WebPushService` that encrypts (aes128gcm) + VAPID-signs + POSTs to each endpoint and prunes `404/410 Gone`; a `BackgroundJob` (registered correctly via `IRegistrationContext`) so sending never blocks the request; duplicate-suppression of the stock popup on the tab-open case.
- **Web Push REST + frontend client.** Controller routes (`GET` vapid-public-key; `POST`/`DELETE` push-subscription, per-user) registered in `appinfo/routes.php`; an always-loaded subscribe script (registered via `BeforeTemplateRenderedEvent`) with opt-in-only permission UX (never prompt on load); a Service Worker (`js/openregister-push-sw.js`) handling `push` + `notificationclick`.
- **Hex icon generation.** An endpoint serving a cached PNG per `originApp`: the app's white `img/app.svg` glyph composited onto the Conduction cobalt hexagon (`#21468B`), plus a monochrome badge.
- **Settings UX.** Admin panel (VAPID status / public key / enabled flag) and a per-user opt-in "Enable browser notifications" toggle.
- **Tests, i18n (nl/en), docs.**

No new OpenRegister business schemas are introduced (see design.md → Seed Data).

## Capabilities

### New Capabilities
- `web-push-delivery`: the imperative Web Push send path — VAPID keypair lifecycle, the `PushSubscription` infra store, the aes128gcm/VAPID encrypted POST via `minishlink/web-push`, the background dispatch job, the REST subscribe/unsubscribe endpoints, the Service Worker, the hex-icon raster endpoint, duplicate suppression, and the admin/user settings. This is the justified ADR-031 imperative exception (external integration + cryptography + scheduled/bulk delivery) and is cleanly separable from the declarative dialect engine, so it earns its own capability.

### Modified Capabilities
- `notificatie-engine`: the declarative dialect-resolution behaviour gains action rendering, `originApp` identity resolution, action-target deeplink resolution (including server-side relation resolution), and routing of the `web-push` channel into the new send path. These are requirement-level additions to the existing engine, implemented in `NotificationAnnotationValidator`, `AnnotationNotificationDispatcher`, and `AnnotationNotifier`.

## Impact

- **Dialect plumbing (modified):** `lib/Service/Notification/NotificationAnnotationValidator.php` (channel enum + `actions[]`/`originApp` validation + cap-of-2), `lib/Service/Notification/AnnotationNotificationDispatcher.php` (`emitNotification` originApp stamp + target resolution + web-push routing), `lib/Notification/AnnotationNotifier.php` (declared-action rendering + originApp icon).
- **New backend:** `lib/Service/WebPush/WebPushService.php`, `lib/Db/PushSubscription.php` + `PushSubscriptionMapper.php`, `lib/Migration/Version*Date*.php`, `lib/BackgroundJob/WebPushDispatchJob.php`, `lib/Command/GenerateVapidKeys.php`, `lib/Controller/WebPushController.php`, `lib/Service/WebPush/HexIconService.php`.
- **Routes:** `appinfo/routes.php` (vapid-public-key, push-subscription, hex-icon).
- **Frontend:** `js/openregister-push-sw.js` (Service Worker), an always-loaded subscribe script registered via `BeforeTemplateRenderedEvent` listener, `src/views/settings/sections/PushNotificationsConfiguration.vue` (admin, extended), a per-user opt-in toggle section.
- **Dependencies:** new composer dep `minishlink/web-push`; Imagick (or NC image abstraction) for hex compositing.
- **Config:** app config keys for the VAPID public/private keypair (`<VAPID_PUBLIC_KEY>` / `<VAPID_PRIVATE_KEY>` — generated by the `occ` command, never committed).
- **Back-compat:** rules with no `actions`/`originApp`/`web-push` behave exactly as today (implicit "View" action, openregister icon, existing channels).
- **Rollback:** revert the code, drop the `minishlink/web-push` dep, drop the `oc_openregister_push_subscriptions` table via a down-migration; existing channels are unaffected.

## DEFERRED_QUESTIONS

1. **Which capability owns the dialect resolution vs the send path?** (a) Should the new imperative send path be a separate capability or folded into the existing engine? (b) Provisional decision: ADD a new `web-push-delivery` capability for the imperative send path (external integration + crypto + bulk delivery — a clean ADR-031 exception, cleanly separable), and apply the dialect-resolution additions as MODIFIED requirements on the existing `notificatie-engine` capability. (c) Affects: proposal Capabilities, both spec files.
2. **Split into backend + client sub-changes?** (a) If tasks pressure the 20-checkbox cap, should this split into `openregister-web-push-backend` + `openregister-web-push-client` per ADR-032? (b) Provisional decision: NOT split — the change lands at 18 unindented checkboxes, comfortably under the cap, and the engine reads as one coherent unit (the dialect resolution, send path, and client are tightly coupled and ship together to be useful). Keep as one change. (c) Affects: tasks.md (kept single).
3. **PushSubscription as a DB table or an OR object?** (a) Should subscriptions be modelled as OR objects/registers? (b) Provisional decision: a real DB table (`oc_openregister_push_subscriptions`) with entity + mapper + migration — it is transient cryptographic infra state with no business meaning, RBAC, audit, or relation value, so an OR object would be an abuse of the engine. (c) Affects: `web-push-delivery` spec, design Seed Data, tasks 2.2.
4. **Do the Service Worker + always-loaded script belong in openregister or a separate always-on bundle?** (a) openregister-only or a dedicated cross-app always-on bundle? (b) Provisional decision: ship in openregister — it owns the engine and is a foundation app present on every Conduction instance, and the `BeforeTemplateRenderedEvent` listener already guarantees fleet-wide page coverage; a separate bundle adds deployment surface without benefit today. Revisit if a non-openregister instance ever needs push. (c) Affects: design (always-loaded client decision), tasks 3.2/3.3.
