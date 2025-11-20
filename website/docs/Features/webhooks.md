# Webhooks

OpenRegister integrates seamlessly with Nextcloud's built-in webhook system, allowing you to trigger external workflows and integrations whenever events occur within OpenRegister.

## Overview

Webhooks enable real-time integration with external systems by dispatching HTTP requests when specific events occur. OpenRegister leverages Nextcloud's `webhook_listeners` app to provide a robust, production-ready webhook system without requiring custom implementation.

### Key Features

- **Event-Driven Architecture**: Subscribe to specific OpenRegister events
- **Automatic Retries**: Built-in retry mechanism for failed deliveries
- **Event Filtering**: Filter events by user, group, or custom criteria
- **Secure**: HTTPS support and authentication options
- **No Custom Code**: Uses Nextcloud's native webhook system

## Requirements

- Nextcloud 28 or higher
- Nextcloud `webhook_listeners` app (bundled with Nextcloud)
- OpenRegister app installed and enabled
- External system to receive webhooks (n8n, Windmill, custom endpoint)

## Enabling Webhooks

### Step 1: Enable webhook_listeners App

The `webhook_listeners` app is bundled with Nextcloud but may need to be enabled:

```bash
docker exec -u 33 <nextcloud-container> php occ app:enable webhook_listeners
```

Verify it is enabled:

```bash
docker exec -u 33 <nextcloud-container> php occ app:list | grep webhook
```

### Step 2: Register Webhook Listeners

Register webhooks using Nextcloud's OCS API:

```bash
curl -X POST http://<nextcloud-host>/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks \
  -H "OCS-APIRequest: true" \
  -u "<admin>:<password>" \
  -H "Content-Type: application/json" \
  -d '{
    "httpMethod": "POST",
    "uri": "https://<your-endpoint>/webhook",
    "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
    "eventFilter": []
  }'
```

#### Parameters

- **httpMethod**: HTTP method (POST, PUT, GET)
- **uri**: External endpoint URL
- **event**: Fully qualified event class name
- **eventFilter**: Optional filters (user.uid, group, etc.)

### Step 3: Configure External System

Configure your external system (n8n, Windmill, etc.) to receive webhook requests. See the [Integrations](#integrations) section for platform-specific guides.

## Available Events

OpenRegister dispatches events for all entity lifecycle operations. Below is a complete list of available events.

### Object Events

| Event | Description | When Triggered |
|-------|-------------|----------------|
| `OCA\OpenRegister\Event\ObjectCreatedEvent` | Object created | After new object is saved |
| `OCA\OpenRegister\Event\ObjectUpdatedEvent` | Object updated | After object is modified |
| `OCA\OpenRegister\Event\ObjectDeletedEvent` | Object deleted | After object is removed |
| `OCA\OpenRegister\Event\ObjectLockedEvent` | Object locked | After object is locked |
| `OCA\OpenRegister\Event\ObjectUnlockedEvent` | Object unlocked | After object is unlocked |
| `OCA\OpenRegister\Event\ObjectRevertedEvent` | Object reverted | After object is reverted to previous version |

### Register Events

| Event | Description | When Triggered |
|-------|-------------|----------------|
| `OCA\OpenRegister\Event\RegisterCreatedEvent` | Register created | After new register is saved |
| `OCA\OpenRegister\Event\RegisterUpdatedEvent` | Register updated | After register is modified |
| `OCA\OpenRegister\Event\RegisterDeletedEvent` | Register deleted | After register is removed |

### Schema Events

| Event | Description | When Triggered |
|-------|-------------|----------------|
| `OCA\OpenRegister\Event\SchemaCreatedEvent` | Schema created | After new schema is saved |
| `OCA\OpenRegister\Event\SchemaUpdatedEvent` | Schema updated | After schema is modified |
| `OCA\OpenRegister\Event\SchemaDeletedEvent` | Schema deleted | After schema is removed |

### Application Events

| Event | Description | When Triggered |
|-------|-------------|----------------|
| `OCA\OpenRegister\Event\ApplicationCreatedEvent` | Application created | After new application is saved |
| `OCA\OpenRegister\Event\ApplicationUpdatedEvent` | Application updated | After application is modified |
| `OCA\OpenRegister\Event\ApplicationDeletedEvent` | Application deleted | After application is removed |

### Agent Events

| Event | Description | When Triggered |
|-------|-------------|----------------|
| `OCA\OpenRegister\Event\AgentCreatedEvent` | Agent created | After new agent is saved |
| `OCA\OpenRegister\Event\AgentUpdatedEvent` | Agent updated | After agent is modified |
| `OCA\OpenRegister\Event\AgentDeletedEvent` | Agent deleted | After agent is removed |

### Source Events

| Event | Description | When Triggered |
|-------|-------------|----------------|
| `OCA\OpenRegister\Event\SourceCreatedEvent` | Source created | After new source is saved |
| `OCA\OpenRegister\Event\SourceUpdatedEvent` | Source updated | After source is modified |
| `OCA\OpenRegister\Event\SourceDeletedEvent` | Source deleted | After source is removed |

### Configuration Events

| Event | Description | When Triggered |
|-------|-------------|----------------|
| `OCA\OpenRegister\Event\ConfigurationCreatedEvent` | Configuration created | After new configuration is saved |
| `OCA\OpenRegister\Event\ConfigurationUpdatedEvent` | Configuration updated | After configuration is modified |
| `OCA\OpenRegister\Event\ConfigurationDeletedEvent` | Configuration deleted | After configuration is removed |

### View Events

| Event | Description | When Triggered |
|-------|-------------|----------------|
| `OCA\OpenRegister\Event\ViewCreatedEvent` | View created | After new view is saved |
| `OCA\OpenRegister\Event\ViewUpdatedEvent` | View updated | After view is modified |
| `OCA\OpenRegister\Event\ViewDeletedEvent` | View deleted | After view is removed |

### Conversation Events

| Event | Description | When Triggered |
|-------|-------------|----------------|
| `OCA\OpenRegister\Event\ConversationCreatedEvent` | Conversation created | After new conversation is saved |
| `OCA\OpenRegister\Event\ConversationUpdatedEvent` | Conversation updated | After conversation is modified |
| `OCA\OpenRegister\Event\ConversationDeletedEvent` | Conversation deleted | After conversation is removed |

### Organisation Events

| Event | Description | When Triggered |
|-------|-------------|----------------|
| `OCA\OpenRegister\Event\OrganisationCreatedEvent` | Organisation created | After new organisation is saved |
| `OCA\OpenRegister\Event\OrganisationUpdatedEvent` | Organisation updated | After organisation is modified |
| `OCA\OpenRegister\Event\OrganisationDeletedEvent` | Organisation deleted | After organisation is removed |

## Event Payload Structure

Each webhook request contains a JSON payload with the event data:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
  "data": {
    "object": {
      "id": 123,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "register": "my-register",
      "schema": "my-schema",
      "data": {
        "title": "Example Object",
        "description": "Object description"
      },
      "organisation": "org-uuid",
      "created": "2024-01-15T10:30:00+00:00",
      "updated": "2024-01-15T10:30:00+00:00"
    }
  }
}
```

For update events, both old and new states are included:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ObjectUpdatedEvent",
  "data": {
    "newObject": { /* updated object */ },
    "oldObject": { /* previous state */ }
  }
}
```

