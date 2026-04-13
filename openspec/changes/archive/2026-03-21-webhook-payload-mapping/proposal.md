# Webhook Payload Mapping

## Problem
Extend OpenRegister's existing CloudEvent-based event and webhook infrastructure with configurable payload mapping. The core webhook delivery (WebhookService, WebhookDeliveryJob, CloudEventFormatter) is already implemented. This spec focuses on the Mapping entity integration for payload transformation, advanced filtering, and delivery management. It documents the complete webhook lifecycle as already implemented: registration with URL/events/secret, payload format selection (standard, CloudEvents, Twig-mapped), delivery retry with exponential backoff, delivery logging, HMAC authentication, event filtering by register/schema/conditions, webhook management API, testing/dry-run, async delivery via background jobs, health monitoring through statistics, multi-tenant webhook isolation via organisation scoping, and request interception for pre-event webhooks. The Mapping entity reference allows any subscriber to receive events in whatever format they require (ZGW notifications, FHIR events, CloudEvents, VNG Notificaties API, custom formats) without any hardcoded format knowledge in OpenRegister.

## Proposed Solution
Implement Webhook Payload Mapping following the detailed specification. Key requirements include:
- Requirement: Webhook registration MUST capture URL, events, secret, and delivery configuration
- Requirement: Webhook entity MUST support an optional mapping reference for payload transformation
- Requirement: Payload format MUST support three strategies with clear priority
- Requirement: Event payload input MUST include full context for mapping templates
- Requirement: Webhook authentication MUST support HMAC-SHA256 signatures

## Scope
This change covers all requirements defined in the webhook-payload-mapping specification.

## Success Criteria
- Create a minimal webhook subscription
- Create a webhook with full configuration
- Webhook with wildcard event subscription
- Webhook with empty events list subscribes to all events
- Required fields validation
