## ADDED Requirements

### Requirement: VAPID keypair lifecycle

OpenRegister SHALL generate, store, and expose a VAPID keypair used to sign all Web Push payloads. An `occ` command SHALL generate the keypair and persist it in app config; the public key SHALL be exposable to the frontend while the private key SHALL never leave the server. When no keypair exists the engine SHALL NOT attempt web-push delivery.

#### Scenario: Generate the VAPID keypair via occ

- **WHEN** an administrator runs `occ openregister:web-push:generate-vapid`
- **THEN** a VAPID public/private keypair is generated and stored in app config (`<VAPID_PUBLIC_KEY>` / `<VAPID_PRIVATE_KEY>`), and the command prints the public key for confirmation

#### Scenario: Public key exposed, private key withheld

- **WHEN** the frontend requests the VAPID public key via the public-key endpoint
- **THEN** the engine returns the stored public key and never the private key

#### Scenario: No keypair configured

- **WHEN** a `web-push` rule fires but no VAPID keypair has been generated
- **THEN** the engine skips web-push delivery and logs a configuration warning rather than throwing

### Requirement: Push subscription persistence

OpenRegister SHALL persist Web Push subscriptions as infrastructure DB state in a dedicated table (`oc_openregister_push_subscriptions`), NOT as an OpenRegister object/register. Each row SHALL store `userId`, `endpoint`, `p256dh`, `auth`, `userAgent`, and `createdAt`, keyed per (user, browser). The subscription store SHALL be created by a migration.

#### Scenario: Store a new subscription

- **WHEN** an authenticated user POSTs a fresh push subscription (endpoint + p256dh + auth)
- **THEN** the engine persists a row keyed to that user and browser, recording the user agent and creation timestamp

#### Scenario: Subscription is infra state, not an OR object

- **WHEN** the migration runs
- **THEN** it creates the `oc_openregister_push_subscriptions` DB table; no register, schema, or OR object is created for subscriptions

#### Scenario: Remove a subscription

- **WHEN** an authenticated user DELETEs their push subscription for the current browser
- **THEN** the matching row is removed and no further pushes are sent to that endpoint

### Requirement: Encrypted VAPID-signed delivery

The `WebPushService` SHALL, given a recipient uid and a notification payload, look up that user's subscriptions, build an aes128gcm-encrypted VAPID-signed payload via `minishlink/web-push`, and POST it to each endpoint. On a `404` or `410 Gone` response the service SHALL prune the dead subscription.

#### Scenario: Deliver to every active subscription

- **WHEN** `WebPushService` is asked to deliver a payload to a recipient with two active subscriptions (e.g. desktop + laptop)
- **THEN** it POSTs the aes128gcm-encrypted, VAPID-signed payload to both endpoints

#### Scenario: Prune a gone subscription

- **WHEN** a push POST returns `404` or `410 Gone`
- **THEN** the service deletes that subscription row and continues delivering to the remaining endpoints

### Requirement: Background dispatch job

