---
status: partial
---

# Notificatie Engine

## Purpose

@e2e exclude backend notification delivery/rules engine — covered by PHPUnit
Extend OpenRegister's existing CloudEvent-based event system with user-facing notification delivery. This is NOT a standalone engine — it builds on the event-driven-architecture spec's events and the webhook-payload-mapping spec's delivery infrastructure, adding Nextcloud INotificationManager integration, user preferences, and delivery channels. The existing WebhookService already handles outbound webhook delivery with HMAC signing, CloudEvents formatting, and Mapping-based payload transformation. The existing Notifier class already implements INotifier for in-app notifications. The existing WebhookEventListener already listens for 55+ object/register/schema/configuration lifecycle events. This spec extends that foundation with configurable notification rules per schema, template-based message formatting, recipient resolution, batching/digest delivery, user preference management, and VNG Notificaties API compliance for Dutch government interoperability.

**Tender demand**: 51% of analyzed government tenders require notification capabilities.

## Relationship to Existing Implementation
This spec is an extension of existing infrastructure, not a greenfield build:

- **Event system (implemented)**: `WebhookEventListener` already captures 55+ lifecycle events across Objects, Registers, Schemas, Configurations, Applications, Agents, Sources, Views, Conversations, and Organisations. The notification engine subscribes to these same events — it does not introduce a new event bus.
- **In-app notifications (partially implemented)**: `NotificationService` and `Notifier` already integrate with Nextcloud's `IManager`/`INotifier`. Currently limited to `configuration_update_available` — this spec extends `Notifier::prepare()` to handle `object_created`, `object_updated`, `object_deleted`, `threshold_alert`, `workflow_completed`, and `digest` subjects.
- **Webhook delivery (implemented)**: `WebhookService` with `CloudEventFormatter`, `WebhookDeliveryJob`, and `WebhookRetryJob` already provides the complete webhook delivery pipeline. Notification rules that target the `webhook` channel delegate to this existing infrastructure.
- **Payload transformation (implemented)**: `MappingService::executeMapping()` with Twig templates already enables format-agnostic payload transformation. VNG Notificaties format is achieved through Mapping configuration, not hardcoded logic.
- **Multi-tenancy (implemented)**: Webhook entities already support organisation scoping via the `organisation` field and `MultiTenancyTrait`. Notification rules inherit this isolation.
- **What this spec adds**: NotificationRule entity, NotificationPreference entity, NotificationHistory entity, digest/batching mechanism, user opt-in/opt-out, rate limiting, threshold/deadline/workflow triggers, and read/unread tracking.
## Requirements
### Requirement: The system MUST integrate with Nextcloud's INotificationManager for in-app notifications
All notification delivery to Nextcloud users MUST go through Nextcloud's native `OCP\Notification\IManager` interface. The object-lifecycle subjects declared by `x-openregister-notifications` — at minimum `object_created`, `object_updated`, and an assignment/transition subject (`object_transitioned`) — MUST be rendered by a registered `INotifier`. In OpenRegister this rendering lives in `AnnotationNotifier` (registered via `registerNotifierService`), which owns those subjects plus anything carrying a pre-rendered `_text` parameter; `Notifier` (registered via `appinfo/info.xml`) continues to own `configuration_update_available`. The two notifiers are mutually exclusive by subject so Nextcloud's sequential `Manager::prepare()` never double-renders. Each object subject MUST be internationalised in Dutch (nl) and English (en) via `IFactory::get('openregister', <languageCode>)` and MUST carry a primary action link to the object detail view. Push delivery is achieved by `notify_push` auto-intercepting the same `IManager` notification — the `push` channel is declared, not coded.

#### Scenario: Deliver object creation notification via INotificationManager
- GIVEN a notification rule targeting channel `in-app` for schema `meldingen` on event `object.created`
- AND user `behandelaar-1` is a member of the recipient group `kcc-team`
- WHEN a new melding object is created with title `Overlast Binnenstad`
- THEN the system MUST call `IManager::notify()` with an `INotification` where:
  - `app` = `openregister`
  - `user` = `behandelaar-1`
  - `subject` = `object_created` with parameters including register, schema, object UUID, and object title
  - `object` type = `register_object`, id = the object's database ID
- AND the notification MUST appear in the Nextcloud notification bell within 2 seconds
- AND clicking the notification MUST navigate to `/apps/openregister/#/registers/{registerId}/schemas/{schemaId}/objects/{objectUuid}`

#### Scenario: Dismiss notifications when object is deleted
- GIVEN user `behandelaar-1` has 3 unread notifications for object `melding-5`
- WHEN `melding-5` is deleted
- THEN the system MUST call `IManager::markProcessed()` for all notifications with object type `register_object` and id matching `melding-5`
- AND those notifications MUST disappear from the user's notification panel

#### Scenario: AnnotationNotifier renders object_created with nl i18n and action link
- GIVEN `AnnotationNotifier` receives an `INotification` with subject `object_created` and `languageCode` = `nl`
- WHEN `prepare()` is called
- THEN it MUST use `IFactory::get('openregister', 'nl')` to load Dutch translations
- AND the parsed subject MUST read the schema's custom per-locale subject when declared, otherwise the canonical Dutch object-created string with the object title and register name substituted
- AND it MUST add a primary action labelled `Bekijken` linking to the absolute route `openregister.dashboard.page` with fragment `#/registers/{registerId}/schemas/{schemaId}/objects/{objectUuid}`, request type `GET`
- AND the notification icon MUST be set via `IURLGenerator::imagePath('openregister', ...)`

#### Scenario: AnnotationNotifier renders object_updated with en i18n
- GIVEN `AnnotationNotifier` receives an `INotification` with subject `object_updated` and `languageCode` = `en`
- WHEN `prepare()` is called
- THEN the parsed subject MUST read the English object-updated string with the title and register name substituted
- AND it MUST add a primary action labelled `View` linking to the object detail view

#### Scenario: AnnotationNotifier declines subjects it does not own
- GIVEN `AnnotationNotifier` receives an `INotification` whose subject it does not own (no `_text` and not a canonical object subject — e.g. `configuration_update_available`)
- WHEN `prepare()` is called
- THEN it MUST raise `UnknownNotificationException` so the manager passes the notification on to `Notifier` untouched

### Requirement: The system MUST support configurable notification rules per schema
Administrators MUST be able to define notification rules that specify which events on which schemas trigger notifications, to which recipients, via which channels, using which message template.

#### Scenario: Create a notification rule for object creation
- GIVEN schema `meldingen` (ID 12) in register `zaken` (ID 5)
- WHEN the admin creates a notification rule via the API:
  - `event`: `object.created`
  - `schema`: `12`
  - `register`: `5`
  - `channels`: `["in-app", "webhook"]`
  - `recipients`: `{"groups": ["kcc-team"], "users": ["supervisor-1"]}`
  - `template`: `Nieuwe melding: {{object.title}} aangemaakt door {{user.displayName}}`
- THEN the rule MUST be persisted in the `oc_openregister_notification_rules` table
- AND creating a new melding object MUST trigger notifications on all specified channels to all resolved recipients

#### Scenario: Configure notification on field value change with condition
- GIVEN schema `vergunningen` with property `status`
- WHEN the admin creates a rule:
  - `event`: `object.updated`
  - `condition`: `{"field": "status", "operator": "changed"}`
  - `channels`: `["in-app"]`
  - `recipients`: `{"dynamic": "object.assignedTo"}`
- THEN updating a vergunning's status from `nieuw` to `in_behandeling` MUST trigger an in-app notification to the user referenced in `object.assignedTo`
- AND updating a vergunning's `description` without changing `status` MUST NOT trigger this rule

#### Scenario: Notification rule with multiple conditions (AND logic)
- GIVEN a notification rule with conditions:
  - `{"field": "status", "operator": "equals", "value": "afgehandeld"}`
  - `{"field": "priority", "operator": "equals", "value": "hoog"}`
- WHEN an object is updated to `status=afgehandeld` and `priority=hoog`
- THEN the notification MUST fire
- AND if only `status=afgehandeld` but `priority=laag`, the notification MUST NOT fire

#### Scenario: Disable and re-enable a notification rule
- GIVEN an active notification rule with ID 7
- WHEN the admin sets `enabled` = `false` on rule 7
- THEN no notifications MUST be sent for events matching rule 7
- AND when the admin sets `enabled` = `true` again, notifications MUST resume

#### Scenario: Delete a notification rule
- GIVEN notification rule ID 7 exists
- WHEN the admin deletes rule 7
- THEN the rule MUST be removed from the database
- AND pending notifications for rule 7 that have not yet been delivered MUST be cancelled

### Requirement: The system MUST support multiple notification channels
Notifications MUST be deliverable via Nextcloud in-app notifications, push notifications (via notify_push), email (via n8n workflow), and outbound webhooks. Each channel MUST be independently configurable per rule.

