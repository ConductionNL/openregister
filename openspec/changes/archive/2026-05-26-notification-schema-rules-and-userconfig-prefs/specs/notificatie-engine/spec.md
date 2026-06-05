## ADDED Requirements

### Requirement: Notification rules MUST be sourced ONLY from the schema annotation, evaluated at dispatch time
The system MUST treat `configuration['x-openregister-notifications']` on the `Schema` as the single, authoritative source of notification rules. Because the schema is ALWAYS loaded whenever anything happens to an object, the dispatcher MUST evaluate the notification rules directly from the already-loaded schema annotation at dispatch time. The system MUST NOT persist notification rules in any separate rule table, and MUST NOT introduce a `NotificationRule` entity or `oc_openregister_notification_rules` table. (ADR-031: `x-openregister-notifications` is the declarative replacement for app-local notification service code.)

#### Scenario: Dispatcher reads rules from the loaded schema, not a rule table
- **WHEN** an object lifecycle event fires for an object whose schema declares an `x-openregister-notifications` rule on `object.created`
- **THEN** the dispatcher MUST evaluate that rule from the schema annotation already loaded for the object
- **AND** the system MUST NOT query any notification-rule table (none exists)

#### Scenario: Editing the schema annotation changes dispatch behaviour immediately
- **WHEN** an administrator updates `x-openregister-notifications` on a schema to add a new `object.updated` rule and saves the schema
- **THEN** the next `object.updated` event on that schema MUST be evaluated against the new rule
- **AND** no rule-table row creation, migration, or rebuild step is required for the change to take effect

#### Scenario: No rule persistence layer for in-app/push channels
- **WHEN** a schema declares an in-app (`nc-notification`) or `push` channel rule
- **THEN** the rule MUST NOT be materialised into any persistent rule/subscription row for those channels
- **AND** the only persistent entity the annotation may materialise remains the `Webhook` entity for `webhook`-channel rules (the existing `NotificationsAnnotationInstaller` behaviour, unchanged)

### Requirement: User notification preferences MUST be override-only values stored in Nextcloud per-user app config
The system MUST store a user's notification preferences as per-user app-config values under the `openregister` app via `OCP\IConfig::setUserValue` (or `OCP\IUserConfig`). A stored user value MUST act ONLY as an override that flips the schema-declared default (on/off, and optionally channel) for a single `(schema, notification-key)` pair. The system MUST NOT introduce a `NotificationPreference` table or rely on a `NotificationSubscription` table for preference resolution.

#### Scenario: Stored override flips the schema default off
- **GIVEN** schema `meldingen` declares notification key `object_created` with default `enabled: true`
- **AND** user `behandelaar-1` has stored an override for `(meldingen, object_created)` of `enabled: false`
- **WHEN** a melding object is created and `behandelaar-1` is a resolved recipient
- **THEN** the system MUST NOT deliver the in-app/push notification to `behandelaar-1`

#### Scenario: No stored override means the schema default applies
- **GIVEN** schema `meldingen` declares notification key `object_created` with default `enabled: true`
- **AND** user `behandelaar-2` has NO stored override for `(meldingen, object_created)`
- **WHEN** a melding object is created and `behandelaar-2` is a resolved recipient
- **THEN** the system MUST deliver the in-app/push notification to `behandelaar-2` using the schema default

#### Scenario: Preferences are isolated per user
- **GIVEN** user `behandelaar-1` has an override turning `(meldingen, object_created)` off
- **WHEN** the override is read for user `behandelaar-2`
- **THEN** the system MUST return the schema default for `behandelaar-2` (no value), unaffected by `behandelaar-1`'s override

### Requirement: Unknown (schema, notification-key) pairs MUST fall through to the schema default with zero migration
The preference-resolution layer MUST be tolerant of unknown keys: adding a NEW schema, or adding a NEW notification to an EXISTING schema, MUST keep working without any migration, data backfill, or rebuild. Any `(schema, notification-key)` pair for which no user override exists MUST resolve to the schema-declared default. The system MUST NOT require a per-user row, table, or migration to exist before a notification can be delivered.

#### Scenario: New schema works immediately with no migration
- **GIVEN** a brand-new schema `klachten` is saved with an `x-openregister-notifications` `object_created` default of `enabled: true`
- **AND** no user has any stored override for any `klachten` notification
- **WHEN** a `klachten` object is created
- **THEN** the system MUST deliver the notification to resolved recipients using the schema default
- **AND** no migration or preference-table backfill MUST be required

#### Scenario: New notification added to an existing schema falls through
- **GIVEN** schema `meldingen` already had `object_created` notifications and users had overrides for it
- **WHEN** the schema is updated to ALSO declare an `object_updated` notification with default `enabled: true`
- **AND** users have no override for `(meldingen, object_updated)`
- **THEN** an `object_updated` event MUST deliver to recipients using the new schema default
- **AND** existing `(meldingen, object_created)` overrides MUST remain unaffected

