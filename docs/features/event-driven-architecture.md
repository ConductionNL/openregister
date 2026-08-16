# Event-Driven Architecture

## Overview

OpenRegister implements a comprehensive event-driven architecture built on Nextcloud's `IEventDispatcher` (PSR-14 compatible). Every mutation across all entity types dispatches a typed PHP event that can be consumed by any Nextcloud app, delivered to external systems via webhooks, or pushed to real-time subscribers via GraphQL SSE.

The architecture distinguishes between:

- **Pre-mutation events** (e.g., `ObjectCreatingEvent`) — implement `StoppableEventInterface` so hooks can reject or modify operations before persistence
- **Post-mutation events** (e.g., `ObjectCreatedEvent`) — notify downstream systems after persistence is complete

## Event Types

### Object Events

| Event Class | Trigger | Stoppable |
|------------|---------|-----------|
| `ObjectCreatingEvent` | Before object insert | Yes |
| `ObjectCreatedEvent` | After successful insert | No |
| `ObjectUpdatingEvent` | Before object update | Yes |
| `ObjectUpdatedEvent` | After successful update | No |
| `ObjectDeletingEvent` | Before object delete | Yes |
| `ObjectDeletedEvent` | After successful delete | No |

Pre-mutation events carry the object entity. Update events carry both `$oldObject` (snapshot before change) and `$newObject` (the incoming state).

### Other Entity Events

Events are dispatched for mutations on all entity types:

| Entity | Events |
|--------|--------|
| Register | `RegisterCreating/Created/Updating/Updated/Deleting/Deleted` |
| Schema | `SchemaCreating/Created/Updating/Updated/Deleting/Deleted` |
| Source | `SourceCreating/Created/Updating/Updated/Deleting/Deleted` |
| Configuration | `ConfigurationCreating/Created/Updating/Updated/Deleting/Deleted` |
| View | `ViewCreating/Created/Updating/Updated/Deleting/Deleted` |
| Agent | `AgentCreating/Created/Updating/Updated/Deleting/Deleted` |
| Application | `ApplicationCreating/Created/Updating/Updated/Deleting/Deleted` |
| Conversation | `ConversationCreating/Created/Updating/Updated/Deleting/Deleted` |
| Organisation | `OrganisationCreating/Created/Updating/Updated/Deleting/Deleted` |

All entity types follow the same before/after naming convention, giving 39+ typed event classes in total.

## Consuming Events

Any Nextcloud app can subscribe to OpenRegister events by registering an event listener in its `Application.php`:

```php
use OCA\OpenRegister\Event\ObjectCreatedEvent;

$dispatcher->addListener(ObjectCreatedEvent::class, function (ObjectCreatedEvent $event) {
    $object = $event->getObject();
    // react to the new object
});
```

### Stopping Propagation

Pre-mutation events implement `StoppableEventInterface`. A listener can reject an operation:

```php
$dispatcher->addListener(ObjectCreatingEvent::class, function (ObjectCreatingEvent $event) {
    if (!$this->validate($event->getObject())) {
        $event->stopPropagation(); // object will NOT be persisted
    }
});
```

This is the mechanism used by [Schema Hooks](workflow-automation.md) and reference validation.

## Event Payload (CloudEvents v1.0)

When events are forwarded to external systems (webhooks, realtime SSE), payloads conform to CloudEvents v1.0:

```json
{
  "specversion": "1.0",
  "type": "nl.conduction.openregister.object.created",
  "source": "https://nextcloud.example.nl/apps/openregister",
  "id": "evt-abc123",
  "time": "2026-03-21T12:00:00Z",
  "datacontenttype": "application/json",
  "data": {
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "register": "meldingen-register",
    "schema": "meldingen",
    "object": { ... }
  }
}
```

## Event Listeners (Internal)

OpenRegister ships 8 internal event listeners in `lib/Listener/`:

| Listener | Events | Purpose |
|----------|--------|---------|
| `WebhookEventListener` | 55+ events across all entities | Dispatches webhook deliveries |
| `SchemaHookListener` | `ObjectCreating/Updating/Deleting` | Fires synchronous schema hooks |
| `AuditTrailListener` | All object events | Writes audit trail entries |
| `RelationIntegrityListener` | `ObjectDeleting/Updating` | Enforces referential integrity |
| `GraphQLSubscriptionListener` | All object events | Bridges events to SSE subscription buffer |
| `SearchIndexListener` | `ObjectCreated/Updated/Deleted` | Keeps Solr/Elasticsearch index current |
| `RetentionListener` | `ObjectCreated` | Applies default archival metadata |
| `NotificationListener` | All object events | Evaluates notification rules |

## Deep Link Integration

The `DeepLinkRegistrationEvent` is dispatched during `Application::boot()`. Consuming Nextcloud apps listen for this event to register URL templates for OpenRegister schema/register combinations. See [Deep Link Registry](deep-link-registry.md).

## Standards

| Standard | Role |
|----------|------|
| CloudEvents v1.0 | Webhook and external event payload format |
| PSR-14 | Event dispatcher interface (`IEventDispatcher`) |
| `StoppableEventInterface` | Pre-mutation rejection mechanism |

## Related Features

- [Webhooks & Notifications](webhooks-and-notifications.md) — webhook delivery via `WebhookEventListener`
- [Workflow Automation](workflow-automation.md) — schema hooks use pre-mutation events
- [Real-Time Updates](realtime-updates.md) — SSE subscriptions consume post-mutation events
- [Content Versioning & Audit Trail](versioning-and-audit.md) — audit entries created from object events
- [Deep Link Registry](deep-link-registry.md) — app registration via boot event
