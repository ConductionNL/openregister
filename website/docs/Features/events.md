---
title: Core Features
sidebar_position: 6
description: An overview of how core concepts in Open Register interact with each other.
keywords:
  - Open Register
  - Core Concepts
  - Relationships
---

import ApiSchema from '@theme/ApiSchema';
import Tabs from '@theme/Tabs';
import TabItem from '@theme/TabItem';

# Events

## What are Events in Open Register?

In Open Register, **Events** are notifications that are triggered when significant actions occur within the system. They form the foundation of Open Register's event-driven architecture, enabling loose coupling between components while facilitating rich integration possibilities.

Events in Open Register are:
- Triggered automatically at key points in the application lifecycle
- Standardized messages containing relevant data about what occurred
- Available for other components to listen and respond to
- Essential for building extensible, integrated systems
- Compatible with Nextcloud's event dispatcher system

## Event Structure

An event in Open Register consists of the following key components:

| Component | Description |
|-----------|-------------|
| Event Class | The PHP class that defines the event type |
| Event Data | The data payload carried by the event |
| Timestamp | When the event occurred |
| Source | The component that triggered the event |
| Context | Additional contextual information |

## Event Categories

Open Register provides several categories of events:

### 1. Schema Events

Events related to schema lifecycle:
- **SchemaCreatedEvent**: Triggered when a new schema is created
- **SchemaUpdatedEvent**: Triggered when a schema is updated
- **SchemaDeletedEvent**: Triggered when a schema is deleted

### 2. Register Events

Events related to register lifecycle:
- **RegisterCreatedEvent**: Triggered when a new register is created
- **RegisterUpdatedEvent**: Triggered when a register is updated
- **RegisterDeletedEvent**: Triggered when a register is deleted

### 3. Object Events

Events related to object lifecycle:
- **ObjectCreatedEvent**: Triggered when a new object is created
- **ObjectUpdatedEvent**: Triggered when an object is updated
- **ObjectDeletedEvent**: Triggered when an object is deleted

### 4. File Events

Events related to file operations:
- **FileUploadedEvent**: Triggered when a file is uploaded
- **FileUpdatedEvent**: Triggered when a file is updated
- **FileDeletedEvent**: Triggered when a file is deleted

### 5. Validation Events

Events related to validation:
- **ValidationSucceededEvent**: Triggered when validation succeeds
- **ValidationFailedEvent**: Triggered when validation fails

### Schema Events

#### SchemaCreatedEvent
- **Class**: `OCA\OpenRegister\Event\SchemaCreatedEvent`
- **Triggered**: When a new schema is created in the system
- **Data Provided**:
  - `getSchema()`: Returns the Schema object that was created
- **Usage**: Can be used to perform additional setup or trigger notifications when new schemas are created

#### SchemaUpdatedEvent
- **Class**: `OCA\OpenRegister\Event\SchemaUpdatedEvent`
- **Triggered**: When a schema is updated
- **Data Provided**:
  - `getSchema()`: Returns the updated Schema object
  - `getOldSchema()`: Returns the Schema object before updates
- **Usage**: Useful for tracking changes to schemas and triggering related actions

#### SchemaDeletedEvent
- **Class**: `OCA\OpenRegister\Event\SchemaDeletedEvent`
- **Triggered**: When a schema is deleted from the system
- **Data Provided**: 
  - `getSchema()`: Returns the Schema object that was deleted
- **Usage**: Can be used to perform cleanup or trigger additional actions when schemas are removed

### Register Events

#### RegisterCreatedEvent
- **Class**: `OCA\OpenRegister\Event\RegisterCreatedEvent`
- **Triggered**: When a new register is created
- **Data Provided**:
  - `getRegister()`: Returns the Register object that was created
- **Usage**: Can be used to perform additional setup or trigger notifications when new registers are created

#### RegisterUpdatedEvent
- **Class**: `OCA\OpenRegister\Event\RegisterUpdatedEvent`
- **Triggered**: When a register is updated
- **Data Provided**:
  - `getRegister()`: Returns the updated Register object
  - `getOldRegister()`: Returns the Register object before updates
- **Usage**: Useful for tracking changes to registers and triggering related actions

#### RegisterDeletedEvent
- **Class**: `OCA\OpenRegister\Event\RegisterDeletedEvent`
- **Triggered**: When a register is deleted
- **Data Provided**:
  - `getRegister()`: Returns the Register object that was deleted
- **Usage**: Can be used for cleanup operations or notifications when registers are removed

### Object Events

#### ObjectCreatedEvent
- **Class**: `OCA\OpenRegister\Event\ObjectCreatedEvent` 
- **Triggered**: When a new object is created in a register
- **Data Provided**:
  - `getObject()`: Returns the ObjectEntity that was created
- **Usage**: Useful for tracking new entries, triggering notifications, or performing additional processing on new objects