#### Scenario: Reading an unknown key never errors
- **WHEN** the resolver is asked for the effective preference of a `(schema, notification-key)` pair that has no stored override and that the schema may or may not declare
- **THEN** the resolver MUST return the schema default if the schema declares the key
- **AND** MUST return a safe "no notification" result if the schema does not declare the key, without raising an error

### Requirement: The effective-preferences API MUST expose schema-default-merged-with-override reads and single-pair override writes
The system MUST expose an API for the per-user settings pane: a GET endpoint returning the EFFECTIVE notifications for the current user (every notification the user's accessible schemas declare, merged with that user's stored overrides), and a PUT endpoint that records or clears a single `(schema, notification-key)` override. The API MUST be authenticated as the current Nextcloud user and MUST only read/write that user's own overrides.

#### Scenario: GET returns merged effective preferences
- **GIVEN** schema `meldingen` declares `object_created` (default on) and `object_updated` (default off)
- **AND** the current user has an override turning `object_created` off
- **WHEN** the user calls the effective-preferences GET endpoint
- **THEN** the response MUST list `(meldingen, object_created)` as effectively `off` (from the override)
- **AND** MUST list `(meldingen, object_updated)` as effectively `off` (from the schema default)
- **AND** MUST indicate, per entry, whether the effective value came from the schema default or a user override

#### Scenario: PUT records a single-pair override
- **WHEN** the current user calls the override PUT endpoint for `(meldingen, object_created)` with `enabled: false`
- **THEN** the system MUST persist that override under the `openregister` app user-config for the current user only
- **AND** a subsequent effective-preferences GET MUST reflect the override

#### Scenario: PUT clearing an override restores the schema default
- **GIVEN** the current user has an override for `(meldingen, object_created)`
- **WHEN** the user calls the override PUT endpoint to clear/reset that pair
- **THEN** the stored user-config value MUST be removed
- **AND** a subsequent effective-preferences GET MUST show the schema default for that pair

#### Scenario: A user cannot read or write another user's overrides
- **WHEN** an authenticated user calls the effective-preferences GET or override PUT endpoint
- **THEN** the system MUST scope all reads and writes to the authenticated user's own app-config values

### Requirement: The dispatcher MUST consult the merged preference before delivering the in-app/push channel
Before delivering the in-app (`nc-notification`) or `push` channel to a given recipient, the dispatcher MUST resolve the effective preference for that recipient as `schema-default ⊕ user-override` and MUST skip the recipient when the effective preference is off. The dispatcher MUST evaluate this per recipient so that one recipient's override never affects another's delivery.

#### Scenario: Recipient with an off override is skipped
- **GIVEN** a schema rule resolves recipients `jan` and `piet` for an `object.created` in-app notification
- **AND** `jan` has an override turning that notification off
- **WHEN** the dispatcher fans out the in-app channel
- **THEN** `jan` MUST NOT receive the notification
- **AND** `piet` MUST receive it (no override → schema default applies)

#### Scenario: Channel override narrows delivery when supported
- **GIVEN** a schema rule declares both `nc-notification` and `push` channels for a recipient
- **AND** the recipient's override specifies the notification is on but only for the in-app channel
- **WHEN** the dispatcher fans out
- **THEN** the in-app notification MUST be delivered
- **AND** the push channel MUST be suppressed for that recipient
- **AND** if the override specifies on/off only (no channel), both declared channels MUST follow the on/off value

## MODIFIED Requirements

### Requirement: The system MUST integrate with Nextcloud's INotificationManager for in-app notifications
All notification delivery to Nextcloud users MUST go through Nextcloud's native `OCP\Notification\IManager` interface. The existing `Notifier` class (implementing `INotifier`) MUST be extended so that `prepare()` renders the object-lifecycle subjects declared by `x-openregister-notifications` — at minimum `object_created`, `object_updated`, and an assignment/transition subject — in addition to the existing `configuration_update_available`. Each rendered subject MUST be internationalised in Dutch (nl) and English (en) via `IFactory::get('openregister', <languageCode>)` and MUST carry a primary action link to the object detail view. Push delivery is achieved by `notify_push` auto-intercepting the same `IManager` notification — no separate push code is required; the `push` channel is declared, not coded.

#### Scenario: Deliver object creation notification via INotificationManager
- GIVEN a schema-declared `x-openregister-notifications` rule targeting channel `nc-notification` for schema `meldingen` on event `object.created`
- AND user `behandelaar-1` is a member of the recipient group `kcc-team`
- AND `behandelaar-1` has no override for `(meldingen, object_created)`
- WHEN a new melding object is created with title `Overlast Binnenstad`
- THEN the system MUST call `IManager::notify()` with an `INotification` where:
  - `app` = `openregister`
  - `user` = `behandelaar-1`
  - `subject` = `object_created` with parameters including register, schema, object UUID, and object title
  - `object` type = `register_object`, id = the object's database ID
