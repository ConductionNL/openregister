---
status: proposed
---

# Webhooks

## ADDED Requirements

### Requirement: An update payload carries the object before and after

The webhook payload of `ObjectUpdatedEvent` SHALL carry `newObject`, the
serialised object after the update, and `oldObject`, the serialised object
before it, or null when the event has no old object. The keys `objectType`,
`action`, `object`, `objectUuid`, `register`, `schema` and `timestamp` SHALL
keep their values.

#### Scenario: A filter matches only the update that made the change

- **GIVEN** a webhook on `ObjectUpdatedEvent` with the filters `{"oldObject.status": "draft", "newObject.status": "final"}`
- **WHEN** an object moves from `draft` to `final`, and is then edited again
- **THEN** the first update passes the filters
- **AND** the edit after it does not

#### Scenario: An update without an old object

- **GIVEN** an `ObjectUpdatedEvent` without an old object
- **WHEN** its payload is built
- **THEN** `oldObject` is null and `object` equals `newObject`
