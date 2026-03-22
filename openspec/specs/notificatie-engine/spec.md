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

