# notificatie-engine Specification

## Purpose
Implement an event-driven notification engine that delivers alerts via email, in-app notifications, and webhooks when register objects change. Notifications MUST be configurable per schema and event type, support template-based message formatting, and enable cross-app event distribution for downstream systems.

**Tender demand**: 51% of analyzed government tenders require notification capabilities.

## ADDED Requirements

### Requirement: The system MUST support configurable notification rules per schema
Administrators MUST be able to define notification rules that trigger on specific CRUD events for each schema.

#### Scenario: Configure notification on object creation
- GIVEN schema `meldingen` in register `zaken`
- WHEN the admin creates a notification rule:
  - Event: `object.created`
  - Schema: `meldingen`
  - Channel: `email`
  - Recipients: group `kcc-team`
  - Template: `Nieuwe melding: {{object.title}} aangemaakt door {{user.displayName}}`
- THEN the rule MUST be stored and activated
- AND creating a new melding object MUST trigger an email to all kcc-team members

#### Scenario: Configure notification on status change
- GIVEN schema `vergunningen` with property `status`
- WHEN the admin creates a rule:
  - Event: `object.updated`
  - Condition: `changed.status != null`
  - Channel: `in-app`
  - Recipients: `object.assignedTo`
- THEN updating a vergunning's status MUST trigger an in-app notification to the assigned user

### Requirement: The system MUST support multiple notification channels
Notifications MUST be deliverable via email, Nextcloud in-app notifications, and outbound webhooks.

#### Scenario: Deliver email notification
- GIVEN a notification rule with channel `email` and recipient `user@example.nl`
- WHEN the triggering event occurs
- THEN the system MUST send an email with the rendered template to the recipient
- AND the email MUST include a link back to the object in the OpenRegister UI

#### Scenario: Deliver in-app notification
- GIVEN a notification rule with channel `in-app` and recipient user `behandelaar-1`
- WHEN the triggering event occurs
- THEN a Nextcloud notification MUST appear in the user's notification panel
- AND clicking the notification MUST navigate to the object detail view

#### Scenario: Deliver webhook notification
- GIVEN a notification rule with channel `webhook` and URL `https://external-system.example.nl/hooks/intake`
- WHEN the triggering event occurs
- THEN the system MUST POST a JSON payload to the webhook URL containing:
  - `event`: the event type
  - `object`: the full object data
  - `changed`: the changed fields (for updates)
  - `timestamp`: ISO 8601 timestamp
  - `register` and `schema` identifiers
- AND the webhook MUST include an HMAC signature header for verification

### Requirement: Notification templates MUST support variable substitution
Templates MUST support referencing object properties, user properties, and event metadata using a placeholder syntax.

#### Scenario: Render template with object properties
- GIVEN a template: `Zaak {{object.title}} is gewijzigd. Nieuwe status: {{object.status}}.`
- AND object has title `Melding overlast` and status `In behandeling`
- WHEN the template is rendered
- THEN the output MUST be: `Zaak Melding overlast is gewijzigd. Nieuwe status: In behandeling.`

#### Scenario: Template with missing property
- GIVEN a template referencing `{{object.nonExistentField}}`
- WHEN the template is rendered
- THEN the placeholder MUST be replaced with an empty string
- AND the notification MUST still be delivered

### Requirement: Notifications MUST support batching and throttling
High-frequency events MUST NOT overwhelm recipients with individual notifications.

#### Scenario: Batch notifications for bulk operations
- GIVEN a notification rule on `object.created` for schema `meldingen`
- AND 50 meldingen are created in a single batch import
- WHEN the notifications are processed
- THEN the system SHOULD send a single digest notification: `50 nieuwe meldingen aangemaakt`
- AND the digest MUST include a link to the filtered object list

#### Scenario: Throttle notifications per recipient
- GIVEN a recipient has received 10 notifications in the last minute for the same rule
- WHEN the 11th event triggers
- THEN the system SHOULD queue the notification and deliver it in the next digest cycle
- AND the digest period SHOULD be configurable (default: 5 minutes)

### Requirement: Notification delivery MUST be reliable with retry
Failed notification deliveries MUST be retried with exponential backoff.

#### Scenario: Webhook delivery failure and retry
- GIVEN a webhook notification to `https://external.example.nl/hooks` fails with HTTP 503
- WHEN the retry mechanism activates
- THEN the system MUST retry after 30 seconds, then 2 minutes, then 10 minutes
- AND after 3 failed attempts, the notification MUST be marked as `failed`
- AND the failure MUST be logged with the HTTP response details

### Requirement: Users MUST be able to manage their notification preferences
Users MUST be able to opt out of specific notification channels or rules.

#### Scenario: User disables email notifications for a rule
- GIVEN a notification rule sending email to group `behandelaars`
- WHEN user `jan` in that group disables email for this rule
- THEN `jan` MUST NOT receive email notifications for this rule
- AND `jan` MUST still receive in-app notifications if that channel is also configured

