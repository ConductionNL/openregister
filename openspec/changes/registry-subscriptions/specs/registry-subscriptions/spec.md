# registry-subscriptions

## ADDED Requirements

### Requirement: A schema declares which registry owns which properties

A schema MAY declare `x-openregister-registry` naming a registry id, an
identity property and the properties the registry owns. Schema save SHALL
refuse an annotation whose identity or owned properties the schema does not
declare.

#### Scenario: an owned property missing from the schema is refused

- **GIVEN** a schema `person` declaring `x-openregister-registry` with owned property `birthDate` that the schema lacks
- **WHEN** the schema is saved
- **THEN** the save is refused naming `birthDate`
- @e2e exclude {schema validation is a service boundary covered by unit tests}

### Requirement: An object carries a subscription state a user can request or end

An object of a registry schema SHALL carry `@self.registry` with state
`none`, `requested`, `active` or `ended`, the time and source of the last
registry update, and any refusal reason. A user with `update` on the object
SHALL be able to request or end the subscription; each SHALL dispatch an
event for a connector and SHALL be audited.

#### Scenario: requesting a subscription dispatches the event

- **GIVEN** a `person` object with state `none` and a user with `update`
- **WHEN** they request a subscription
- **THEN** `@self.registry.state` is `requested` and a `RegistrySubscriptionRequestedEvent` carries the object uuid and identity value
- @e2e exclude {proposal only; task 4.2 adds tests/e2e/api-direct/registry-subscriptions.spec.ts when the endpoints ship}

### Requirement: An inbound registry update writes owned properties only

The system SHALL expose an authenticated inbound update endpoint per
registry that resolves the object by identity value, applies the supplied
owned properties through the ordinary save path with the registry as actor,
records the update time and event reference, and SHALL refuse with 422 a
property the annotation does not list as owned.

#### Scenario: a BRP move updates the address and nothing else

- **GIVEN** an active `person` object with owned properties including `address`
- **WHEN** the connector posts an update with a new `address` and the BRP event reference
- **THEN** the object's `address` changes, the audit entry names registry `brp` as actor, and `@self.registry.lastUpdate` holds the event reference
- @e2e exclude {proposal only; task 4.2 adds tests/e2e/api-direct/registry-subscriptions.spec.ts when the endpoint ships}

#### Scenario: an update to a local property is refused

- **GIVEN** the same object, whose `notes` property is not owned
- **WHEN** the connector posts an update touching `notes`
- **THEN** the request is refused with 422 naming `notes` and nothing is written
- @e2e exclude {the owned-property guard is covered by unit tests}

### Requirement: Subscription state and freshness are query lenses

The object query SHALL accept `_registry[state]` and
`_registry[updatedBefore]` so that stale or unsubscribed registry objects
can be listed.

#### Scenario: a steward lists stale persons

- **GIVEN** two active persons, one updated a year ago and one yesterday
- **WHEN** the list is queried with `_registry[state]=active` and `_registry[updatedBefore]` of a month ago
- **THEN** only the person updated a year ago is returned
- @e2e exclude {lenses are covered by unit tests on the query parser}
