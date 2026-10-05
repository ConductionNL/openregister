## 1. Dialect resolution (declarative engine)

- [x] 1.1 Extend `lib/Service/Notification/NotificationAnnotationValidator.php`: add `web-push` to `VALID_CHANNELS`; validate `actions[]` (each: i18n `label`, optional `primary` bool, `target` of kind `object-detail`|`route`|`url`) with the hard cap of 2 (`notification-too-many-actions`), plus `notification-action-bad-label` / `notification-action-bad-target` / `notification-bad-origin-app`.
- [x] 1.2 Extend `lib/Service/Notification/AnnotationNotificationDispatcher.php::emitNotification` to stamp resolved `originApp` (declared, else register-owning app) and route the `web-push` channel into the send path (background job).
- [x] 1.3 Add action-target deeplink resolution in the dispatcher: `object-detail` (triggering object), `object-detail`+relation (server-side relation resolve through OR RBAC = "Open client"), `route` (`{{prop}}` HTML-escaped interpolation), `url` (passthrough).
- [x] 1.4 Extend `lib/Notification/AnnotationNotifier.php` to render declared `actions[]` via `addAction()` (keep implicit "View" only when none declared) and set the icon to the originApp hex composite.

## 2. Web Push backend (imperative — ADR-031 exception)

- [x] 2.1 Add the `minishlink/web-push` composer dependency (run `composer audit`) and an `occ` command `lib/Command/GenerateVapidKeys.php` (`openregister:web-push:generate-vapid`) storing the keypair in app config.
- [x] 2.2 Add `lib/Db/PushSubscription.php` + `PushSubscriptionMapper.php` + `lib/Migration/Version*Date*.php` creating `oc_openregister_push_subscriptions` (userId, endpoint, p256dh, auth, userAgent, createdAt) — infra table, NOT an OR object.
- [x] 2.3 Add `lib/Service/WebPush/WebPushService.php`: look up the recipient's subscriptions, build the aes128gcm-encrypted VAPID-signed payload via `minishlink/web-push`, POST each endpoint, prune `404/410 Gone`.
- [x] 2.4 Add `lib/BackgroundJob/WebPushDispatchJob.php` and register it correctly via `IRegistrationContext::registerJob` (do NOT reproduce the fleet's never-run mis-registration bug) so web-push send is out of band.
- [x] 2.5 Implement duplicate suppression: when web-push is active and a tab is open, suppress the stock popup via shared notification `tag` + foreground-client flag; closed-browser stays single-source.

## 3. REST + frontend client + Service Worker

- [x] 3.1 Add `lib/Controller/WebPushController.php` (`GET` vapid-public-key; `POST`/`DELETE` push-subscription, `#[NoAdminRequired]`, current-user-only, no IDOR) and register the routes in `appinfo/routes.php`.
- [x] 3.2 Add the always-loaded subscribe script registered via a `BeforeTemplateRenderedEvent` listener (loads on every NC page); opt-in only — never prompt on load; subscribe with `{userVisibleOnly:true, applicationServerKey:<VAPID public>}` on gesture/toggle and POST the subscription.
- [x] 3.3 Add the Service Worker `js/openregister-push-sw.js` at a registrable scope: `push` → `registration.showNotification(title,{body,icon,badge,actions,tag,data})`; `notificationclick` → focus-or-open the resolved deeplink (top-level + per `event.action`).

## 4. Hex icon

- [x] 4.1 Add `lib/Service/WebPush/HexIconService.php` + a hex-icon route serving a cached PNG per `originApp` (white `img/app.svg` glyph on cobalt hex `#21468B` from `design-system/brand/assets/hexes/hex-cobalt.svg`, via Imagick/NC image abstraction) plus a monochrome badge.

## 5. Settings UX

- [x] 5.1 Extend `src/views/settings/sections/PushNotificationsConfiguration.vue` (admin): VAPID status / public key / browser-push-enabled flag.
- [x] 5.2 Add a per-user opt-in "Enable browser notifications" toggle section (mirror decidesk's `NotificationPreferencesSection.vue`) that drives permission + subscribe/unsubscribe.

## 6. Tests, i18n, docs

- [x] 6.1 PHPUnit: validator (channel/actions/cap-of-2/originApp errors), dispatcher (originApp stamp, four target kinds incl. relation+RBAC, web-push routing), notifier (declared actions + icon), `WebPushService` (encrypt/sign/POST/prune), and a test asserting `WebPushDispatchJob` is registered and runnable.
- [x] 6.2 Frontend tests (subscribe client opt-in gating, Service Worker push/notificationclick) and i18n nl/en strings for new UI + action labels (English keys per ADR-025).
- [x] 6.3 Update docs: VAPID setup (`occ` command), key rotation, browser-degradation matrix, and the duplicate-suppression mechanism.

## Acceptance criteria

- A rule with `channels:["web-push"]`, `originApp:"pipelinq"`, and an `object-detail`+relation action delivers a background notification (browser closed) showing the pipelinq hex icon and an "Open client" button that opens the related Client deeplink.
- Validation rejects a 3rd action with `notification-too-many-actions`, bad labels/targets/originApp with their respective error codes.
- Rules with no `actions`/`originApp`/`web-push` behave exactly as before (implicit "View", openregister icon, existing channels) — back-compat preserved.
- `WebPushService` prunes subscriptions on `404/410`; the dispatch job runs out of band and is verifiably registered.
- No push-permission prompt fires on page load; opt-in is gesture/toggle-only.
- No VAPID schema introduced as an OR object; `PushSubscription` is a DB table.

## Quality reminders

- Run all 18 hydra gates (`hydra-gates`) — especially `route-auth`, `route-reachability`, `no-admin-idor` (subscription endpoints), `notification-dialect`, `spdx-headers`, `forbidden-patterns`, `composer-audit` (new dep).
- `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) green; EUPL-1.2 SPDX header + `@spec` tags on every new/changed PHP method.
- Use only safe placeholders in code/docs (`<VAPID_PUBLIC_KEY>`, `<VAPID_PRIVATE_KEY>`, `EXAMPLE_HOST`, nil UUID `00000000-0000-0000-0000-000000000000`) — never realistic-looking keys (gitleaks).
- Fix any pre-existing quality issues encountered in the touched files (CLAUDE.md mandate).