#### Scenario: Deliver in-app notification
- GIVEN a notification rule with channel `in-app` and recipient user `behandelaar-1`
- WHEN the triggering event occurs
- THEN a Nextcloud notification MUST appear in the user's notification panel via `INotificationManager::notify()`
- AND clicking the notification MUST navigate to the object detail view

#### Scenario: Deliver push notification via notify_push
- GIVEN a notification rule with channel `push` and recipient user `medewerker-1`
- AND the Nextcloud `notify_push` app is installed and running
- WHEN the triggering event occurs
- THEN the system MUST create an `INotification` via `INotificationManager` (which notify_push automatically intercepts)
- AND the push notification MUST be delivered to the user's connected devices within 5 seconds
- AND if notify_push is not installed, the notification MUST still be delivered as a standard in-app notification

#### Scenario: Deliver email notification via n8n workflow
- GIVEN a notification rule with channel `email` and recipient `user@example.nl`
- AND an n8n workflow `notification-email-sender` is configured as the email delivery handler
- WHEN the triggering event occurs
- THEN the system MUST trigger the n8n workflow via webhook with payload containing:
  - `to`: `user@example.nl`
  - `subject`: rendered template subject line
  - `body`: rendered template body (HTML)
  - `objectUrl`: deep link to the object in OpenRegister
- AND the email MUST include a link back to the object in the OpenRegister UI

#### Scenario: Deliver webhook notification
- GIVEN a notification rule with channel `webhook` and URL `https://external-system.example.nl/hooks/intake`
- WHEN the triggering event occurs
- THEN the system MUST delegate to the existing `WebhookService::deliverWebhook()` with a payload containing:
  - `event`: the event type (e.g., `object.created`)
  - `object`: the full object data
  - `changed`: the changed fields (for updates)
  - `timestamp`: ISO 8601 timestamp
  - `register` and `schema` identifiers
- AND the webhook MUST include an `X-Webhook-Signature` HMAC-SHA256 header if a secret is configured

#### Scenario: Channel-specific failure isolation
- GIVEN a notification rule with channels `["in-app", "email", "webhook"]`
- AND the webhook endpoint returns HTTP 503
- WHEN the triggering event occurs
- THEN the in-app notification MUST still be delivered successfully
- AND the email MUST still be delivered successfully
- AND the webhook failure MUST be logged and retried independently

### Requirement: Notification templates MUST support variable substitution with Twig
Templates MUST support referencing object properties, user properties, event metadata, register/schema metadata, and computed values using Twig template syntax, consistent with the existing `MappingService` Twig integration.

#### Scenario: Render template with object and user properties
- GIVEN a template: `Zaak "{{object.title}}" is gewijzigd door {{user.displayName}}. Nieuwe status: {{object.status}}.`
- AND the object has title `Melding overlast` and status `In behandeling`
- AND the triggering user has displayName `Jan de Vries`
- WHEN the template is rendered via `MappingService` or a dedicated `NotificationTemplateRenderer`
- THEN the output MUST be: `Zaak "Melding overlast" is gewijzigd door Jan de Vries. Nieuwe status: In behandeling.`

#### Scenario: Template with register and schema context
- GIVEN a template: `Nieuw object in register "{{register.name}}", schema "{{schema.name}}": {{object.title}}`
- AND the register name is `Zaakregistratie` and schema name is `Meldingen`
- WHEN the template is rendered
- THEN the output MUST be: `Nieuw object in register "Zaakregistratie", schema "Meldingen": Melding overlast`

#### Scenario: Template with missing property falls back gracefully
- GIVEN a template referencing `{{object.nonExistentField}}`
- WHEN the template is rendered
- THEN the placeholder MUST be replaced with an empty string
- AND the notification MUST still be delivered
- AND a debug-level log entry MUST record the missing variable

#### Scenario: Template with conditional blocks
- GIVEN a template: `{% if object.priority == "hoog" %}URGENT: {% endif %}{{object.title}} gewijzigd`
- AND the object has `priority` = `hoog`
- WHEN the template is rendered
- THEN the output MUST be: `URGENT: Melding overlast gewijzigd`

#### Scenario: Template with date formatting
- GIVEN a template: `Aangemaakt op {{object.created|date("d-m-Y H:i")}}`
- AND the object has `created` = `2026-03-19T14:30:00+01:00`
- WHEN the template is rendered
- THEN the output MUST be: `Aangemaakt op 19-03-2026 14:30`

### Requirement: Notifications MUST support batching and digest delivery

High-frequency events MUST NOT overwhelm recipients with individual notifications. The system MUST support configurable digest windows and batch summaries. In addition to the rolling digest window below, a rule MAY declare a fixed time-of-day digest schedule via a `digest` block (`schedule: "daily"|"weekly"`, `at: "HH:MM"`, optional `timezone` defaulting to the server timezone, optional `weekday: 0-6` required when `schedule: "weekly"`). A rule MUST NOT declare both a rolling digest period and a `digest` schedule block; schema-save validation MUST reject the combination with HTTP 422.

#### Scenario: Batch notifications for bulk import operations
- GIVEN a notification rule on `object.created` for schema `meldingen`
- AND 50 meldingen are created in a single bulk import within 10 seconds
- WHEN the notifications are processed
- THEN the system MUST send a single digest notification: `50 nieuwe meldingen aangemaakt in register "Zaakregistratie"`
- AND the digest MUST include a link to the object list view filtered to the newly created objects

#### Scenario: Throttle notifications per recipient within digest window
- GIVEN a digest window of 5 minutes is configured for a notification rule
- AND recipient `jan` receives 15 events within the window
- WHEN the digest window expires
- THEN a single digest notification MUST be delivered to `jan` summarizing all 15 events
- AND each individual event MUST NOT have generated a separate notification

#### Scenario: Configurable digest period per rule
- GIVEN notification rule A has digest period `0` (immediate) and rule B has digest period `300` (5 minutes)
- WHEN events trigger both rules
- THEN rule A MUST deliver notifications immediately (no batching)
- AND rule B MUST batch notifications within the 5-minute window

#### Scenario: Digest includes per-event summary
- GIVEN a digest window contains 3 created and 2 updated meldingen
- WHEN the digest is delivered
- THEN the digest message MUST include a breakdown: `3 nieuw, 2 gewijzigd`
- AND the digest MUST list the titles of affected objects (up to 10, then `... en 5 meer`)

#### Scenario: Rule declares a daily fixed-time digest schedule
- GIVEN a notification rule on schema `gradeEntry` declares `digest: {schedule: "daily", at: "07:00", timezone: "Europe/Amsterdam"}`
- AND 3 grade-published events fire for recipient `ouder-1` at 14:00, 16:30, and 21:00 the previous day
- WHEN `NotificationQueueFlushJob` ticks after 07:00 Europe/Amsterdam the following morning
- THEN a single digest notification summarizing the 3 events MUST be delivered to `ouder-1`
- AND no individual notification MUST have been delivered before the 07:00 flush

#### Scenario: Weekly digest schedule requires a weekday
- GIVEN a notification rule declares `digest: {schedule: "weekly", at: "08:00"}` with no `weekday`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422 and a structured error identifying the missing `weekday`

#### Scenario: Rolling digest period and fixed digest schedule are mutually exclusive
- GIVEN a notification rule declares both a rolling `digest period: 300` and `digest: {schedule: "daily", at: "07:00"}`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422

### Requirement: Notification delivery MUST be reliable with retry and dead-letter handling
Failed notification deliveries MUST be retried with configurable backoff strategies. Permanently failed notifications MUST be moved to a dead-letter queue for admin inspection.

#### Scenario: Webhook delivery failure and exponential retry
- GIVEN a webhook notification to `https://external.example.nl/hooks` fails with HTTP 503
- WHEN the retry mechanism activates
- THEN the system MUST retry using the webhook's configured `retryPolicy` (exponential, linear, or fixed)
- AND for exponential policy: retry after 2 minutes, then 4 minutes, then 8 minutes
- AND after `maxRetries` failed attempts, the notification MUST be marked as `failed` in the `WebhookLog`

#### Scenario: Dead-letter queue for permanently failed notifications
- GIVEN a webhook notification has exhausted all retries (e.g., 5 attempts over 62 minutes)
- WHEN the final retry fails
- THEN the notification MUST be moved to a dead-letter queue
- AND the admin MUST be able to view failed notifications with: event data, target URL, failure count, last error message, last attempt timestamp
- AND the admin MUST be able to manually retry or dismiss individual dead-letter entries

#### Scenario: In-app notification delivery failure logging
- GIVEN `INotificationManager::notify()` throws an exception for user `broken-user`
- WHEN the error is caught
- THEN the failure MUST be logged with the user ID, notification subject, and exception message
- AND delivery to other recipients MUST continue unaffected