#### ObjectUpdatedEvent
- **Class**: `OCA\OpenRegister\Event\ObjectUpdatedEvent`
- **Triggered**: When an existing object is updated in a register
- **Data Provided**:
  - `getObject()`: Returns the updated ObjectEntity
  - `getOldObject()`: Returns the ObjectEntity before updates
- **Usage**: Useful for tracking changes to objects, auditing modifications, or triggering follow-up actions

#### ObjectDeletedEvent
- **Class**: `OCA\OpenRegister\Event\ObjectDeletedEvent`
- **Triggered**: When an object is deleted from a register
- **Data Provided**:
  - `getObject()`: Returns the ObjectEntity that was deleted
- **Usage**: Can be used for cleanup operations, maintaining related data integrity, or sending notifications about deletions

## Example Event

Here's an example of an `ObjectCreatedEvent`:

```php
namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Entity\ObjectEntity;
use OCP\EventDispatcher\Event;

class ObjectCreatedEvent extends Event {
    private ObjectEntity $object;

    public function __construct(ObjectEntity $object) {
        parent::__construct();
        $this->object = $object;
    }

    public function getObject(): ObjectEntity {
        return $this->object;
    }
}
```

## Event-Driven Architecture

Open Register uses an event-driven architecture to provide several benefits:

### 1. Loose Coupling

Components can interact without direct dependencies:
- The event publisher doesn't need to know who is listening
- Listeners can be added or removed without changing the publisher
- Different parts of the system can evolve independently

### 2. Extensibility

The event system makes Open Register highly extensible:
- New functionality can be added by listening to existing events
- Third-party applications can integrate without modifying core code
- Custom business logic can be implemented through event listeners

### 3. Scalability

Event-driven architectures support better scalability:
- Processing can be distributed across different components
- Asynchronous handling allows for better resource management
- Event queues can buffer processing during peak loads

### 4. Observability

Events provide better system observability:
- System activities can be monitored through events
- Audit trails can be built by capturing events
- Debugging is easier with a clear event timeline

## Working with Events

### Listening to Events

To listen to events in Open Register, you need to:

1. Create an event listener class
2. Register it with Nextcloud's event dispatcher

Here's an example of a listener for `ObjectCreatedEvent`:

```php
namespace OCA\MyApp\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

class ObjectCreatedListener implements IEventListener {
    public function handle(Event $event): void {
        if (!($event instanceof ObjectCreatedEvent)) {
            return;
        }
        
        $object = $event->getObject();
        // Perform actions with the new object
    }
}
```

### Registering Event Listeners

Register your listener in your app's `Application.php` file:

```php
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\MyApp\Listener\ObjectCreatedListener;
use OCP\EventDispatcher\IEventDispatcher;

// In the register() method:
$dispatcher = $this->getContainer()->get(IEventDispatcher::class);
$dispatcher->addServiceListener(ObjectCreatedEvent::class, ObjectCreatedListener::class);
```

If you're extending Open Register, you might need to dispatch your own events. You can read more about event handling in the [Nextcloud documentation](https://docs.nextcloud.com/server/latest/developer_manual/basics/events.html).

## Event Relationships

Events have important relationships with other core concepts:

### Events and Objects

- Events are triggered by changes to objects
- Events carry object data
- Events enable tracking object lifecycle

### Events and Schemas

- Schema changes trigger events
- Events can be used to validate schema compatibility
- Events enable schema evolution tracking

### Events and Registers

- Register operations trigger events
- Events can be used to monitor register usage
- Events enable register lifecycle management

### Events and Files

- File operations trigger events
- Events carry file metadata
- Events enable file processing workflows

## Use Cases

### 1. Integration

Use events to integrate with other systems:
- Sync data with external systems
- Trigger notifications in messaging platforms
- Update search indexes

### 2. Workflow Automation

Build automated workflows:
- Generate documents when objects are created
- Send approval requests when objects are updated
- Archive data when objects are deleted

### 3. Audit and Compliance

Implement audit and compliance features:
- Log all changes to sensitive data
- Track who did what and when
- Generate compliance reports

### 4. Custom Business Logic

Implement custom business logic:
- Validate complex business rules
- Enforce data quality standards
- Implement approval workflows

## Best Practices

1. **Keep Listeners Focused**: Each listener should have a single responsibility
2. **Handle Errors Gracefully**: Listeners should not break the system if they fail
3. **Consider Performance**: Heavy processing should be done asynchronously
4. **Document Events**: Clearly document what events are available and when they're triggered
5. **Version Events**: Consider versioning events to handle changes over time
6. **Test Event Handling**: Write tests for event listeners
7. **Monitor Event Flow**: Implement monitoring for event processing

## Conclusion

Events in Open Register provide a powerful mechanism for extending functionality, integrating with other systems, and building loosely coupled architectures. By leveraging the event-driven approach, you can create flexible, scalable applications that can evolve over time while maintaining a clean separation of concerns. 