## Event Filtering

You can filter events to reduce noise and only receive webhooks for specific conditions:

### Filter by User

Only trigger for events by a specific user:

```json
{
  "httpMethod": "POST",
  "uri": "https://your-endpoint/webhook",
  "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
  "eventFilter": {
    "user.uid": "specific_user"
  }
}
```

### Filter by Group

Only trigger for events by users in a specific group:

```json
{
  "httpMethod": "POST",
  "uri": "https://your-endpoint/webhook",
  "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
  "eventFilter": {
    "group": "editors"
  }
}
```

## Managing Webhooks

### List All Webhooks

```bash
curl -X GET http://<nextcloud-host>/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks \
  -H "OCS-APIRequest: true" \
  -u "<admin>:<password>"
```

### Delete a Webhook

```bash
curl -X DELETE http://<nextcloud-host>/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks/<webhook-id> \
  -H "OCS-APIRequest: true" \
  -u "<admin>:<password>"
```

## Security Considerations

1. **Use HTTPS**: Always use HTTPS endpoints for webhook URLs
2. **Authentication**: Configure authentication on your webhook endpoint
3. **IP Whitelisting**: Restrict webhook endpoint access to your Nextcloud server IP
4. **Validate Payloads**: Verify incoming webhook requests
5. **Rate Limiting**: Implement rate limiting on your webhook endpoint

## Troubleshooting

### Webhook Not Triggering

1. **Verify app is enabled**:

```bash
docker exec -u 33 <nextcloud-container> php occ app:list | grep webhook
```

2. **Check webhook registration**:

```bash
curl -X GET http://<nextcloud-host>/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks \
  -H "OCS-APIRequest: true" \
  -u "<admin>:<password>"
```

3. **Review Nextcloud logs**:

```bash
docker logs -f <nextcloud-container>
```

### Webhook Endpoint Unreachable

1. Verify endpoint URL is accessible from Nextcloud container
2. Check firewall rules
3. Test endpoint manually with curl
4. Ensure HTTPS certificates are valid

### Events Not Being Dispatched

1. Verify the entity operation actually triggers an event
2. Check that the event class name is correct
3. Ensure the operation completes successfully
4. Review OpenRegister logs for errors

## Common Use Cases

- **Data Synchronization**: Sync OpenRegister data to external databases
- **Workflow Automation**: Trigger workflows in n8n or Windmill
- **Notifications**: Send alerts to Slack, Teams, or email
- **Analytics**: Stream events to analytics platforms
- **Audit Logging**: Record all changes to external audit systems
- **Integration**: Connect to CRM, ERP, or other business systems

## Integrations

OpenRegister provides ready-to-use integrations with popular automation platforms:

- **[n8n Integration Guide](../Integrations/n8n.md)**: Complete guide with workflow templates
- **[Windmill Integration Guide](../Integrations/windmill.md)**: Windmill workflow examples
- **[Custom Webhooks Guide](../Integrations/custom-webhooks.md)**: Build your own webhook consumers

## Further Reading

- [Events API Reference](../api/events-reference.md): Detailed event payload schemas
- [Nextcloud Webhook Listeners Documentation](https://docs.nextcloud.com/server/latest/admin_manual/webhook_listeners/)
- [OpenRegister API Documentation](../api/)

