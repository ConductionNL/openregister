# Notificatie Engine

## Why

Tender requirements (VNG Notificaties API conformance, NL government audit trail rules) and customer feedback both call for a configurable notification layer on top of OpenRegister's CloudEvents. Hard-coded NC `INotificationManager` calls in earlier prototypes scattered notification logic across services, ignored rate limits, and made it impossible to declare "notify on save" in a schema. A schema-driven engine — with rule annotations, multiple delivery channels, recipient resolvers, and rate limiting — gives admins one place to configure who gets notified about what, while keeping the dispatch path observable and testable.

## What Changes

- Define five trigger types (`created`, `updated`, `transition`, `scheduled`, `threshold`), six recipient kinds (`users`, `field`, `groups`, `relation`, `object-acl`, `expression`), and five channels (`nc-notification`, `email`, `activity`, `webhook`, `talk`) declared on schemas under `configuration['x-openregister-notifications']`.
- Validate notification rules at install time via `NotificationAnnotationValidator` and persist them via `NotificationsAnnotationInstaller`.
- Dispatch through `AnnotationNotificationDispatcher`, which fans out per channel and integrates with Nextcloud's `INotificationManager` for the in-app channel.
- Implement `RateLimiter` as a token-bucket per `(rule, recipient)` pair with default bucket size 10 and 1-token/60s refill, app-config overrides (`notification_rate_limit_default_bucket_size`, `notification_rate_limit_default_refill_seconds`), per-rule overrides on the rule body, and a `notification_rate_limit_enabled` kill switch.
- Run scheduled notifications via `ScheduledNotificationJob` and threshold notifications via `AggregationThresholdListener`.
- Auto-create managed `Webhook` entities for `webhook.persistent: true` rules so notifications use the standard webhook delivery pipeline (exponential retry with `maxRetries=5`, dead-letter via `oc_openregister_webhook_logs`).
- Add batching/digest delivery via a `BatchNotificationJob` that coalesces multiple events into one digest per recipient.
- Add register-/schema-level notification preferences UI so users can subscribe/unsubscribe per register.
- Add a VNG Notificaties API channel adapter that maps OR's payload into the Dutch government envelope (`kanaal`, `hoofdObject`, `resource`, `resourceUrl`, `actie`, `kenmerken`).
- Add explicit organisation-pinning on rules so multi-tenant deployments can scope notifications independently of object visibility.
- Add `oc_openregister_notification_history` table + query API so all dispatches (not just webhook deliveries) are auditable.
- Add notification grouping/coalescing to suppress storms of related events (e.g. "5 actions in 1 minute → one digest").
- Add per-locale `subject` template support (NL/EN) gated on the `register-i18n` spec.
- Add read/unread tracking surface for non-in-app channels where it makes sense.

## Problem
Extend OpenRegister's existing CloudEvent-based event system with user-facing notification delivery. This is NOT a standalone engine — it builds on the event-driven-architecture spec's events and the webhook-payload-mapping spec's delivery infrastructure, adding Nextcloud INotificationManager integration, user preferences, and delivery channels.

## Proposed Solution
Extend the existing implementation with 14 additional requirements.
