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
| `nc-notification` | Nextcloud notification bell (via `INotificationManager`) |
| `email` | Via `IMailer` (being replaced by n8n) |
| `activity` | Activity stream entry per recipient |
| `webhook` | Inline POST per dispatch; with `webhook.persistent: true` an auto-managed `Webhook` entity routes through the standard retry / HMAC / dead-letter pipeline |
| `talk` | Posts a chat message to the configured Spreed room (one-shot per dispatch, recipients are not @-mentioned) |

### Notification Rules

Rules live on the schema under `configuration['x-openregister-notifications']`. Each entry has `trigger`, `recipients`, `channels`, and a `subject` template (supports `{{prop}}` interpolation).

#### Triggers

| `trigger.type` | Fires when |
|----------------|-----------|
| `created` | Object created. |
| `updated` | Object updated. |
| `transition` | Object transitioned via the lifecycle state machine. Optional `trigger.action` filters to a specific action. |
| `scheduled` | Periodic. Requires `trigger.intervalSec >= 60`. The 60s `ScheduledNotificationJob` iterates the schema, optionally narrowed by `trigger.filter` (flat equality match on object data), and dispatches once per interval. |
| `threshold` | Aggregation crossed a threshold. Requires `trigger.aggregation` (declared on the same schema), `trigger.op` ∈ `[gt, gte, lt, lte, eq, ne]`, and `trigger.value`. `AggregationThresholdListener` re-runs the aggregation on object-write events and dispatches once per below→above transition. |

#### Recipient kinds

| `kind` | Resolution |
|--------|------------|
| `users` | Literal list (`recipient.users: [uid, …]`). |
| `field` | Reads `recipient.field` from the object data, treats the value as a uid. |
| `groups` | Members of `recipient.groups`. |
| `relation` | Resolves a typed `x-openregister-relations` field; reads either a uid string, an array of uids, or an array of objects with `userId`. |
| `object-acl` | ACL holders of the object for the configured `recipient.permission` ∈ `[read, manage]`. |
| `expression` | Arbitrary resolver class (DI tag in `recipient.resolver`). Must implement `RecipientResolverInterface::resolve(ObjectEntity $object, array $context): string[]`. |

#### Example

```json
{
  "x-openregister-notifications": {
    "meetingReminderDaily": {
      "trigger": {"type": "scheduled", "intervalSec": 86400, "filter": {"lifecycle": "scheduled"}},
      "recipients": [{"kind": "field", "field": "chair"}],
      "channels": ["nc-notification", "email"],
      "subject": "Reminder: {{title}} starts soon"
    },
    "tooManyOverdue": {
      "trigger": {"type": "threshold", "aggregation": "totalOverdue", "op": "gt", "value": 10},
      "recipients": [{"kind": "groups", "groups": ["admin"]}],
      "channels": ["nc-notification", "talk"],
      "talk": {"token": "abc123def"},
      "subject": "Action items overdue: {{value}}"
    },
    "meetingClosed": {
      "trigger": {"type": "transition", "action": "close"},
      "recipients": [{"kind": "object-acl", "permission": "manage"}],
      "channels": ["webhook"],
      "webhook": {
        "persistent": true,
        "url": "https://example.com/hooks/closed",
        "events": ["ObjectTransitionedEvent"],
        "secret": "..."
      },
      "subject": "Meeting closed"
    }
  }
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