#### Scenario: Retry does not duplicate already-delivered notifications
- GIVEN a notification rule with channels `["in-app", "webhook"]`
- AND the in-app notification succeeds but the webhook fails
- WHEN the webhook is retried
- THEN the in-app notification MUST NOT be re-sent
- AND only the failed webhook delivery MUST be retried

### Requirement: Users MUST be able to manage their notification preferences

Users MUST be able to turn specific schema-declared notifications on or off (and optionally narrow channels) via a personal settings interface, without affecting other users' preferences. Preferences MUST be stored as override-only values in Nextcloud per-user app config under the `openregister` app (NOT in a `NotificationPreference` or `NotificationSubscription` table). When a user has no override for a `(schema, notification-key)` pair, the schema-declared default applies. Independently of the per-`(schema, notification)` override, a user MAY configure a global delivery window (quiet hours) via `NotificationDeliveryWindowService`, exposed through `GET`/`PUT /api/notification-delivery-window`, stored the same override-only way (a distinct per-user app-config key, `notification_delivery_window`) so a user with no configured window keeps today's immediate-delivery behaviour.

#### Scenario: User disables a specific notification
- GIVEN schema `meldingen` declares an `object_created` notification (default on) to group `behandelaars`
- AND user `jan` is a member of `behandelaars`
- WHEN `jan` turns off `(meldingen, object_created)` via `PUT /api/notification-preferences`
- THEN `jan` MUST NOT receive that notification
- AND other members of `behandelaars` MUST be unaffected

#### Scenario: User opts out of all notifications for a schema
- GIVEN multiple notification rules exist for schema `meldingen`
- WHEN user `jan` opts out of all notifications for schema `meldingen`
- THEN `jan` MUST NOT receive any notifications triggered by events on `meldingen` objects
- AND `jan` MUST still receive notifications for other schemas

