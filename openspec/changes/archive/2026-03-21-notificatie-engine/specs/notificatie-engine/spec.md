---
status: partial
---

# Notificatie Engine

## Purpose
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
All notification delivery to Nextcloud users MUST go through Nextcloud's native `OCP\Notification\IManager` interface. The existing `Notifier` class (implementing `INotifier`) MUST be extended to handle all notification subjects beyond `configuration_update_available`, including object lifecycle events, threshold alerts, and workflow-triggered notifications.

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

#### Scenario: Notifier prepares notification with correct i18n
- GIVEN the Notifier receives an `INotification` with subject `object_updated` and `languageCode` = `nl`
- WHEN `Notifier::prepare()` is called
- THEN it MUST use `IFactory::get('openregister', 'nl')` to load Dutch translations
- AND the parsed subject MUST read `Object "%s" bijgewerkt in register "%s"` with the object title and register name substituted
- AND the notification icon MUST be set to the OpenRegister app icon via `IURLGenerator::imagePath()`

#### Scenario: Notifier adds action link to object detail view
- GIVEN a notification for object UUID `abc-123` in register `5` and schema `12`
- WHEN `Notifier::prepare()` formats the notification
- THEN it MUST add a primary action with label `Bekijken` and link to the absolute route `openregister.dashboard.page` with fragment `#/registers/5/schemas/12/objects/abc-123`
- AND the action request type MUST be `GET`

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
High-frequency events MUST NOT overwhelm recipients with individual notifications. The system MUST support configurable digest windows and batch summaries.

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
Users MUST be able to opt in or out of specific notification channels or rules via a personal settings interface, without affecting other users' preferences.

#### Scenario: User disables email notifications for a specific rule
- GIVEN notification rule 7 sends email and in-app notifications to group `behandelaars`
- AND user `jan` is a member of `behandelaars`
- WHEN `jan` disables the `email` channel for rule 7 via `PUT /api/notification-preferences`
- THEN `jan` MUST NOT receive email notifications for rule 7
- AND `jan` MUST still receive in-app notifications for rule 7
- AND other members of `behandelaars` MUST be unaffected

#### Scenario: User opts out of all notifications for a schema
- GIVEN multiple notification rules exist for schema `meldingen`
- WHEN user `jan` opts out of all notifications for schema `meldingen`
- THEN `jan` MUST NOT receive any notifications triggered by events on `meldingen` objects
- AND `jan` MUST still receive notifications for other schemas

#### Scenario: User sets global quiet hours
- GIVEN user `medewerker-1` configures quiet hours from 18:00 to 08:00 (Europe/Amsterdam)
- WHEN a notification event triggers at 22:15 CET
- THEN the notification MUST be queued and delivered at 08:00 the next morning
- AND in-app notifications MUST still be stored (but not pushed) during quiet hours

#### Scenario: Admin overrides user preferences for critical notifications
- GIVEN a notification rule marked as `critical` = `true`
- AND user `jan` has opted out of email notifications
- WHEN the critical rule triggers
- THEN `jan` MUST still receive the notification on all channels including email
- AND the notification MUST be visually marked as critical in the notification panel

#### Scenario: Retrieve user notification preferences
- GIVEN user `jan` has customized preferences for 3 rules
- WHEN `jan` calls `GET /api/notification-preferences`
- THEN the response MUST list all notification rules the user is subscribed to, with per-rule channel settings
- AND rules where the user has no custom preferences MUST show the default channel configuration

### Requirement: Notifications MUST support per-register and per-schema channel subscriptions
Administrators MUST be able to configure notification channels at the register or schema level, providing default notification behavior that individual rules can override.

#### Scenario: Register-level default notification channel
- GIVEN register `zaken` is configured with default notification channels `["in-app"]`
- WHEN a notification rule is created for schema `meldingen` in register `zaken` without specifying channels
- THEN the rule MUST inherit the register's default channels (`in-app`)

#### Scenario: Schema-level notification channel override
- GIVEN register `zaken` has default channels `["in-app"]`
- AND schema `vergunningen` overrides with channels `["in-app", "email"]`
- WHEN a notification rule for `vergunningen` inherits defaults
- THEN it MUST use the schema-level override `["in-app", "email"]`, not the register default

#### Scenario: Rule-level channel takes precedence
- GIVEN schema `meldingen` has default channels `["in-app"]`
- AND a notification rule explicitly sets channels `["webhook"]`
- THEN the rule MUST use only `["webhook"]`, overriding the schema default

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

**Recommendation**: The in-app notification integration via `INotifier` is the correct and native approach for Nextcloud. Extend the existing `Notifier::prepare()` to handle additional subjects (`object_created`, `object_updated`, `object_deleted`, `threshold_alert`, `workflow_completed`, `digest`) beyond the current `configuration_update_available`. For email notifications, the recommended path is to delegate to n8n workflows via the existing webhook system rather than implementing direct SMTP, which aligns with the project direction. For push notifications, rely on Nextcloud's `notify_push` automatic interception of `INotificationManager::notify()` calls. New entities needed: `NotificationRule` (configurable rules), `NotificationPreference` (per-user opt-in/out), and optionally `NotificationHistory` (audit trail). The existing `WebhookService` and `WebhookEventListener` provide a solid foundation for the webhook channel; the notification engine should build on top of them rather than replacing them.
