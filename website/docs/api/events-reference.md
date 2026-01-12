# Events API Reference

Complete reference for all OpenRegister events, including event classes, payload structures, and usage examples.

## Event Structure

All OpenRegister events follow a consistent structure:

```json
{
  "event": "Fully\\Qualified\\Event\\ClassName",
  "data": {
    // Event-specific data
  }
}
```

## Event Categories

- [Object Events](#object-events)
- [Register Events](#register-events)
- [Schema Events](#schema-events)
- [Application Events](#application-events)
- [Agent Events](#agent-events)
- [Source Events](#source-events)
- [Configuration Events](#configuration-events)
- [View Events](#view-events)
- [Conversation Events](#conversation-events)
- [Organisation Events](#organisation-events)

---

## Object Events

### ObjectCreatedEvent

**Event Class**: `OCA\OpenRegister\Event\ObjectCreatedEvent`

**When Triggered**: After a new object is successfully created and saved to the database.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
  "data": {
    "object": {
      "id": 123,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "register": "customers",
      "schema": "customer",
      "data": {
        "title": "John Doe",
        "description": "Customer record",
        "email": "john@example.com",
        "customFields": {}
      },
      "organisation": "org-uuid",
      "version": 1,
      "created": "2024-01-15T10:30:00+00:00",
      "updated": "2024-01-15T10:30:00+00:00"
    }
  }
}
```

**PHP Access**:

```php
$event->getObject(); // Returns ObjectEntity
```

---

### ObjectUpdatedEvent

**Event Class**: `OCA\OpenRegister\Event\ObjectUpdatedEvent`

**When Triggered**: After an object is successfully updated in the database.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ObjectUpdatedEvent",
  "data": {
    "newObject": {
      "id": 123,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "data": {
        "title": "John Doe (Updated)",
        "email": "newemail@example.com"
      },
      "updated": "2024-01-15T11:00:00+00:00"
    },
    "oldObject": {
      "id": 123,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "data": {
        "title": "John Doe",
        "email": "john@example.com"
      },
      "updated": "2024-01-15T10:30:00+00:00"
    }
  }
}
```

**PHP Access**:

```php
$event->getNewObject(); // Returns updated ObjectEntity
$event->getOldObject(); // Returns previous state
```

---

### ObjectDeletedEvent

**Event Class**: `OCA\OpenRegister\Event\ObjectDeletedEvent`

**When Triggered**: After an object is successfully deleted from the database.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ObjectDeletedEvent",
  "data": {
    "object": {
      "id": 123,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "register": "customers",
      "schema": "customer",
      "data": { /* object data before deletion */ }
    }
  }
}
```

**PHP Access**:

```php
$event->getObject(); // Returns deleted ObjectEntity
```

---

### ObjectLockedEvent

**Event Class**: `OCA\OpenRegister\Event\ObjectLockedEvent`

**When Triggered**: After an object is locked to prevent modifications.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ObjectLockedEvent",
  "data": {
    "object": {
      "id": 123,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "locked": true
    }
  }
}
```

**PHP Access**:

```php
$event->getObject(); // Returns locked ObjectEntity
```

---

### ObjectUnlockedEvent

**Event Class**: `OCA\OpenRegister\Event\ObjectUnlockedEvent`

**When Triggered**: After an object is unlocked to allow modifications.

**Payload Structure**: Same structure as ObjectLockedEvent with `"locked": false`.

**PHP Access**:

```php
$event->getObject(); // Returns unlocked ObjectEntity
```

---

### ObjectRevertedEvent

**Event Class**: `OCA\OpenRegister\Event\ObjectRevertedEvent`

**When Triggered**: After an object is reverted to a previous version.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ObjectRevertedEvent",
  "data": {
    "object": {
      "id": 123,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "version": 5,
      "data": { /* reverted data */ }
    }
  }
}
```

**PHP Access**:

```php
$event->getObject(); // Returns reverted ObjectEntity
```

---

## Register Events

### RegisterCreatedEvent

**Event Class**: `OCA\OpenRegister\Event\RegisterCreatedEvent`

**When Triggered**: After a new register is created.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\RegisterCreatedEvent",
  "data": {
    "register": {
      "id": 1,
      "uuid": "register-uuid",
      "name": "customers",
      "description": "Customer register",
      "schema": "customer-schema-uuid",
      "created": "2024-01-15T10:00:00+00:00",
      "updated": "2024-01-15T10:00:00+00:00"
    }
  }
}
```

**PHP Access**:

```php
$event->getRegister(); // Returns Register entity
```

---

### RegisterUpdatedEvent

**Event Class**: `OCA\OpenRegister\Event\RegisterUpdatedEvent`

**When Triggered**: After a register is updated.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\RegisterUpdatedEvent",
  "data": {
    "newRegister": { /* updated register */ },
    "oldRegister": { /* previous state */ }
  }
}
```

**PHP Access**:

```php
$event->getNewRegister(); // Returns updated Register
$event->getOldRegister(); // Returns previous state
```

---

### RegisterDeletedEvent

**Event Class**: `OCA\OpenRegister\Event\RegisterDeletedEvent`

**When Triggered**: After a register is deleted.

**PHP Access**:

```php
$event->getRegister(); // Returns deleted Register entity
```

---

## Schema Events

### SchemaCreatedEvent

**Event Class**: `OCA\OpenRegister\Event\SchemaCreatedEvent`

**When Triggered**: After a new schema is created.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\SchemaCreatedEvent",
  "data": {
    "schema": {
      "id": 1,
      "uuid": "schema-uuid",
      "name": "customer",
      "version": "1.0.0",
      "description": "Customer schema",
      "properties": {
        "title": {
          "type": "string",
          "required": true
        },
        "email": {
          "type": "string",
          "format": "email"
        }
      },
      "created": "2024-01-15T09:00:00+00:00",
      "updated": "2024-01-15T09:00:00+00:00"
    }
  }
}
```

**PHP Access**:

```php
$event->getSchema(); // Returns Schema entity
```

---

### SchemaUpdatedEvent

**Event Class**: `OCA\OpenRegister\Event\SchemaUpdatedEvent`

**When Triggered**: After a schema is updated.

**Payload Structure**: Includes both `newSchema` and `oldSchema`.

**PHP Access**:

```php
$event->getNewSchema(); // Returns updated Schema
$event->getOldSchema(); // Returns previous state
```

---

### SchemaDeletedEvent

**Event Class**: `OCA\OpenRegister\Event\SchemaDeletedEvent`

**When Triggered**: After a schema is deleted.

**PHP Access**:

```php
$event->getSchema(); // Returns deleted Schema entity
```

---

## Application Events

### ApplicationCreatedEvent

**Event Class**: `OCA\OpenRegister\Event\ApplicationCreatedEvent`

**When Triggered**: After a new application is created.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ApplicationCreatedEvent",
  "data": {
    "application": {
      "id": 1,
      "uuid": "app-uuid",
      "name": "My Application",
      "description": "Application description",
      "version": "1.0.0",
      "organisation": "org-uuid",
      "created": "2024-01-15T10:00:00+00:00",
      "updated": "2024-01-15T10:00:00+00:00"
    }
  }
}
```

**PHP Access**:

```php
$event->getApplication(); // Returns Application entity
```

---

### ApplicationUpdatedEvent

**Event Class**: `OCA\OpenRegister\Event\ApplicationUpdatedEvent`

**When Triggered**: After an application is updated.

**PHP Access**:

```php
$event->getNewApplication(); // Returns updated Application
$event->getOldApplication(); // Returns previous state
```

---

### ApplicationDeletedEvent

**Event Class**: `OCA\OpenRegister\Event\ApplicationDeletedEvent`

**When Triggered**: After an application is deleted.

**PHP Access**:

```php
$event->getApplication(); // Returns deleted Application entity
```

---

## Agent Events

### AgentCreatedEvent

**Event Class**: `OCA\OpenRegister\Event\AgentCreatedEvent`

**When Triggered**: After a new agent is created.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\AgentCreatedEvent",
  "data": {
    "agent": {
      "id": 1,
      "uuid": "agent-uuid",
      "name": "AI Assistant",
      "description": "Customer service agent",
      "type": "chatbot",
      "configuration": {},
      "created": "2024-01-15T10:00:00+00:00",
      "updated": "2024-01-15T10:00:00+00:00"
    }
  }
}
```

**PHP Access**:

```php
$event->getAgent(); // Returns Agent entity
```

---

### AgentUpdatedEvent / AgentDeletedEvent

Similar structure to ApplicationUpdatedEvent / ApplicationDeletedEvent.

**PHP Access**:

```php
// Updated
$event->getNewAgent();
$event->getOldAgent();

// Deleted
$event->getAgent();
```

---

## Source Events

### SourceCreatedEvent

**Event Class**: `OCA\OpenRegister\Event\SourceCreatedEvent`

**When Triggered**: After a new data source is created.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\SourceCreatedEvent",
  "data": {
    "source": {
      "id": 1,
      "uuid": "source-uuid",
      "name": "External API",
      "type": "api",
      "endpoint": "https://api.example.com",
      "configuration": {},
      "created": "2024-01-15T10:00:00+00:00",
      "updated": "2024-01-15T10:00:00+00:00"
    }
  }
}
```

**PHP Access**:

```php
$event->getSource(); // Returns Source entity
```

---

### SourceUpdatedEvent / SourceDeletedEvent

**PHP Access**:

```php
// Updated
$event->getNewSource();
$event->getOldSource();

// Deleted
$event->getSource();
```

---

## Configuration Events

### ConfigurationCreatedEvent

**Event Class**: `OCA\OpenRegister\Event\ConfigurationCreatedEvent`

**When Triggered**: After a new configuration is created.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ConfigurationCreatedEvent",
  "data": {
    "configuration": {
      "id": 1,
      "uuid": "config-uuid",
      "type": "api",
      "name": "API Configuration",
      "configuration": {
        "apiKey": "encrypted-key",
        "endpoint": "https://api.example.com"
      },
      "created": "2024-01-15T10:00:00+00:00",
      "updated": "2024-01-15T10:00:00+00:00"
    }
  }
}
```

**PHP Access**:

```php
$event->getConfiguration(); // Returns Configuration entity
```

---

### ConfigurationUpdatedEvent / ConfigurationDeletedEvent

**PHP Access**:

```php
// Updated
$event->getNewConfiguration();
$event->getOldConfiguration();

// Deleted
$event->getConfiguration();
```

---

## View Events

### ViewCreatedEvent

**Event Class**: `OCA\OpenRegister\Event\ViewCreatedEvent`

**When Triggered**: After a new view is created.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ViewCreatedEvent",
  "data": {
    "view": {
      "id": 1,
      "uuid": "view-uuid",
      "name": "Customer List",
      "register": "customers",
      "filters": {},
      "columns": ["title", "email", "created"],
      "created": "2024-01-15T10:00:00+00:00",
      "updated": "2024-01-15T10:00:00+00:00"
    }
  }
}
```

**PHP Access**:

```php
$event->getView(); // Returns View entity
```

---

### ViewUpdatedEvent / ViewDeletedEvent

**PHP Access**:

```php
// Updated
$event->getNewView();
$event->getOldView();

// Deleted
$event->getView();
```

---

## Conversation Events

### ConversationCreatedEvent

**Event Class**: `OCA\OpenRegister\Event\ConversationCreatedEvent`

**When Triggered**: After a new conversation is created.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ConversationCreatedEvent",
  "data": {
    "conversation": {
      "id": 1,
      "uuid": "conversation-uuid",
      "title": "Support Ticket #123",
      "messages": [],
      "created": "2024-01-15T10:00:00+00:00",
      "updated": "2024-01-15T10:00:00+00:00"
    }
  }
}
```

**PHP Access**:

```php
$event->getConversation(); // Returns Conversation entity
```

---

### ConversationUpdatedEvent / ConversationDeletedEvent

**PHP Access**:

```php
// Updated
$event->getNewConversation();
$event->getOldConversation();

// Deleted
$event->getConversation();
```

---

## Organisation Events

### OrganisationCreatedEvent

**Event Class**: `OCA\OpenRegister\Event\OrganisationCreatedEvent`

**When Triggered**: After a new organisation is created.

**Payload Structure**:

```json
{
  "event": "OCA\\OpenRegister\\Event\\OrganisationCreatedEvent",
  "data": {
    "organisation": {
      "id": 1,
      "uuid": "org-uuid",
      "name": "Acme Corp",
      "description": "Organisation description",
      "created": "2024-01-15T10:00:00+00:00",
      "updated": "2024-01-15T10:00:00+00:00"
    }
  }
}
```

**PHP Access**:

```php
$event->getOrganisation(); // Returns Organisation entity
```

---

### OrganisationUpdatedEvent / OrganisationDeletedEvent

**PHP Access**:

```php
// Updated
$event->getNewOrganisation();
$event->getOldOrganisation();

// Deleted
$event->getOrganisation();
```

---

## Event Filtering

When registering webhooks, you can filter events using `eventFilter`:

### Filter by User

```json
{
  "eventFilter": {
    "user.uid": "admin"
  }
}
```

### Filter by Group

```json
{
  "eventFilter": {
    "group": "editors"
  }
}
```

### Multiple Filters

```json
{
  "eventFilter": {
    "user.uid": "admin",
    "group": "managers"
  }
}
```

## Common Patterns

### Detecting Field Changes

```javascript
function detectChanges(oldObj, newObj) {
  const changes = [];
  const oldData = oldObj.data || {};
  const newData = newObj.data || {};
  
  for (const key in newData) {
    if (oldData[key] !== newData[key]) {
      changes.push({
        field: key,
        oldValue: oldData[key],
        newValue: newData[key]
      });
    }
  }
  
  return changes;
}
```

### Processing by Entity Type

```python
def process_event(payload):
    event = payload.get('event', '')
    
    if 'Object' in event:
        return process_object_event(payload)
    elif 'Schema' in event:
        return process_schema_event(payload)
    elif 'Register' in event:
        return process_register_event(payload)
    # etc.
```

### Batch Processing

```python
def batch_process_objects(events):
    objects = [e['data']['object'] for e in events 
               if 'ObjectCreatedEvent' in e['event']]
    
    # Process all objects at once.
    save_to_database(objects)
```

## Best Practices

1. **Handle All Event Types**: Don't assume only certain events will be received
2. **Validate Payloads**: Always validate incoming event payloads
3. **Idempotency**: Ensure your handlers can process the same event multiple times safely
4. **Error Handling**: Implement proper error handling and logging
5. **Performance**: Process events efficiently, especially for high-volume scenarios
6. **Testing**: Test with sample payloads before deploying

## Further Reading

- [Webhooks Feature Documentation](../Features/webhooks.md)
- [n8n Integration Guide](../Integrations/n8n.md)
- [Windmill Integration Guide](../Integrations/windmill.md)
- [Custom Webhooks Guide](../Integrations/custom-webhooks.md)

