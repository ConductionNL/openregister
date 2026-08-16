# Notificatie Engine

## Problem
Extend OpenRegister's existing CloudEvent-based event system with user-facing notification delivery. This is NOT a standalone engine — it builds on the event-driven-architecture spec's events and the webhook-payload-mapping spec's delivery infrastructure, adding Nextcloud INotificationManager integration, user preferences, and delivery channels. The existing WebhookService already handles outbound webhook delivery with HMAC signing, CloudEvents formatting, and Mapping-based payload transformation. The existing Notifier class already implements INotifier for in-app notifications. The existing WebhookEventListener already listens for 55+ object/register/schema/configuration lifecycle events. This spec extends that foundation with configurable notification rules per schema, template-based message formatting, recipient resolution, batching/digest delivery, user preference management, and VNG Notificaties API compliance for Dutch government interoperability.
**Tender demand**: 51% of analyzed government tenders require notification capabilities.

## Proposed Solution
Implement Notificatie Engine following the detailed specification. Key requirements include:
- Requirement: The system MUST integrate with Nextcloud's INotificationManager for in-app notifications
- Requirement: The system MUST support configurable notification rules per schema
- Requirement: The system MUST support multiple notification channels
- Requirement: Notification templates MUST support variable substitution with Twig
- Requirement: Notifications MUST support batching and digest delivery

## Scope
This change covers all requirements defined in the notificatie-engine specification.

## Success Criteria
- Deliver object creation notification via INotificationManager
- Dismiss notifications when object is deleted
- Notifier prepares notification with correct i18n
- Notifier adds action link to object detail view
- Create a notification rule for object creation
