## 1. Dispatch in-app/push from the schema annotation

- [x] 1.1 Add an `AnnotationNotificationDispatcher` seam that, on an object lifecycle event, reads `configuration['x-openregister-notifications']` off the already-loaded `Schema` and selects rules matching the event (no rule table, no persistence).
- [x] 1.2 Resolve recipients for a matching rule (groups / `field` / users per the rule shape) and fan out the `nc-notification` and `push` channels via `OCP\Notification\IManager` (push needs no extra code — `notify_push` intercepts `IManager`).
- [x] 1.3 Leave the existing `NotificationsAnnotationInstaller` webhook-materialisation path unchanged; ensure unimplemented advanced rule fields (digest/threshold/rate-limit) are ignored gracefully, not errored.

## 2. Render object-lifecycle subjects in Notifier

- [x] 2.1 Extend `lib/Notification/Notifier.php::prepare()` with cases for `object_created`, `object_updated`, and an assignment/transition subject, keeping unknown subjects raising `\InvalidArgumentException`.
- [x] 2.2 Localise each subject in nl + en via `IFactory::get('openregister', <languageCode>)`; add the matching nl/en translation strings.
- [x] 2.3 Add a primary action (`Bekijken` / `View`, request type GET) linking to the object detail view route, and set the OR app icon via `IURLGenerator::imagePath`.

## 3. Override-only user-config preferences API

- [x] 3.1 Add a preferences service that resolves `effective = user-override ?? schema-default` for a `(schema, notification-key)` pair, reading overrides via `IConfig::getUserValue` under app `openregister` (key shape per design.md), returning the schema default for unknown keys with no error.
- [x] 3.2 Add a controller GET endpoint returning the effective notifications for the current user (every declared notification on accessible schemas merged with the user's overrides), tagging each entry as `schema-default` or `user-override`; scope strictly to the authenticated user.
- [x] 3.3 Add a controller PUT endpoint that records a single `(schema, notification-key)` override (on/off, optional `channels`) and clears the stored value when reset; register both routes in `appinfo/routes.php` with correct auth attributes.

## 4. Gate delivery on the merged preference

- [x] 4.1 In the dispatcher, before delivering the `nc-notification`/`push` channel to each recipient, resolve `schema-default ⊕ user-override` per recipient and skip recipients whose effective value is off; apply optional channel-narrowing when present.

## 5. Deprecate the NotificationSubscription layer

- [x] 5.1 Mark `NotificationSubscriptionsController` (and the entity/mapper) `@deprecated` and add a one-shot repair/migration translating existing `NotificationSubscription` rows into equivalent user-config overrides (idempotent; original rows left in place during the deprecation window).

## 6. Document shapes and verify

- [x] 6.1 Document the canonical `x-openregister-notifications` rule shape and the user-config override key shape (from design.md) in the app docs / code docblocks using SAFE placeholders (nil UUID, `YOUR_*_HERE`); no new OR schema or seed-data file is added.
- [x] 6.2 Add tests covering the zero-migration fall-through invariant, the merged-preference delivery gate (per-recipient isolation), and nl/en Notifier rendering with action links.

## Acceptance criteria

- Notification rules are read only from `x-openregister-notifications` at dispatch time; no `NotificationRule` table or entity exists.
- A schema-declared `object.created` in-app/push notification reaches the recipient's Nextcloud bell and renders localised (nl/en) with a working object-detail action link.
- User preferences are override-only Nextcloud user-config values under app `openregister`; a stored override flips only the schema default for one `(schema, notification-key)` pair; absence of an override falls through to the schema default.
- Adding a new schema, or a new notification to an existing schema, delivers correctly with zero migration / backfill.
- The effective-GET endpoint returns schema-defaults merged with the current user's overrides (tagged by source); the override-PUT writes/clears a single pair, scoped to the authenticated user only.
- The dispatcher skips recipients whose effective preference is off, per recipient, without affecting other recipients.
- `NotificationSubscriptionsController`/entity/mapper are deprecated and existing rows are migrated to user-config overrides.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- All new PHP files carry the SPDX-License-Identifier + SPDX-FileCopyrightText inside the main docblock (EUPL-1.2).
- All framework interactions use OCP interfaces (`IManager`, `INotifier`, `IFactory`, `IConfig`, `IURLGenerator`).
- nl + en translation strings exist for every new Notifier subject and action label.
- No new DB tables or OR schemas introduced; push relies on `notify_push` interception (no bespoke push code).