- AND the notification MUST appear in the Nextcloud notification bell within 2 seconds
- AND clicking the notification MUST navigate to `/apps/openregister/#/registers/{registerId}/schemas/{schemaId}/objects/{objectUuid}`

#### Scenario: Notifier renders object_created with nl i18n and action link
- GIVEN the Notifier receives an `INotification` with subject `object_created` and `languageCode` = `nl`
- WHEN `Notifier::prepare()` is called
- THEN it MUST use `IFactory::get('openregister', 'nl')` to load Dutch translations
- AND the parsed subject MUST read the Dutch object-created string with the object title and register name substituted (e.g. `Object "%s" aangemaakt in register "%s"`)
- AND it MUST add a primary action labelled `Bekijken` linking to the object detail view (`openregister.dashboard.page` with fragment `#/registers/{registerId}/schemas/{schemaId}/objects/{objectUuid}`), request type `GET`
- AND the notification icon MUST be set via `IURLGenerator::imagePath('openregister', ...)`

#### Scenario: Notifier renders object_updated with en i18n and action link
- GIVEN the Notifier receives an `INotification` with subject `object_updated` and `languageCode` = `en`
- WHEN `Notifier::prepare()` is called
- THEN it MUST use `IFactory::get('openregister', 'en')` to load English translations
- AND the parsed subject MUST read the English object-updated string (e.g. `Object "%s" updated in register "%s"`) with the title and register name substituted
- AND it MUST add a primary action labelled `View` linking to the object detail view

#### Scenario: Notifier renders the assignment/transition subject
- GIVEN the Notifier receives an `INotification` for an assignment/transition subject (e.g. an object's `assignedTo` changed) with a `languageCode`
- WHEN `Notifier::prepare()` is called
- THEN it MUST render a localised subject naming the affected object and the assignment/transition in the recipient's language (nl or en)
- AND it MUST add a primary action linking to the object detail view

#### Scenario: Unknown subject is left unhandled safely
- GIVEN the Notifier receives an `INotification` whose subject is not one it renders
- WHEN `Notifier::prepare()` is called
- THEN it MUST raise `\InvalidArgumentException` (the documented INotifier contract for unknown subjects)
- AND delivery of other notifications MUST be unaffected

### Requirement: Users MUST be able to manage their notification preferences
Users MUST be able to turn specific schema-declared notifications on or off (and optionally select channels) via a personal settings interface, without affecting other users' preferences. Preferences MUST be stored as override-only values in Nextcloud per-user app config under the `openregister` app (NOT in a `NotificationPreference` or `NotificationSubscription` table). When a user has no override for a `(schema, notification-key)` pair, the schema-declared default applies.

#### Scenario: User disables a specific notification
- GIVEN schema `meldingen` declares an `object_created` notification (default on) to group `behandelaars`
- AND user `jan` is a member of `behandelaars`
- WHEN `jan` turns off `(meldingen, object_created)` via the override PUT endpoint
- THEN `jan` MUST NOT receive that notification
- AND other members of `behandelaars` MUST be unaffected

#### Scenario: Retrieve effective user notification preferences
- GIVEN user `jan` has customised 2 of the notifications his accessible schemas declare
- WHEN `jan` calls the effective-preferences GET endpoint
- THEN the response MUST list every declared notification for his accessible schemas with its effective on/off (and channel) value
- AND for the 2 customised entries the effective value MUST reflect his overrides, with the remainder showing the schema defaults
- AND each entry MUST indicate whether its value came from the schema default or a user override

#### Scenario: User with no overrides sees all schema defaults
- GIVEN user `piet` has never set any override
- WHEN `piet` calls the effective-preferences GET endpoint
- THEN every entry MUST reflect the schema-declared default
- AND no per-user row or migration MUST be required for the read to succeed

## REMOVED Requirements

### Requirement: Notifications MUST support per-register and per-schema channel subscriptions
**Reason**: Superseded by the override-only model. The `NotificationSubscription` entity/table + `NotificationSubscriptionsController` implemented a per-user subscribe/unsubscribe store, which contradicts the constraint that rules live only in the schema annotation and preferences are override-only Nextcloud user-config values. Per-schema channel defaults are now declared in `x-openregister-notifications`; per-user behaviour is an override-only user-config value.
**Migration**: Deprecate `NotificationSubscriptionsController` (mark `@deprecated`, keep responding during the deprecation window). Migrate any existing `NotificationSubscription` rows into equivalent `(schema, notification-key)` user-config overrides via a one-shot repair/migration step, then schedule removal of the table, mapper, and controller. (Deprecate-vs-hard-remove is recorded as a DEFERRED_QUESTION; provisional decision is deprecate-then-remove.)