### Current Implementation Status
- **Partially implemented — in-app notifications**: `NotificationService` (`lib/Service/NotificationService.php`) exists and integrates with Nextcloud's `INotificationManager`. `Notifier` (`lib/Notification/Notifier.php`) implements `INotifier` for formatting notifications with translations.
- **Partially implemented — webhook notifications**: `WebhookService` (`lib/Service/WebhookService.php`) handles outbound webhook delivery. `WebhookEventListener` (`lib/Listener/WebhookEventListener.php`) listens for object CRUD events and triggers webhooks. Webhook entities are stored via `WebhookMapper` (`lib/Db/WebhookMapper.php`) and delivery is logged in `WebhookLog` (`lib/Db/WebhookLog.php`) / `WebhookLogMapper` (`lib/Db/WebhookLogMapper.php`).
- **Partially implemented — webhook retry**: `WebhookRetryJob` (`lib/Cron/WebhookRetryJob.php`) and `WebhookDeliveryJob` (`lib/BackgroundJob/WebhookDeliveryJob.php`) handle async delivery and retry logic.
- **Partially implemented — CloudEvent formatting**: `CloudEventFormatter` (`lib/Service/Webhook/CloudEventFormatter.php`) formats webhook payloads following the CloudEvents specification.
- **Not implemented — email notification channel**: No email sending service exists for notification rules. The codebase notes that mail is being phased out in favor of n8n workflows.
- **Not implemented — configurable notification rules per schema**: No admin UI or entity for defining notification rules with event/condition/channel/recipient configuration exists. Webhooks are configured globally, not per-schema with conditions.
- **Not implemented — template-based message formatting**: No template engine for notification messages with `{{object.property}}` substitution exists.
- **Not implemented — notification batching and throttling**: No digest/batching mechanism exists for high-frequency events.
- **Not implemented — user notification preferences**: No per-user opt-out or channel preference management exists.

### Standards & References
- CloudEvents specification (https://cloudevents.io/) — already partially adopted for webhook payloads
- Nextcloud Notifications API (`INotificationManager`, `INotifier`)
- HMAC-SHA256 for webhook signature verification
- VNG Notificaties API (https://vng-realisatie.github.io/gemma-zaken/standaard/notificaties/) for Dutch government notification patterns
- RFC 6570 for URI templates in webhook configuration

### Specificity Assessment
- **Moderately specific**: The spec covers notification rules, channels, templates, batching, retry, and user preferences with clear scenarios.
- **Missing details**:
  - Data model for notification rules (what entity, what fields, how stored?)
  - How conditions are evaluated (expression language? JSON path? Simple field comparison?)
  - Integration with the existing webhook system vs. a new unified notification system
  - n8n workflow integration for email delivery (since direct SMTP is being phased out)
  - How recipient resolution works for dynamic recipients like `object.assignedTo`
- **Open questions**:
  - Should the notification engine build on top of the existing webhook system or replace it?
  - Should email delivery be delegated to n8n workflows rather than implemented natively?
  - What is the relationship between this spec and the existing `WebhookService`?

## Nextcloud Integration Analysis

**Status**: Implemented

**Existing Implementation**: Notifier class implements INotifier for formatting in-app notifications with translation support. NotificationService integrates with Nextcloud's INotificationManager for creating and dispatching notifications. WebhookService handles outbound webhook delivery with WebhookEventListener triggering on object CRUD events. Webhook entities are stored via WebhookMapper with delivery logging in WebhookLog/WebhookLogMapper. WebhookRetryJob and WebhookDeliveryJob handle async delivery and retry logic. CloudEventFormatter formats webhook payloads following the CloudEvents specification.

**Nextcloud Core Integration**: The notification engine is natively integrated with Nextcloud's INotifier interface (OCP\Notification\INotifier), registered during app bootstrap via IBootstrap::register(). This means OpenRegister notifications appear in the standard Nextcloud notification bell, supporting both web push (via the Nextcloud Push app) and email delivery (via Nextcloud's built-in notification-to-email feature). The Notifier class handles i18n through Nextcloud's IL10N translation system. Webhook delivery runs asynchronously via Nextcloud's BackgroundJob system, ensuring that notification processing does not block the originating request. The INotificationManager handles notification lifecycle (create, mark processed, dismiss).

**Recommendation**: The in-app notification integration via INotifier is the correct and native approach for Nextcloud. The webhook delivery system with CloudEvents formatting provides a solid foundation for external system integration. For email notifications specifically, the recommended path is to rely on Nextcloud's notification-to-email feature (users configure email delivery in their notification settings) rather than implementing direct SMTP sending, which aligns with the noted direction of phasing out direct mail in favor of n8n workflows. Enhancements to consider: configurable notification rules per schema with condition evaluation, template-based message formatting using Twig (already available in the codebase), and notification batching for bulk operations to prevent notification floods.
