## MODIFIED Requirements

### Requirement: Validation events MUST be dispatched for notification and extensibility
The reference validation pipeline MUST dispatch Nextcloud events via `IEventDispatcher` at key points, allowing other apps and listeners to react to validation outcomes. The dispatched event value-objects (`ReferenceValidatedEvent`, `ReferenceValidationFailedEvent`) MUST expose the validated reference context — property name, referenced UUID, target schema slug, and target register — via getters so listeners can route on it without re-deriving it from the object data.

#### Scenario: Validation failure event dispatched
- GIVEN a save that fails reference validation
- WHEN the `ValidationException` is about to be thrown
- THEN a `ReferenceValidationFailedEvent` MUST be dispatched with:
  - The object data that was being saved
  - The property name, invalid UUID, and target schema
  - The register and schema context
- AND other apps MAY listen to this event for custom notification or logging

#### Scenario: Validation success event dispatched for monitored schemas
- GIVEN a schema with `configuration.emitValidationEvents: true`
- AND a save succeeds with all references validated
- WHEN the save completes
- THEN a `ReferenceValidationSucceededEvent` MUST be dispatched with the validated property names and UUIDs
- AND this event MUST only be dispatched when `emitValidationEvents` is enabled (performance optimization)

#### Scenario: Event listeners do not block the save pipeline
- GIVEN a registered listener for `ReferenceValidationFailedEvent`
- AND the listener throws an exception
- WHEN the event is dispatched
- THEN the exception MUST be caught and logged
- AND the original validation error MUST still be returned to the client
- AND the save pipeline MUST NOT be affected by listener failures

#### Scenario: Validation event value-objects expose the reference context via getters
- GIVEN a `ReferenceValidatedEvent` or `ReferenceValidationFailedEvent` is dispatched for property `assignee` referencing UUID `person-uuid` in target schema slug `person` within register `procest`
- WHEN a listener reads the event
- THEN `getPropertyName()` MUST return `"assignee"`
- AND `getReferencedUuid()` MUST return `"person-uuid"`
- AND `getTargetSchemaSlug()` MUST return `"person"` (the slug or raw `$ref`)
- AND `getTargetRegister()` MUST return `"procest"`, or `null` when no register context applied to the lookup