#### Scenario: User sets global quiet hours and a suppressed notification is queued, not dropped
- GIVEN user `medewerker-1` configures quiet hours from 18:00 to 08:00 (Europe/Amsterdam) via `PUT /api/notification-delivery-window`
- WHEN a non-critical notification event triggers at 22:15 CET
- THEN the notification MUST be persisted as a `QueuedNotification` with reason `quiet-hours` (not dropped)
- AND `NotificationQueueFlushJob` MUST deliver it once 08:00 Europe/Amsterdam is reached (bounded by the job's 60-second tick)
- AND in-app notifications MUST still be stored (but not pushed) during quiet hours

#### Scenario: Admin overrides user preferences for critical notifications
- GIVEN a notification rule marked as `critical` = `true`
- AND user `jan` has opted out of email notifications
- WHEN the critical rule triggers
- THEN `jan` MUST still receive the notification on all channels including email
- AND the notification MUST be visually marked as critical in the notification panel

#### Scenario: Critical rule bypasses quiet hours
- GIVEN user `medewerker-1` has quiet hours configured from 18:00 to 08:00 (Europe/Amsterdam)
- AND a notification rule is declared with `critical: true`
- WHEN the critical rule triggers at 22:15 CET
- THEN the notification MUST be dispatched immediately, NOT queued
- AND the notification MUST still respect the existing preference-off gate (a `critical` rule bypasses quiet-hours queueing only, not the per-`(schema, notification)` on/off override)

#### Scenario: Retrieve effective user notification preferences
- GIVEN user `jan` has customised 2 of the notifications his accessible schemas declare
- WHEN `jan` calls `GET /api/notification-preferences`
- THEN the response MUST list every declared notification for his accessible schemas with its effective on/off (and channel) value
- AND for the 2 customised entries the effective value MUST reflect his overrides, with the remainder showing the schema defaults
- AND each entry MUST indicate whether its value came from the schema default or a user override

#### Scenario: User with no overrides sees all schema defaults
- GIVEN user `piet` has never set any override
- WHEN `piet` calls `GET /api/notification-preferences`
- THEN every entry MUST reflect the schema-declared default
- AND no per-user row or migration MUST be required for the read to succeed

#### Scenario: User with no configured delivery window is never queued for quiet hours
- GIVEN user `piet` has never called `PUT /api/notification-delivery-window`
- WHEN a non-critical notification event triggers for `piet` at any hour
- THEN `GET /api/notification-delivery-window` MUST return `{enabled: false}` (no stored value)
- AND the dispatcher MUST dispatch immediately, exactly as it did before this change

#### Scenario: Retrieve and update the delivery-window preference
- GIVEN user `medewerker-1` has no stored delivery-window preference
- WHEN `medewerker-1` calls `PUT /api/notification-delivery-window` with `{enabled: true, start: "18:00", end: "08:00", timezone: "Europe/Amsterdam"}`
- THEN the value MUST be stored as an override-only per-user app-config value (no migration, no table)
- AND a subsequent `GET /api/notification-delivery-window` MUST return the stored window
- AND a request for another user's window (or an unauthenticated request) MUST be rejected

### Requirement: The system MUST support VNG Notificaties API compliance
For Dutch government interoperability, the notification engine MUST support publishing notifications in the VNG Notificaties API format, enabling integration with ZGW-compatible systems via the Notificatierouteringscomponent (NRC) pattern.

#### Scenario: Publish VNG-compliant notification on object creation
- GIVEN a webhook is configured with a Mapping entity that transforms payloads to VNG Notificaties format
- AND the Mapping template produces:
  ```json
  {
    "kanaal": "{{register.slug}}",
    "hoofdObject": "{{baseUrl}}/api/v1/{{register.slug}}/{{object.uuid}}",
    "resource": "{{schema.slug}}",
    "resourceUrl": "{{baseUrl}}/api/v1/{{schema.slug}}/{{object.uuid}}",
    "actie": "{{action}}",
    "aanmaakdatum": "{{timestamp}}",
    "kenmerken": {}
  }
  ```
- WHEN a new object is created in register `zaken`, schema `zaak`
- THEN the webhook MUST deliver a payload conforming to the VNG Notificaties API schema
- AND the `actie` field MUST be `create`
- AND the `aanmaakdatum` MUST be an ISO 8601 timestamp

#### Scenario: Subscribe external system as NRC abonnement
- GIVEN an external ZGW system registers an abonnement (subscription) via the OpenRegister API:
  - `callbackUrl`: `https://zgw-system.example.nl/api/v1/notificaties`
  - `auth`: bearer token
  - `kanalen`: `[{"naam": "zaken", "filters": {"zaaktype": "https://catalogi.example.nl/zaaktypen/abc"}}]`
- WHEN an object matching the filter is created
- THEN the system MUST POST a VNG Notificaties-compliant payload to the `callbackUrl`
- AND the request MUST include the `Authorization: Bearer <token>` header

#### Scenario: VNG notification via Mapping (no hardcoded format)
- GIVEN OpenRegister has no hardcoded knowledge of the VNG Notificaties format
- WHEN a VNG-compliant notification is needed
- THEN it MUST be achieved entirely through the existing Webhook + Mapping system
- AND the Mapping entity MUST contain the Twig template that transforms the event payload to VNG format
- AND this approach MUST work for any notification format (VNG, FHIR, custom) without code changes

### Requirement: Notifications MUST be scoped to organisations for multi-tenant deployments
In multi-tenant deployments, notifications MUST be scoped to the organisation context. Users MUST only receive notifications for objects belonging to their organisation.

#### Scenario: Organisation-scoped notification delivery
- GIVEN user `jan` belongs to organisation `gemeente-amsterdam`
- AND a notification rule exists for schema `meldingen` with no explicit organisation filter
- WHEN a melding is created in organisation `gemeente-amsterdam` and another in `gemeente-utrecht`
- THEN `jan` MUST receive a notification for the Amsterdam melding
- AND `jan` MUST NOT receive a notification for the Utrecht melding

#### Scenario: Cross-organisation admin notifications
- GIVEN user `admin` has the `admin` group membership and no organisation restriction
- WHEN objects are created across multiple organisations
- THEN `admin` MUST receive notifications for all organisations (unless explicitly filtered)

#### Scenario: Webhook scoped to organisation
- GIVEN a webhook entity has `organisation` = `gemeente-amsterdam`
- WHEN an object event fires in organisation `gemeente-utrecht`
- THEN the webhook MUST NOT be triggered
- AND the webhook MUST only fire for events within `gemeente-amsterdam`

### Requirement: Notification history MUST be stored and queryable for audit purposes
All notifications MUST be logged with delivery status, timestamp, recipient, channel, and associated event data. This history MUST be queryable by administrators for audit and compliance.

#### Scenario: Query notification history by date range
- GIVEN 500 notifications were sent in the last 7 days
- WHEN the admin queries `GET /api/notification-history?from=2026-03-12&to=2026-03-19`
- THEN all matching notification records MUST be returned with: id, rule, event type, recipient, channel, status (delivered/failed/pending), timestamp, object reference
- AND results MUST be paginated (default 50 per page)

#### Scenario: Query notification history by recipient
- GIVEN user `jan` has received 25 notifications in the last month
- WHEN the admin queries `GET /api/notification-history?recipient=jan`
- THEN all 25 notification records for `jan` MUST be returned

#### Scenario: Notification history retention
- GIVEN the system is configured with notification history retention of 90 days
- WHEN the daily cleanup job runs
- THEN notification history records older than 90 days MUST be purged
- AND webhook logs (`WebhookLog`) MUST follow the same retention policy

#### Scenario: Export notification history for compliance
- GIVEN 1000 notifications exist for register `zaken` in the last quarter
- WHEN the admin exports notification history as CSV
- THEN the export MUST include: timestamp, event type, object UUID, recipient, channel, delivery status, rule name

### Requirement: Notification messages MUST support i18n in Dutch and English
All notification messages (subjects, bodies, action labels) MUST be translatable via Nextcloud's `IL10N` system. Dutch (nl) and English (en) MUST be supported as minimum languages.

#### Scenario: Dutch user receives notification in Dutch
- GIVEN user `jan` has Nextcloud language set to `nl`
- WHEN a notification is prepared by the `Notifier`
- THEN the subject MUST be in Dutch, e.g., `Object "Melding overlast" aangemaakt in register "Zaakregistratie"`
- AND action labels MUST be in Dutch, e.g., `Bekijken`

#### Scenario: English user receives notification in English
- GIVEN user `john` has Nextcloud language set to `en`
- WHEN the same notification is prepared
- THEN the subject MUST be in English, e.g., `Object "Melding overlast" created in register "Zaakregistratie"`
- AND action labels MUST be in English, e.g., `View`

#### Scenario: Custom template messages use user's language
- GIVEN a notification rule with templates:
  - `nl`: `Nieuwe melding: {{object.title}} door {{user.displayName}}`
  - `en`: `New report: {{object.title}} by {{user.displayName}}`
- WHEN the notification is rendered for a Dutch-speaking user
- THEN the Dutch template MUST be used
- AND if no template exists for the user's language, the default language (nl) MUST be used

### Requirement: The notification engine MUST support event-driven trigger types beyond CRUD
Notifications MUST be triggerable by workflow events, threshold alerts, scheduled checks, and external triggers in addition to standard object CRUD events.

The `updated` trigger MUST additionally accept an optional non-numeric field-change `condition` block, evaluated against the old-versus-new object data the dispatch already supplies for `calculatedChange`. The block names a single `field` and one `operator`:

- `{"field": "status", "operator": "changed"}` — the rule fires only when the field's value differs between the old and new object data (old ≠ new).
- `{"field": "status", "operator": "equals", "value": "<target>"}` — the rule fires only when the new value equals `value`.
- `{"field": "status", "operator": "equals", "value": "<target>", "from": "<prior>"}` — the optional `from` additionally requires the old value to equal `<prior>`, so the rule fires only on the specific `<prior>` → `<target>` transition.

The evaluator MUST fail closed: when the old-versus-new object data is unavailable in the dispatch context, a `condition`-bearing `updated` rule MUST NOT fire — consistent with the existing `calculatedChange` behaviour. An `updated` rule that declares NO `condition` MUST continue to fire on every update (back-compatible). The non-numeric field-change condition is evaluated by a string-condition evaluator distinct from the existing numeric `calculatedChange` evaluator; numeric `calculatedChange` semantics are unchanged.

The engine MUST additionally be able to dispatch these trigger types for OpenRegister's **own system entities** (`synchronization`, `import`, `schema`, `configuration`, `source`, `agent`, `webhook`, `register`). A system-event bridge MUST route create/update/transition signals from the relevant system entities through the same `AnnotationNotificationListener` → dispatcher path used for stored register objects, populating the old-versus-new object data so the field-change `condition` block is available for system schemas as well. Operational notifications on system schemas MUST reuse the existing channels, recipient resolvers, rate-limiting, coalescing, per-user preference overrides, and bilingual (nl/en) i18n unchanged; only the rule source and event source are extended to cover system schemas.

#### Scenario: Workflow completion triggers notification
- GIVEN an n8n workflow `vergunning-beoordeling` completes with output `{"result": "goedgekeurd"}`
- AND a notification rule listens for event `workflow.completed` with condition `{"workflowName": "vergunning-beoordeling"}`
- WHEN the workflow completes
- THEN a notification MUST be sent to the assignee with message: `Vergunning {{object.title}} is goedgekeurd`

#### Scenario: Threshold alert triggers notification
- GIVEN a notification rule with trigger type `threshold`:
  - `schema`: `meldingen`
  - `condition`: `{"aggregate": "count", "operator": ">=", "value": 100, "period": "24h"}`
  - `template`: `Waarschuwing: {{count}} meldingen in de afgelopen 24 uur`
- WHEN the 100th melding is created within 24 hours
- THEN a threshold notification MUST be sent to the configured recipients
- AND the notification MUST include the actual count

#### Scenario: SLA deadline approaching triggers notification
- GIVEN a notification rule with trigger type `deadline`:
  - `schema`: `vergunningen`
  - `condition`: `{"field": "deadline", "operator": "before", "offset": "-48h"}`
  - `template`: `Vergunning "{{object.title}}" nadert deadline ({{object.deadline}})`
- WHEN a background job detects that object `vergunning-1` has a deadline within 48 hours
- THEN a notification MUST be sent to `object.assignedTo` with the deadline warning

#### Scenario: External system triggers notification via API
- GIVEN notification rule 15 is configured to accept external triggers
- WHEN an external system calls `POST /api/notification-rules/15/trigger` with payload `{"objectUuid": "abc-123", "message": "Externe update ontvangen"}`
- THEN a notification MUST be sent to the rule's recipients with the provided message

#### Scenario: updated trigger with `changed` condition fires only when the field value differs
- GIVEN an `updated` rule whose `trigger` declares `condition` `{"field": "status", "operator": "changed"}`
- AND the dispatch context carries the old object data `{"status": "open"}` and the new object data `{"status": "closed"}`
- WHEN the dispatcher evaluates the rule
- THEN the rule MUST fire because the old value (`open`) differs from the new value (`closed`)

#### Scenario: updated trigger with `equals` condition fires only when the new value matches
- GIVEN an `updated` rule whose `trigger` declares `condition` `{"field": "status", "operator": "equals", "value": "closed"}`
- AND the dispatch context carries the old object data `{"status": "open"}` and the new object data `{"status": "closed"}`
- WHEN the dispatcher evaluates the rule
- THEN the rule MUST fire because the new value equals `closed`
- AND GIVEN instead a new object data of `{"status": "pending"}`, the rule MUST NOT fire

#### Scenario: System synchronization failure dispatches an operational notification
- GIVEN OpenRegister's `synchronization` system schema declares an `x-openregister-notifications` rule that fires on the synchronization-failed event (via `transition`→`failed` or `updated`+`condition` `{"field":"status","operator":"equals","value":"failed"}`) with recipients `{"kind":"groups","groups":["admin"]}`
- WHEN a synchronization run transitions to `failed`
- THEN the system-event bridge MUST route the failure through the same listener/dispatcher path used for stored register objects
- AND a notification MUST be delivered to the configured admin/integration-ops group on the configured channel
- AND the subject MUST be metadata-only (no synchronization payload contents) and available in both `nl` and `en`

#### Scenario: System schema/configuration change dispatches an operational notification
- GIVEN OpenRegister's `configuration` system schema declares an `updated` rule with recipients `{"kind":"groups","groups":["admin"]}`
- WHEN a configuration record is updated
- THEN the system-event bridge MUST dispatch the rule through the existing dispatcher path
- AND a notification MUST be delivered to the admin group, reusing the existing rate-limiting, coalescing and per-user preference-override behaviour unchanged

#### Scenario: System source/agent health threshold dispatches an operational notification
- GIVEN OpenRegister's `source` system schema declares a `threshold` rule on consecutive failures (or an `updated`+`condition` rule on a health field)
- WHEN the source becomes unhealthy per the configured threshold/condition
- THEN a notification MUST be delivered to the configured integration-ops group
- AND the dispatch MUST reuse the existing threshold/condition evaluation, numeric `calculatedChange` semantics being unchanged

### Requirement: Notification grouping MUST reduce noise for related events
Multiple notifications about the same object or related objects MUST be grouped to avoid flooding the user's notification panel.

#### Scenario: Group notifications for the same object
- GIVEN user `jan` receives 5 update notifications for object `melding-1` within 2 minutes
- WHEN the notifications are processed
- THEN they MUST be collapsed into a single notification: `Object "Melding overlast" is 5 keer gewijzigd`
- AND only the most recent changes MUST be shown in the notification detail

#### Scenario: Group notifications by schema
- GIVEN user `jan` receives 8 creation notifications for schema `meldingen` within the digest window
- WHEN the digest is delivered
- THEN the notifications MUST be grouped: `8 nieuwe meldingen in register "Zaakregistratie"`
- AND a single link to the filtered list view MUST be included

#### Scenario: Urgent notifications bypass grouping
- GIVEN a notification rule is marked `priority` = `urgent`
- WHEN the event triggers
- THEN the notification MUST be delivered immediately without waiting for the digest window
- AND the notification MUST NOT be merged into any group

### Requirement: Read/unread tracking MUST be maintained per user per notification
The system MUST track whether each notification has been read by each recipient, enabling unread counts and read receipts.

#### Scenario: Track unread notification count
- GIVEN user `jan` has 3 unread and 7 read notifications
- WHEN `jan` queries `GET /api/notifications/unread-count`
- THEN the response MUST return `{"unread": 3}`

#### Scenario: Mark notification as read
- GIVEN user `jan` has an unread notification with ID 42
- WHEN `jan` calls `PUT /api/notifications/42/read`
- THEN the notification MUST be marked as read
- AND the unread count MUST decrease by 1
- AND the Nextcloud notification bell badge MUST update accordingly

#### Scenario: Mark all notifications as read
- GIVEN user `jan` has 5 unread notifications
- WHEN `jan` calls `PUT /api/notifications/read-all`
- THEN all 5 notifications MUST be marked as read
- AND the unread count MUST become 0

#### Scenario: Nextcloud native read tracking integration
- GIVEN a notification was delivered via `INotificationManager::notify()`
- WHEN the user dismisses the notification in Nextcloud's notification panel
- THEN OpenRegister MUST detect the dismissal (via `INotificationManager::markProcessed()`)
- AND the notification MUST be marked as read in the notification history

### Requirement: Notification rate limiting MUST prevent abuse and system overload
The system MUST enforce rate limits on notification delivery per recipient, per rule, and globally to prevent notification storms from degrading system performance.

#### Scenario: Per-recipient rate limit
- GIVEN a rate limit of 100 notifications per hour per recipient
- AND user `jan` has received 100 notifications in the current hour
- WHEN the 101st notification triggers for `jan`
- THEN it MUST be queued for delivery in the next hour
- AND a warning MUST be logged: `Rate limit reached for user jan (100/hour)`

#### Scenario: Per-rule rate limit
- GIVEN notification rule 7 has a rate limit of 500 notifications per hour
- AND 500 notifications have already been sent for rule 7 in the current hour
- WHEN the 501st event triggers rule 7
- THEN it MUST be queued for the next delivery window
- AND the admin MUST be notified that rule 7 is being rate-limited

#### Scenario: Global notification rate limit
- GIVEN a global rate limit of 10,000 notifications per hour
- AND 9,999 notifications have been sent in the current hour
- WHEN the 10,000th notification triggers
- THEN it MUST be delivered
- AND all subsequent notifications in that hour MUST be queued
- AND an admin alert MUST be generated: `Globale notificatielimiet bereikt`

### Requirement: Notification rules MUST be sourced ONLY from the schema annotation, evaluated at dispatch time
The system MUST treat `configuration['x-openregister-notifications']` on the `Schema` as the single, authoritative source of notification rules. Because the schema is ALWAYS loaded whenever anything happens to an object, the dispatcher MUST evaluate the notification rules directly from the already-loaded schema annotation at dispatch time. The system MUST NOT persist notification rules in any separate rule table, and MUST NOT introduce a `NotificationRule` entity or `oc_openregister_notification_rules` table. (ADR-031: `x-openregister-notifications` is the declarative replacement for app-local notification service code.)

OpenRegister's **own system schemas** (`register`, `schema`, `configuration`, `source`, `synchronization`, `import`, `webhook`, `agent`) MUST also be able to declare `x-openregister-notifications` rules for operational events. The dispatcher MUST resolve rules for a system entity through the same annotation-sourced path it uses for stored register objects — either by representing the system entities as schema-backed objects whose schema carries the annotation, or by a system-schema rule source that returns the same rule shape — so that no separate notification-rule table is introduced for system schemas either.

#### Scenario: Dispatcher reads rules from the loaded schema, not a rule table
- **WHEN** an object lifecycle event fires for an object whose schema declares an `x-openregister-notifications` rule on `object.created`
- **THEN** the dispatcher MUST evaluate that rule from the schema annotation already loaded for the object
- **AND** the system MUST NOT query any notification-rule table (none exists)

#### Scenario: Editing the schema annotation changes dispatch behaviour immediately
- **WHEN** an administrator updates `x-openregister-notifications` on a schema to add a new `object.updated` rule and saves the schema
- **THEN** the next `object.updated` event on that schema MUST be evaluated against the new rule
- **AND** no rule-table row creation, migration, or rebuild step is required for the change to take effect

#### Scenario: A system schema declares rules sourced through the same annotation path
- **GIVEN** OpenRegister's `synchronization` system schema declares an `x-openregister-notifications` rule for its failure event
- **WHEN** a synchronization run fails
- **THEN** the dispatcher MUST resolve that rule through the annotation-sourced path (schema-backed object or system-schema rule source), NOT from any notification-rule table
- **AND** the system MUST NOT introduce a notification-rule table for system schemas

### Requirement: User notification preferences MUST be override-only values stored in Nextcloud per-user app config
The system MUST store a user's notification preferences as per-user app-config values under the `openregister` app via `OCP\IConfig::setUserValue`. A stored user value MUST act ONLY as an override that flips the schema-declared default (on/off, and optionally channel) for a single `(schema, notification-key)` pair. The system MUST NOT introduce a `NotificationPreference` table or rely on a `NotificationSubscription` table for preference resolution.

#### Scenario: Stored override flips the schema default off
- **GIVEN** schema `meldingen` declares notification key `object_created` with default `enabled: true`
- **AND** user `behandelaar-1` has stored an override for `(meldingen, object_created)` of `enabled: false`
- **WHEN** a melding object is created and `behandelaar-1` is a resolved recipient
- **THEN** the system MUST NOT deliver the in-app/push notification to `behandelaar-1`

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

### Requirement: The effective-preferences API MUST expose schema-default-merged-with-override reads and single-pair override writes
The system MUST expose an API for the per-user settings pane: a GET endpoint returning the EFFECTIVE notifications for the current user (every notification the user's accessible schemas declare, merged with that user's stored overrides), and a PUT endpoint that records or clears a single `(schema, notification-key)` override. The API MUST be authenticated as the current Nextcloud user and MUST only read/write that user's own overrides.

#### Scenario: GET returns merged effective preferences
- **GIVEN** schema `meldingen` declares `object_created` (default on) and `object_updated` (default off)
- **AND** the current user has an override turning `object_created` off
- **WHEN** the user calls the effective-preferences GET endpoint
- **THEN** the response MUST list `(meldingen, object_created)` as effectively `off` (from the override) and `(meldingen, object_updated)` as effectively `off` (from the schema default)
- **AND** MUST indicate, per entry, whether the effective value came from the schema default or a user override

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
- **THEN** the in-app notification MUST be delivered and the push channel MUST be suppressed for that recipient
- **AND** if the override specifies on/off only (no channel), both declared channels MUST follow the on/off value

### Requirement: Schemas MAY declare notifications via `x-openregister-notifications` with a normative channel block format

A schema MUST be allowed to include a top-level `x-openregister-notifications` block, which the system MUST treat as a map of notification name → spec.
Each spec declares
`trigger` (type + parameters), `filter` (Mongo-style operators
against the triggering object), `recipients` (one or more
recipient blocks), `channels` (one or more channel blocks),
optional `throttle`, optional `audit: bool`, optional `critical: bool`
(default `false` — bypasses quiet-hours queuing only; see "Users MUST be
able to manage their notification preferences"), optional `digest`
(fixed time-of-day schedule; see "Notifications MUST support batching and
digest delivery"). Schema-save validation MUST verify every reference and reject malformed
annotations with HTTP 422.

#### Channel block format (normative)

Every entry in `channels[]` MUST be an object with exactly one
mandatory field — `kind` — whose value is one of
`nc-notification`, `email`, `webhook`, `talk`, `activity`. The
remaining fields are kind-dependent:

| `kind` | Required fields | Optional fields | Notes |
|---|---|---|---|
| `nc-notification` | (none) | `subjectKey`, `messageKey`, `iconUrl`, `link`, `priority` (low/normal/high) | i18n keys resolve via the existing `Notifier` template registry. |
| `email` | (none) | `subjectKey`, `bodyTemplateKey`, `replyTo`, `senderKey` | SMTP config comes from NC; templates are i18n keys. The annotation MUST NOT inline raw email bodies. |
| `webhook` | `webhookId` (UUID of an existing `Webhook` entity registered by an admin) | `mappingKey` (template override) | The target URL MUST come from the existing Webhook entity registry — non-admin schema authors MUST NOT be able to set arbitrary URLs in the annotation, to prevent SSRF. Schema-save validation MUST reject `url:` directly inline in a channel block with `{ code: "notification-channel-webhook-inline-url-forbidden" }`. |
| `talk` | `room` (NC Talk conversation id or token) | `messageKey` | Resolves via `OCA\Talk\Manager`. Validation MUST verify the room exists at install time (best effort — re-checks at delivery). |
| `activity` | (none) | `subjectKey`, `objectType`, `objectName` | Routes through `OCP\Activity\IManager`; the existing `activity-provider` integration consumes it. |

A schema MAY declare more than one channel block per notification
(e.g. send both email and an `nc-notification`). Validation MUST
reject unknown keys, missing mandatory fields, or unsupported
`kind` values with HTTP 422.

#### Scenario: Webhook channel with inline URL is rejected
- GIVEN a notification declares `channels: [{ kind: "webhook", url: "https://attacker.example.com/x" }]`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the response body MUST include `{ code: "notification-channel-webhook-inline-url-forbidden" }`

#### Scenario: Webhook channel referencing a registered entity is accepted
- GIVEN an admin has registered a `Webhook` entity with UUID `abc-123` and target URL `https://allowed.example.com/hook`
- AND a notification declares `channels: [{ kind: "webhook", webhookId: "abc-123" }]`
- WHEN the schema is saved
- THEN the save MUST succeed
- AND delivery MUST POST to the URL stored on the registered Webhook entity, NOT to a URL supplied by the schema author

#### Scenario: `critical` key is accepted and defaults to false
- GIVEN a notification declares no `critical` key
- WHEN the schema is saved
- THEN the save MUST succeed
- AND the rule MUST behave as `critical: false` (subject to quiet-hours queuing, unchanged from pre-existing behaviour)

#### Scenario: `critical` key must be boolean
- GIVEN a notification declares `critical: "yes"`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422

### Requirement: Throttle window grammar (normative)

A notification's optional `throttle` block MAY declare `perRecipient`, `perObject`, and / or `global` windows, and the system MUST validate every declared window.
Each throttle value MUST match the regex
`^([1-9][0-9]*) per (second|minute|hour|day|week)$`
(count + literal `per` + unit). Whitespace between tokens is
exactly one ASCII space. Schema-save validation MUST reject any
other format with HTTP 422 and
`{ code: "notification-throttle-invalid-window", value: "<input>", expected: "{N} per {second|minute|hour|day|week}" }`.
ISO-8601 durations (`PT24H` etc.) are NOT accepted in v1 —
implementations MAY add ISO-8601 in v2 but MUST keep the v1
grammar working unchanged.

#### Scenario: Valid throttle window is accepted
- GIVEN a notification with `throttle: { perRecipient: "1 per day" }`
- WHEN the schema is saved
- THEN validation MUST accept it

#### Scenario: ISO-8601 duration is rejected in v1
- GIVEN a notification with `throttle: { perRecipient: "PT24H" }`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the response body MUST include `{ code: "notification-throttle-invalid-window", value: "PT24H", expected: "{N} per {second|minute|hour|day|week}" }`

### Requirement: Trigger types `created` and `updated` MUST be supported

The trigger registry MUST recognise `created` and `updated`
trigger types (in addition to `transition`, `scheduled`, and
`threshold` documented elsewhere).

#### Scenario: `created` trigger fires on object creation; filters see the new state only
- GIVEN a notification with `trigger: { type: "created" }` and `filter: { taskStatus: "open" }`
- AND a new action item is created with `taskStatus: "open"`
- WHEN `ObjectCreatedEvent` fires
- THEN the installer-mapped listener MUST evaluate the filter against the created object's payload (there is no "before" state)
- AND `$before.*` placeholder MUST resolve to `null` and validation MUST reject filters that require a non-null `$before`
- AND the notification MUST dispatch to all resolved recipients

#### Scenario: `updated` trigger MAY filter on a field-diff (`only_if_changed`)
- GIVEN a notification with `trigger: { type: "updated", only_if_changed: ["assignee"] }`
- AND an existing action item is updated, changing `assignee` from `alice` to `bob`
- WHEN `ObjectUpdatedEvent` fires
- THEN the listener MUST compare the listed fields between before/after state
- AND fire the notification (because `assignee` changed)
- WHEN the same item is later updated, changing only `description`
- THEN the listener MUST NOT fire (no listed field changed)
- AND when `only_if_changed` is omitted, the trigger fires on every update

### Requirement: Scheduled trigger filters MUST support relative-date and inequality operators

A `scheduled` trigger's `filter` MUST be supported as a flat map of object-data field names to conditions, ANDed together.
Each condition MUST be accepted in two forms:

- **Scalar (v1, unchanged):** `{"status": "open"}` — strict equality
  against the object's field value, byte-for-byte the existing
  behaviour.
- **Operator object (v1.1):** `{"<field>": {"operator": "<op>",
  "value": <value>}}` with the operators:
  - `equals` — field value equals `value` (same comparison semantics as
    the scalar form).
  - `notEquals` — field value does not equal `value`. A missing/null
    field value satisfies `notEquals` for any non-null `value`.
  - `withinNext` — the field value, parsed as a date or date-time, lies
    in the half-open window `(now, now + value]`, where `value` is an
    ISO-8601 duration (e.g. `PT24H`, `P7D`) and `now` is the evaluating
    scan's clock.
  - `olderThan` — the field value, parsed as a date or date-time, lies
    before `now - value`, `value` an ISO-8601 duration.

Relative-date operators MUST fail closed: when the field value is
missing, null, or not parseable as a date/date-time, the condition does
NOT match (and the engine logs at debug level, not warning — unfilled
date fields are normal data). All filter entries MUST hold for the
object to match (AND semantics, unchanged).

#### Scenario: Deadline window matched with `withinNext`
- GIVEN a `scheduled` rule with filter `{"dueDate": {"operator": "withinNext", "value": "PT24H"}}`
- AND an object whose `dueDate` is 6 hours after the scan's `now`
- WHEN the scheduled job evaluates the filter
- THEN the object matches and the rule dispatches for it

#### Scenario: Object outside the `withinNext` window does not match
- GIVEN the same rule
- AND an object whose `dueDate` is 3 days after `now`, and another whose `dueDate` is 1 hour before `now`
- WHEN the scheduled job evaluates the filter
- THEN neither object matches (the window is future-only and bounded by the duration)

#### Scenario: `olderThan` selects stale objects
- GIVEN a `scheduled` rule with filter `{"lastSyncedAt": {"operator": "olderThan", "value": "P7D"}}`
- AND an object whose `lastSyncedAt` is 10 days before `now`
- WHEN the scheduled job evaluates the filter
- THEN the object matches

#### Scenario: `notEquals` excludes terminal states and combines with AND semantics
- GIVEN a `scheduled` rule with filter `{"dueDate": {"operator": "withinNext", "value": "PT24H"}, "status": {"operator": "notEquals", "value": "done"}}`
- AND object A with `dueDate` in 6 hours and `status: "open"`, and object B with `dueDate` in 6 hours and `status: "done"`
- WHEN the scheduled job evaluates the filter
- THEN object A matches and object B does not

#### Scenario: Unparsable date fails closed
- GIVEN a `scheduled` rule with a `withinNext` condition on `dueDate`
- AND an object whose `dueDate` value is the string `"soon"`
- WHEN the scheduled job evaluates the filter
- THEN the object does NOT match
- AND no warning-level log entry is produced for it

#### Scenario: Scalar filters keep v1 equality semantics
- GIVEN a `scheduled` rule with filter `{"status": "open"}` (scalar form)
- WHEN the scheduled job evaluates the filter
- THEN matching is strict equality exactly as before this change, with no operator parsing applied

### Requirement: Scheduled filter operator grammar MUST be validated when the schema is saved

The notification-annotation validator MUST reject, with HTTP 422 and a
structured error, any `scheduled` trigger filter entry that is an
operator object with: an unknown `operator`; a missing `value`; or a
`value` that is not a valid ISO-8601 duration when the operator is
`withinNext` or `olderThan`. Scalar filter entries and well-formed
operator objects MUST be accepted. The structured error MUST identify
the rule key, the field, and the offending value (consistent with the
existing throttle-window-grammar requirement).

#### Scenario: Unknown operator rejected at save time
- GIVEN a schema whose `scheduled` rule filter contains `{"dueDate": {"operator": "near", "value": "PT24H"}}`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the response body MUST include a structured error naming the rule key, the field `dueDate`, and the unknown operator `near`

#### Scenario: Invalid duration rejected at save time
- GIVEN a schema whose `scheduled` rule filter contains `{"dueDate": {"operator": "withinNext", "value": "24h"}}`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the structured error MUST state that `withinNext` requires an ISO-8601 duration (e.g. `PT24H`)

#### Scenario: Well-formed operator filter accepted
- GIVEN a schema whose `scheduled` rule filter combines a scalar entry and `withinNext`/`notEquals` operator objects with valid values
- WHEN the schema is saved
- THEN the save MUST succeed

### Requirement: Scheduled rules MUST deduplicate dispatch per object and re-arm on watched-field change

A `scheduled` rule MUST dispatch at most once per (schema, rule key,
object, dedup fingerprint). The dedup fingerprint is derived from the
object's current values of the rule's **watched fields**:

- By default, the watched fields are the filter fields that use a
  relative-date operator (`withinNext` / `olderThan`).
- A rule MAY override the watched-field set with
  `trigger.dedupeFields` (a non-empty array of field names, validated
  at save time against the same 422 contract).
- When a rule has neither relative-date operators nor `dedupeFields`,
  the fingerprint is constant — the rule dispatches at most once per
  object until its dedup state is pruned.

When an object matches the filter on a scan: if no dedup state exists
for (schema, rule, object), or the stored fingerprint differs from the
current one, the engine dispatches and stores the current fingerprint;
if the stored fingerprint equals the current one, the engine MUST NOT
dispatch again. The per-rule `intervalSec` throttle is unchanged and
independent: it bounds scan frequency, not delivery count.

#### Scenario: No re-notification on subsequent scans
- GIVEN an hourly (`intervalSec: 3600`) rule with `withinNext PT24H` on `dueDate`
- AND an object that entered the due window and was notified on the previous scan
- WHEN the next 23 hourly scans evaluate the same object with an unchanged `dueDate`
- THEN no further notification is dispatched for that object

#### Scenario: Changed due date re-arms the reminder
- GIVEN the same rule and an object already notified for `dueDate = 2026-06-12T09:00`
- WHEN the object's `dueDate` is moved to `2026-06-20T09:00` and a later scan finds it inside the window again
- THEN exactly one new notification is dispatched for the new due date

#### Scenario: Unrelated field churn does not re-arm
- GIVEN the same rule (watched field defaults to `dueDate`) and an already-notified object
- WHEN the object's `description` and `status` change while `dueDate` stays the same and the object still matches the filter
- THEN no new notification is dispatched

#### Scenario: `dedupeFields` overrides the watched-field set
- GIVEN a rule with `trigger.dedupeFields: ["assignee"]`
- AND an already-notified object
- WHEN the object's `assignee` changes and a later scan matches it again
- THEN exactly one new notification is dispatched

#### Scenario: Distinct objects are deduplicated independently
- GIVEN two objects A and B that both enter the due window between two scans
- WHEN the next scan runs
- THEN one notification is dispatched for A and one for B, each tracked by its own dedup state

### Requirement: Scheduled dedup state MUST be durable and pruned with its object and rule

Per-object dedup state MUST be persisted in the database (NOT in a
memory/distributed cache), so that cache eviction, restarts, and
backend swaps can neither replay nor suppress notifications. The state
MUST be pruned when: the object is deleted (or purged after soft
delete); the rule is removed from the schema's
`x-openregister-notifications` annotation; or the state row exceeds a
retention horizon (default 90 days since last evaluation match) — a
background sweep reclaims expired rows. Pruned state simply re-arms the
object; it never causes retroactive dispatch.

#### Scenario: Dedup survives cache eviction and restart
- GIVEN an object already notified by a scheduled rule
- WHEN the distributed cache is flushed and the background-job worker restarts
- AND the next scan matches the object with an unchanged fingerprint
- THEN no duplicate notification is dispatched

#### Scenario: Rule removal prunes its state
- GIVEN dedup state rows exist for rule `taskDueSoon` on a schema
- WHEN `taskDueSoon` is removed from the schema's notification annotation and the prune runs
- THEN the rule's dedup rows are deleted
- AND re-adding the rule later treats all objects as not-yet-notified

#### Scenario: Object deletion prunes its state
- GIVEN dedup state exists for an object
- WHEN the object is deleted and purged
- THEN its dedup rows are removed by the prune path

### Requirement: Scheduled notifications fire for every eligible object

The scheduled-notification job SHALL process eligible objects using a filtered,
paged query with a persisted offset cursor, so that every eligible object
eventually fires. It SHALL NOT repeatedly process only the same first N objects
and silently drop the remainder.

#### Scenario: Object beyond the batch cap still fires

- **WHEN** a schema has more eligible objects than one run's batch cap
- **AND** the job runs across multiple ticks
- **THEN** every eligible object eventually triggers its notification
- **AND** an object beyond the first batch is not permanently skipped

#### Scenario: No object fires twice per due window

- **WHEN** the offset cursor completes a full pass
- **THEN** each eligible object fired exactly once for that due window

### Requirement: Periodic sweeps are bounded and watermarked

Periodic object-sweep jobs SHALL bound per-run work with a batch cap and a
watermark/cursor, and SHALL NOT load a full object/case set into memory on every
tick. This covers temporal calculation, DPIA detection, and name warmup.

#### Scenario: Temporal sweep processes only due objects

- **WHEN** the temporal sweep runs on a large schema
- **THEN** it processes only objects whose next tier-crossing time has arrived
- **AND** it does so in bounded batches, not a full-table load

### Requirement: The dispatcher MUST queue, not drop, non-critical notifications suppressed by an active delivery window or a not-yet-due digest schedule

Before delivering a non-broadcast channel (`nc-notification`, `email`, `activity`) to a recipient, the dispatcher MUST evaluate, in addition to the existing preference-off gate, whether the recipient has an active delivery window (quiet hours) covering the current moment, and whether the rule declares a `digest` schedule that has not yet reached its next fire time. When either applies AND the rule is not `critical: true`, the dispatcher MUST persist a `QueuedNotification` row (with the pre-resolved subject/message/channels/context so the eventual flush does not need to re-run recipient/template resolution) and record notification history with status `queued-quiet-hours` or `queued-digest` instead of `dispatched`. Broadcast channels (`webhook`, `talk`) are unaffected by this gate — they continue to fire once per dispatch, unchanged. `NotificationQueueFlushJob`, a 60-second `TimedJob`, re-evaluates each queued row's window/schedule live at each tick (against the current wall clock in the window's or schedule's declared IANA timezone, never a precomputed instant) and flushes rows whose condition has cleared, grouping same-`(rule, recipient)` rows into one summary message.

#### Scenario: Non-critical notification during quiet hours is queued and later flushed
- GIVEN recipient `jan` has quiet hours 18:00-08:00 (Europe/Amsterdam) configured
- AND a non-critical rule fires for `jan` at 20:00 CET
- WHEN the dispatcher evaluates the delivery-window gate
- THEN a `QueuedNotification` row MUST be created with `reason: "quiet-hours"`
- AND notification history MUST record status `queued-quiet-hours`
- AND WHEN `NotificationQueueFlushJob` next ticks after 08:00 Europe/Amsterdam, the notification MUST be delivered and history updated to `dispatched`

#### Scenario: Broadcast channels are unaffected by the delivery-window gate
- GIVEN a rule declares both `nc-notification` and `webhook` channels
- AND the recipient is inside their configured quiet hours
- WHEN the rule fires
- THEN the `nc-notification` channel MUST be queued per the delivery-window gate
- AND the `webhook` channel MUST fire immediately, unaffected (broadcast channels are not per-recipient and are out of scope for this gate)

#### Scenario: Window overlap — delivery waits for the later of quiet-hours-end and digest-due-time
- GIVEN recipient `ouder-1` has quiet hours until 08:00 Europe/Amsterdam
- AND the triggering rule also declares `digest: {schedule: "daily", at: "07:00", timezone: "Europe/Amsterdam"}`
- WHEN events fire for `ouder-1` overnight
- THEN the queued notifications MUST NOT flush at 07:00 (digest-due but still inside quiet hours)
- AND MUST flush at the next `NotificationQueueFlushJob` tick at or after 08:00 (quiet hours cleared)

#### Scenario: Live re-evaluation avoids stale precomputed delivery times across a DST transition
- GIVEN a `QueuedNotification` row was created with an advisory `due_at_hint` computed before a DST transition
- WHEN `NotificationQueueFlushJob` ticks after the DST transition
- THEN the flush decision MUST be based on a fresh evaluation of the recipient's window against the current wall clock in the window's declared timezone, NOT the stored `due_at_hint`

#### Scenario: No delivery window and no digest schedule — behaviour is unchanged from before this change
- GIVEN a recipient has no configured delivery window
- AND the triggering rule declares no `digest` schedule
- WHEN a non-critical notification fires
- THEN the dispatcher MUST dispatch immediately through the unchanged preference-off / rate-limit / coalesce gates
- AND no `QueuedNotification` row MUST be created

## Current Implementation Status
- **Partially implemented -- in-app notifications**: `NotificationService` (`lib/Service/NotificationService.php`) exists and integrates with Nextcloud's `IManager` (INotificationManager). Currently limited to `configuration_update_available` notifications. `Notifier` (`lib/Notification/Notifier.php`) implements `INotifier` for formatting notifications with translations. Registered as a notifier service in `appinfo/info.xml`.
- **Partially implemented -- webhook notifications**: `WebhookService` (`lib/Service/WebhookService.php`) handles outbound webhook delivery with HMAC signing, event filtering, and payload mapping. `WebhookEventListener` (`lib/Listener/WebhookEventListener.php`) listens for 55+ object/register/schema/configuration lifecycle events and triggers webhooks. Webhook entities stored via `WebhookMapper` with `organisation` field for multi-tenant scoping. Delivery logged in `WebhookLog`/`WebhookLogMapper`.
- **Partially implemented -- webhook retry**: `WebhookRetryJob` (`lib/Cron/WebhookRetryJob.php`) and `WebhookDeliveryJob` (`lib/BackgroundJob/WebhookDeliveryJob.php`) handle async delivery and retry with configurable policies (exponential, linear, fixed backoff).
- **Partially implemented -- CloudEvent formatting**: `CloudEventFormatter` (`lib/Service/Webhook/CloudEventFormatter.php`) formats webhook payloads as CloudEvents v1.0 with `specversion`, `type`, `source`, `id`, `time`, and `data` fields.
- **Partially implemented -- payload mapping**: `WebhookService` supports Mapping entity references for Twig-based payload transformation, enabling VNG Notificaties format without hardcoded logic (via `MappingService::executeMapping()`).
- **Not implemented -- configurable notification rules per schema**: No `NotificationRule` entity or `oc_openregister_notification_rules` table exists. No admin UI or API for defining rules with event/condition/channel/recipient configuration.
- **Not implemented -- template-based message formatting for notifications**: No template renderer for notification messages with `{{object.property}}` substitution exists (though Twig is available via MappingService for webhooks).
- **Not implemented -- notification batching and throttling**: No digest/batching mechanism exists for high-frequency events.
- **Not implemented -- user notification preferences**: No per-user opt-out or channel preference management exists.
- **Not implemented -- notification history/audit**: No dedicated notification history table beyond `WebhookLog`.
- **Not implemented -- read/unread tracking**: No read status tracking for in-app notifications beyond Nextcloud's native dismiss.
- **Not implemented -- rate limiting for notifications**: No per-recipient, per-rule, or global rate limiting exists.
- **Not implemented -- threshold/deadline/workflow event triggers**: Only CRUD events trigger notifications; no threshold alerting or scheduled deadline checks exist.
- **Not implemented -- push notifications**: notify_push integration relies on Nextcloud's native behavior (automatic for apps using `INotificationManager`); no explicit push integration code exists.
- **Not implemented -- email notifications**: No email sending service; mail is being phased out in favor of n8n workflows for email delivery.
- **Not implemented -- dead-letter queue**: Failed webhook deliveries are logged but no formal dead-letter queue with admin UI exists.

## Standards & References
- **Nextcloud Notifications API**: `OCP\Notification\IManager`, `OCP\Notification\INotifier`, `OCP\Notification\INotification` -- native notification system
- **Nextcloud notify_push**: Push notification delivery for Nextcloud apps using `INotificationManager` -- automatic for properly registered notifiers
- **CloudEvents v1.0 (CNCF)**: https://cloudevents.io/ -- already adopted for webhook payloads
- **VNG Notificaties API**: https://vng-realisatie.github.io/gemma-zaken/standaard/notificaties/ -- Dutch government notification routing standard (NRC pattern)
- **HMAC-SHA256**: Webhook signature verification via `X-Webhook-Signature` header
- **Twig Template Engine**: https://twig.symfony.com/ -- already used by MappingService for payload transformation
- **Nextcloud IL10N / IFactory**: Internationalization support for notification messages
- **RFC 6570**: URI templates for webhook configuration
- **Nextcloud IEventDispatcher**: Internal event system for cross-app event publishing (used by WebhookEventListener, GraphQLSubscriptionListener, HookListener, SolrEventListener, etc.)

## Cross-References
- **event-driven-architecture**: Provides the CloudEvents event bus that the notification engine consumes. Notification rules subscribe to events published by the event bus. The event bus provides the transport layer; the notification engine provides the user-facing delivery layer.
- **webhook-payload-mapping**: The Mapping entity and `MappingService::executeMapping()` provide the template transformation layer for webhook payloads. VNG Notificaties format compliance is achieved entirely through Mappings, not hardcoded logic. Notification templates for in-app/email channels use the same Twig engine.
- **realtime-updates**: SSE-based real-time updates complement notifications. SSE provides instant UI refresh for connected clients; notifications provide persistent alerts for disconnected users. Both are triggered by the same object lifecycle events via shared event listeners.

## Specificity Assessment
- **Highly specific**: The spec covers 15 requirements with 3-5 scenarios each, covering all notification lifecycle stages from trigger to delivery to tracking.
- **Well-grounded in existing code**: Requirements reference concrete existing classes (NotificationService, Notifier, WebhookService, CloudEventFormatter, WebhookEventListener, MappingService) and Nextcloud APIs (IManager, INotifier, INotification, IL10N, IFactory).
- **Clear extension path**: New features (notification rules, templates, preferences, batching) build on top of existing infrastructure rather than replacing it.
- **Open questions**:
  - Should the NotificationRule entity be a new database table or extend the existing Webhook entity with additional fields?
  - Should notification preferences be stored in Nextcloud's user config (`IConfig::setUserValue`) or a dedicated OpenRegister table?
  - What is the maximum digest window before notifications are considered lost (proposed: 1 hour)?
  - Should notification history share the `WebhookLog` table or have its own `oc_openregister_notification_history` table?

## Nextcloud Integration Analysis

**Status**: Partially Implemented

**Existing Implementation**: `Notifier` class implements `INotifier` and is registered in `appinfo/info.xml` as a notifier service, handling `configuration_update_available` subjects with i18n via `IFactory`. `NotificationService` uses `IManager` for creating, dispatching, and dismissing notifications with group-based recipient resolution and user deduplication. `WebhookService` provides comprehensive outbound webhook delivery with HMAC signing, CloudEvents formatting, Mapping-based payload transformation, event filtering, and retry policies. `WebhookEventListener` handles 55+ event types across Objects, Registers, Schemas, Configurations, Applications, Agents, Sources, Views, Conversations, and Organisations. Webhook entities support multi-tenant scoping via the `organisation` field.

**Nextcloud Core Integration**: The notification engine is natively integrated with Nextcloud's `INotifier` interface (registered during app bootstrap via `appinfo/info.xml` service declaration). This means OpenRegister notifications appear in the standard Nextcloud notification bell. The `notify_push` app (if installed) automatically intercepts `INotificationManager::notify()` calls and pushes them to connected clients via WebSocket, giving OpenRegister real-time push notifications without any additional code. Email delivery via Nextcloud's built-in notification-to-email feature is available when users configure email delivery in their Nextcloud notification settings. The Notifier handles i18n through Nextcloud's `IL10N` translation system via `IFactory::get()`. Webhook delivery runs asynchronously via Nextcloud's `QueuedJob` background job system, ensuring notification processing does not block the originating request. The `INotificationManager` handles the full notification lifecycle: create, mark processed, and dismiss.

**Recommendation**: The in-app notification integration via `INotifier` is the correct and native approach for Nextcloud. Object-lifecycle subjects (`object_created`, `object_updated`, `object_transitioned`) are rendered by `AnnotationNotifier`, which owns those subjects and anything carrying a pre-rendered `_text`; `Notifier` keeps `configuration_update_available` (the two are mutually exclusive by subject). For email notifications, the recommended path is to delegate to n8n workflows via the existing webhook system rather than implementing direct SMTP, which aligns with the project direction. For push notifications, rely on Nextcloud's `notify_push` automatic interception of `INotificationManager::notify()` calls. **No `NotificationRule` or `NotificationPreference`/`NotificationSubscription` tables are introduced**: notification rules live ONLY in the schema annotation `x-openregister-notifications` (evaluated at dispatch from the always-loaded schema), and per-user preferences are override-only Nextcloud user-config values that flip the schema default for a `(schema, notification-key)` pair (absence falls through to the default — zero migration). `NotificationHistory` remains for the audit trail. The existing `WebhookService` and `WebhookEventListener` provide a solid foundation for the webhook channel; the notification engine builds on top of them rather than replacing them.
