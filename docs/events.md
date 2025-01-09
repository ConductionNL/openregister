# Events Documentation

## Overview

This document provides a comprehensive overview of all events in the OpenRegister application. Events are a crucial part of OpenRegister's component-based architecture, enabling seamless integration with other Nextcloud applications. In Nextcloud's ecosystem, each application functions as an independent component - events provide a standardized way for these components to communicate and interact with OpenRegister.

These events can be used to hook into various actions and extend functionality, allowing other applications to respond to changes in OpenRegister's data and workflow without tight coupling. This event-driven approach promotes loose coupling between components while enabling rich integration possibilities.

## Available Events

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

## Using Events

Events are a powerful way to decouple different parts of your application and respond to changes in the system. The OpenRegister app uses Nextcloud's event dispatcher system to broadcast various events that you can listen to.

### Event Handling Overview

Events are dispatched at key points in the application lifecycle, such as when objects are created, updated, or deleted. By implementing event listeners, you can:

- Perform additional actions when changes occur
- Maintain data consistency across different parts of the system 
- Send notifications or trigger external integrations
- Add custom business logic without modifying core code
- Create audit trails and logs

### Implementation Steps

To start handling events in your application, follow these steps:

### 1. Create an Event Listener Class

First, create a class that will handle the event. Your listener class should:

- Implement the `IEventListener` interface
- Define a `handle()` method that receives the event object
- Be placed in an appropriate namespace in your application

Here's a basic example structure:

```php
namespace OCA\MyApp\Listener;

use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;

class SchemaListener implements IEventListener {
    public function handle(SchemaCreatedEvent $event) {
        // Handle the event
    }
}
```	

### 2. Register the Event Listener

After creating your listener class, you need to register it with Nextcloud's event dispatcher system. This is done in your application's `lib/AppInfo/Application.php` file by registering the listener in the `register()` method:

```php
/* @var IEventDispatcher $dispatcher */
$dispatcher = $this->getContainer()->get(IEventDispatcher::class);
$dispatcher->addServiceListener(eventName: SchemaCreatedEvent::class, className: SchemaCreatedEvent::class);
```

This line registers the `SchemaListener` class to handle `SchemaCreatedEvent` events. You can add similar lines for other events to register your listener for those specific events.

You can read more about event handling in the [Nextcloud documentation](https://docs.nextcloud.com/server/latest/developer_manual/basics/events.html).
