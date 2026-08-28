---
kind: code
depends_on: []
chain:
  - notification-schema-rules-and-userconfig-prefs   # this spec (OpenRegister engine)
  # downstream, in separate repos (NOT Hydra-chained here, narrated below):
  #   nextcloud-vue  — user-settings notification preferences pane
  #   pipelinq       — schema x-openregister-notifications declarations (new lead/contact)
  #   procest        — schema x-openregister-notifications declarations (case assigned)
---

# Notification Schema Rules and User-Config Preferences

## Why

The `notificatie-engine` spec already declares that schema-annotated notifications drive in-app delivery, and `x-openregister-notifications` is already an accepted annotation key — but the dispatch path is half-wired. `NotificationsAnnotationInstaller` only materialises the `webhook` channel, and `Notifier::prepare()` only renders `configuration_update_available`. So a schema that declares an `object.created` in-app notification today produces nothing in the bell. At the same time the engine spec proposed `NotificationRule`, `NotificationPreference`, and `NotificationSubscription` tables — persistence layers that contradict ADR-031's declarative-first principle (the schema is the single source of truth) and add migration burden every time an app adds a schema or a notification.

This change corrects the engine to honour two hard architectural constraints: **notification rules live ONLY in the schema annotation** (evaluated directly from the always-loaded schema at dispatch time, no rule table), and **user preferences are override-only values in Nextcloud's per-user app config** (no preference/subscription table), so new schemas and new notifications keep working with zero migration.

## What Changes

- **Dispatch in-app/push directly from the schema annotation.** The dispatcher MUST read `configuration['x-openregister-notifications']` off the already-loaded `Schema` at the moment an object lifecycle event fires and fan out the `nc-notification` / `push` channels through `OCP\Notification\IManager`. No `NotificationRule` table; no rule-persistence layer. (The existing webhook-materialisation path in `NotificationsAnnotationInstaller` stays — webhooks are persistent `Webhook` entities by design; only the in-app/push gap is closed.)
- **Render object-lifecycle subjects in `Notifier::prepare()`.** Extend the current single-subject switch to handle `object_created`, `object_updated`, and an assignment/transition subject, with nl+en i18n via `IFactory` and a primary action link to the object detail view. Reuse the subject/param/route shape already specified in the `notificatie-engine` spec scenarios.
- **User preferences as override-only Nextcloud user-config.** Store per-user overrides via `IConfig::setUserValue` under app `openregister`. A stored value only flips the schema-declared default (on/off, and optionally channel) for one `(schema, notification-key)` pair. When NO override exists, the schema default applies — unknown keys fall through. **No `NotificationPreference` table.**
- **Effective-preferences read/write API.** Add OR endpoints to GET the *effective* notifications for a user (schema defaults merged with that user's overrides) and to PUT a single `(schema, notification-key)` override. This is the contract the nextcloud-vue settings pane consumes.
- **Dispatcher consults the merged preference before delivery.** Before delivering the in-app/push channel to a given recipient, the dispatcher MUST resolve `schema-default ⊕ user-override` and skip recipients whose effective preference is off.
- **Deprecate the `NotificationSubscription` table + controller.** The existing per-user subscribe/unsubscribe table contradicts the override-only model. **BREAKING** (internal API): mark `NotificationSubscriptionsController` deprecated, migrate any existing rows into user-config overrides, and schedule removal. (See DEFERRED_QUESTIONS — deprecate-vs-hard-remove is a human-judgment call; provisional decision is deprecate-then-remove.)

## Capabilities

### New Capabilities
<!-- None. This change tightens the existing notificatie-engine spec rather than introducing a distinct capability. -->

### Modified Capabilities
- `notificatie-engine`: The schema-annotation rule source becomes the ONLY rule store (drop the proposed `NotificationRule` table); user preferences become override-only Nextcloud user-config values (drop the proposed `NotificationPreference`/`NotificationSubscription` tables). New requirements: the zero-migration fall-through invariant; `Notifier::prepare()` rendering of object-lifecycle subjects with nl+en i18n + action link; the effective-preferences read/write API; the merged-preference delivery gate.

## Impact

- **Code (OpenRegister):**
  - `lib/Notification/Notifier.php` — extend `prepare()` with `object_created` / `object_updated` / assignment subjects, nl+en i18n, object-detail action link.
  - `lib/Service/Notification/NotificationsAnnotationInstaller.php` — adjacent dispatcher path: read in-app/push channels from the annotation at dispatch time (today it only materialises webhooks at save time).
  - A dispatcher seam (`AnnotationNotificationDispatcher` per the engine spec) that evaluates schema rules + the merged user preference and calls `IManager::notify()`.
  - A user-config-backed preferences service + controller (effective-GET, override-PUT) under app `openregister`.
  - `lib/Db/NotificationSubscription.php`, `lib/Db/NotificationSubscriptionMapper.php`, `lib/Controller/NotificationSubscriptionsController.php` — deprecate; add a one-shot migration of existing rows to user-config.
- **No new OR schemas / no new DB tables** introduced by this change (it is engine code). Push works via `notify_push` auto-interception of `IManager` — no separate push code.
- **APIs:** new effective-preferences GET + override PUT endpoints (consumed by the nc-vue pane). `NotificationSubscriptions*` endpoints become deprecated.
- **Dependent / linked changes (separate repos — NOT tasks here):**
  1. This OpenRegister engine change (this spec) lands the dispatch + API.
  2. **nextcloud-vue** — replace the `<p>User preferences will appear here.</p>` placeholder in `src/components/CnAppRoot/CnAppRoot.vue` (~line 217) with a preferences pane that calls the new OR effective-GET / override-PUT API.
  3. **pipelinq / procest** — add `x-openregister-notifications` default declarations to their schemas (pipelinq: new lead/contact → notify sales group on `object.created`; procest: case assigned → notify `object.assignedTo` on assignee change). These are config changes in those repos.
