# Webhooks & Notifications

## Overview

OpenRegister delivers event notifications to external systems via a full-featured webhook infrastructure and to Nextcloud users via in-app notifications. The webhook system uses CloudEvents v1.0 as the standard payload format, supports configurable payload transformation via Twig mappings, HMAC signing for security, retry with exponential backoff, and multi-tenant isolation.

**Tender demand**: 51% of analyzed government tenders require notification capabilities.

## Webhooks

### Webhook Configuration

A webhook subscription is registered via the API and stored as a `Webhook` entity with 23 fields:

```json
{
  "url": "https://external.system.nl/api/events",
  "events": ["nl.conduction.openregister.object.created", "nl.conduction.openregister.object.updated"],
  "secret": "hmac-signing-secret",
  "method": "POST",
  "headers": { "X-Source": "openregister" },
  "timeout": 30,
  "retryPolicy": "exponential",
  "register": "meldingen-register",
  "schema": "meldingen"
}
```

Webhooks can be scoped to a specific register, schema, or object-level filter condition.

### Payload Formats

Three payload strategies are applied in priority order:

| Priority | Strategy | Description |
|----------|----------|-------------|
| 1 (highest) | Twig Mapping | Payload is transformed by a `Mapping` entity using Twig templates — arbitrary output format |
| 2 | CloudEvents v1.0 | Standard CloudEvents envelope, recommended for interoperability |
| 3 | Standard | OpenRegister native JSON format |

**Twig Mapping** enables delivering events in any format required by the subscriber — ZGW notifications, FHIR events, VNG Notificaties API format, Slack messages, etc. — without hardcoded format knowledge in OpenRegister.

### HMAC Signing

Outgoing webhook requests are signed with HMAC-SHA256 using the configured secret:

```
X-Signature: sha256=<hex_signature>
```

Subscribers can verify authenticity by computing the same signature over the raw request body.

### Retry Mechanism

Failed webhook deliveries are retried by `WebhookRetryJob` (runs every 5 minutes):

| Policy | Description |
|--------|-------------|
| `exponential` | Backoff doubles with each attempt (1m, 2m, 4m, 8m, …) |
| `linear` | Fixed interval between attempts |
| `fixed` | All retries at the same interval |

Maximum retry count and backoff ceiling are configurable per webhook.

### Delivery Logging

Every delivery attempt is logged in `WebhookLog`:

- HTTP status code and response body
- Delivery timestamp and duration
- Error message for failed deliveries
- Retry count

Statistics are available via `GET /api/webhooks/{id}/statistics`.

### Event Filtering

`WebhookEventListener` handles 36+ event types across 11 entity categories. Webhooks can filter by:

- Event type (class name)
- Register slug
- Schema slug
- Conditional property matching (object-level conditions)

### Multi-Tenancy

Webhooks are scoped to organisations via `MultiTenancyTrait` on `WebhookMapper`. Each organisation's webhooks only receive events for objects in their organisation scope.

### Management API

```
GET    /api/webhooks                         List all webhooks
POST   /api/webhooks                         Create a new webhook
GET    /api/webhooks/{id}                    Get a webhook
PUT    /api/webhooks/{id}                    Update a webhook
DELETE /api/webhooks/{id}                    Delete a webhook
POST   /api/webhooks/{id}/test               Send a test event
GET    /api/webhooks/{id}/logs               List delivery logs
GET    /api/webhooks/{id}/statistics         Delivery statistics
POST   /api/webhooks/{id}/retry/{logId}      Manually retry a failed delivery
GET    /api/webhooks/events                  List all available event types
```

## In-App Notifications

OpenRegister integrates with Nextcloud's `INotificationManager` for user-facing in-app notifications:

### Notification Channels

| Channel | Description |
|---------|-------------|
| In-app | Nextcloud notification bell (via `INotificationManager`) |
| Webhook | Delegates to existing webhook delivery pipeline |
| Email | Via Nextcloud mail system (being replaced by n8n) |

### Notification Rules

Notification rules are configured per schema:

```json
{
  "notifications": [
    {
      "trigger": "object.created",
      "recipient": "$owner",
      "subject": "Nieuwe melding aangemaakt",
      "template": "melding-aangemaakt"
    },
    {
      "trigger": "object.status_changed",
      "condition": { "field": "status", "value": "afgehandeld" },
      "recipient": "$owner",
      "subject": "Uw melding is afgehandeld"
    }
  ]
}
```

### VNG Notificaties API Compliance

For Dutch government interoperability, webhook payloads can be formatted according to the VNG Notificaties API standard via a Twig mapping configuration. This enables OpenRegister to act as a notificatiecomponent in a ZGW API landscape.

## Standards

| Standard | Role |
|----------|------|
| CloudEvents v1.0 | Default webhook payload envelope |
| HMAC-SHA256 | Webhook request signing |
| VNG Notificaties API | Dutch government notification format (via Mapping) |
| Nextcloud INotificationManager | In-app notification delivery |

## Related Features

- [Event-Driven Architecture](event-driven-architecture.md) — all mutations dispatch events consumed by `WebhookEventListener`
- [Workflow Automation](workflow-automation.md) — schema hooks also trigger on object events
- [Real-Time Updates](realtime-updates.md) — SSE provides in-browser realtime (no polling)
- [Access Control (RBAC)](access-control.md) — webhooks are organisation-scoped