Web Push delivery SHALL run in a background job so the originating request is never blocked on push I/O. The job SHALL be registered via `IRegistrationContext` correctly (the fleet's known mis-registration bug — registering the job in a way that never runs — SHALL NOT be reproduced).

#### Scenario: Web-push fires without blocking the request

- **WHEN** a dialect notification with the `web-push` channel fires during an object save
- **THEN** a background job is queued and the save request returns immediately; the encrypted push is sent by the job out of band

#### Scenario: Job is actually registered

- **WHEN** the app boots
- **THEN** the dispatch job is registered through `IRegistrationContext::registerJob(...)` (or a correctly-wired `QueuedJob`) such that the scheduler runs it — verified by a test asserting the job class is reachable and runnable

### Requirement: Duplicate suppression while a tab is open

When the `web-push` channel is active for a recipient AND a Nextcloud tab is open, the engine SHALL suppress the duplicate stock notifications-app browser popup so the recipient sees only the rich web-push version. The closed-browser case SHALL remain single-source and SHALL NOT be suppressed.

#### Scenario: Tab open — stock popup suppressed

- **WHEN** a `web-push` rule fires for a recipient with at least one open Nextcloud tab who would otherwise also get a plain popup
- **THEN** the engine suppresses the plain popup (documented mechanism — shared notification tag / foreground-client flag) and the recipient sees only the rich web-push notification

#### Scenario: No tab open — no suppression

- **WHEN** a `web-push` rule fires for a recipient with no open tab
- **THEN** only the background web-push is delivered and no suppression logic runs

### Requirement: Web Push REST endpoints

OpenRegister SHALL expose REST endpoints, registered in `appinfo/routes.php` with correct Nextcloud auth attributes: a `GET` returning the VAPID public key, and `POST`/`DELETE` managing the current user's push subscription (`#[NoAdminRequired]`, per-user — a user SHALL only manage their own subscriptions).

#### Scenario: Fetch the VAPID public key

- **WHEN** the frontend GETs the vapid-public-key route
- **THEN** the controller returns the configured public key with the correct auth posture declared

#### Scenario: A user cannot manage another user's subscription

- **WHEN** an authenticated user POSTs or DELETEs a push subscription
- **THEN** the controller binds the subscription to the current session user only and never accepts an arbitrary target uid (no IDOR)

### Requirement: Always-loaded subscribe client and Service Worker

OpenRegister SHALL register an always-loaded frontend script via `BeforeTemplateRenderedEvent` so the subscribe client loads on every Nextcloud page (not only openregister pages). The client SHALL be opt-in only — it SHALL NEVER prompt for push permission on page load; subscription SHALL occur only after an explicit user gesture or settings toggle, calling `pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: <VAPID public> })` and POSTing the result. A Service Worker served at a registrable scope SHALL handle the `push` event by calling `registration.showNotification(title, { body, icon, badge, actions, tag, data })` and the `notificationclick` event by focusing-or-opening the resolved deeplink for both the top-level click and each per-action click.

#### Scenario: No permission prompt on page load

- **WHEN** any Nextcloud page loads for a user who has not opted in
- **THEN** no push-permission prompt is shown; subscription is attempted only after an explicit gesture or settings-toggle opt-in

#### Scenario: Service Worker renders a background push

- **WHEN** the Service Worker receives a `push` event while all Nextcloud tabs are closed
- **THEN** it calls `registration.showNotification` with the payload's title, body, icon, badge, actions, tag, and data

#### Scenario: notificationclick opens the action's deeplink

- **WHEN** the user clicks a declared action button on a background notification
- **THEN** the Service Worker's `notificationclick` handler focuses an existing client or opens the deeplink resolved for that specific action

### Requirement: Hex icon raster endpoint

OpenRegister SHALL serve, per `originApp`, a cached PNG icon composed of the app's white monochrome `img/app.svg` glyph composited onto the Conduction cobalt hexagon (`#21468B`, from `design-system/brand/assets/hexes/hex-cobalt.svg`) via Imagick or the Nextcloud image abstraction, plus a monochrome glyph badge. The rendered PNG SHALL be cached and keyed by appId.

#### Scenario: Icon keyed by originApp

- **WHEN** the hex-icon endpoint is requested for `originApp=pipelinq`
- **THEN** it returns a cached PNG showing pipelinq's white glyph on the cobalt hexagon, plus a monochrome badge, keyed by `pipelinq`

#### Scenario: Render is cached

- **WHEN** the same originApp icon is requested twice
- **THEN** the second request is served from cache rather than recomposited

### Requirement: Browser-support degradation

Chrome, Edge, and Firefox SHALL receive full Web Push including background delivery. Safari SHALL receive Web Push only when running as an installed PWA; otherwise the recipient SHALL fall back to the foreground `nc-notification` popup. The engine SHALL never assume background delivery is available without an active subscription.

#### Scenario: Safari without installed PWA falls back

- **WHEN** a `web-push` rule targets a Safari recipient that is not an installed PWA (no active push subscription)
- **THEN** background push is not attempted and the recipient receives the foreground `nc-notification` popup instead

### Requirement: Admin and user settings

OpenRegister SHALL provide an admin settings panel showing VAPID status, the public key, and a browser-push-enabled flag (extending `src/views/settings/sections/PushNotificationsConfiguration.vue`), and a per-user opt-in "Enable browser notifications" toggle that drives the permission request and subscription.

#### Scenario: Admin views VAPID status

- **WHEN** an administrator opens the push-notifications admin panel
- **THEN** it shows whether a VAPID keypair exists, the public key, and the enabled flag

#### Scenario: User opts in via the toggle

- **WHEN** a user enables the "Enable browser notifications" toggle
- **THEN** the client requests push permission (gesture-driven), subscribes, and POSTs the subscription; disabling the toggle DELETEs it
