# App-declared webhook events Specification (delta)

## Purpose

Another app hands its own events to OpenRegister's webhook delivery, and
the event catalogue lists them, without OpenRegister naming the app's
classes.

## ADDED Requirements

### Requirement: An app declares its own event for webhook delivery

`AppWebhookEvents::declare()` MUST subscribe OpenRegister's webhook
listener to the declared event class, once per class per request. It
MUST refuse, and subscribe nothing, a class that does not exist, is not a
Nextcloud event, lives in OpenRegister's own namespace, or whose payload
method is missing, not public, static or requires an argument.

#### Scenario: A declared event is subscribed

- GIVEN an app event class with a public `getPayload()` method
- WHEN the app declares it from `boot()`
- THEN the webhook listener is subscribed to that class once

#### Scenario: A built-in event cannot be shadowed

- GIVEN `OCA\OpenRegister\Event\ObjectCreatedEvent`
- WHEN an app declares it
- THEN the declaration is refused and nothing is subscribed

### Requirement: A declared event is delivered with its own payload

When a declared event is dispatched and a webhook names its class, the
webhook listener MUST hand the array the declared payload method returns
to the webhook service unchanged. An undeclared app event, or a payload
method returning no array, MUST NOT be delivered.

#### Scenario: The app's payload reaches the webhook

- GIVEN a declared event and a webhook subscribed to its class
- WHEN the app dispatches the event
- THEN the webhook service receives the event class and exactly the app's payload

### Requirement: The event catalogue lists declared events

`GET /api/webhooks/events` MUST return the built-in events unchanged and
in their order, followed by each declared event with its class, name,
description, category, type `after` and properties `["payload"]`.

#### Scenario: The catalogue shows an app's events

- GIVEN an app declared one event
- WHEN the catalogue is requested
- THEN it holds the built-in events followed by the declared one, and `total` counts both
