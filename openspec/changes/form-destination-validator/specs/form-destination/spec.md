# form-destination Delta: form-destination-validator

**Status**: draft
**Scope**: OpenRegister validator, submit service and route, upload tokens, schema-save check. Canonical requirements: `hydra/openspec/changes/form-submits-into-its-destination-object/specs/form-destination/spec.md`.

## ADDED Requirements

### Requirement: OpenRegister MUST judge a form's mapping against its destination schema

`FormDestinationValidator::validate(mapping, schema)` SHALL return one finding per problem, each `{ field?, property, code, message }`. It SHALL report a required property with no field, no fixed value, no `default` and no `x-openregister.serverSet` marker as `required-unmapped`; a field into a property the schema lacks as `property-unknown` when the schema forbids additional properties; a type or format that cannot produce the property's as `type-mismatch` or `format-mismatch`; a free-text field into an enum as `enum-unconstrained`; a choice option outside the enum as `enum-value-unknown`; a field constraint looser than the property's as `constraint-looser`; a fixed value the property refuses as `fixed-value-invalid`. It SHALL extend `TaskFormReader::validate` so flow task forms and object forms are judged by one service.

#### Scenario: A required property with no source is reported

- **GIVEN** schema `case` requiring `title` and `caseType`
- **AND** a mapping covering `title` only
- **WHEN** the validator runs
- **THEN** it returns one finding, property `caseType`, code `required-unmapped`

#### Scenario: A property a listener fills is not reported

- **GIVEN** schema `case` requiring `identifier`, marked `x-openregister.serverSet: true`
- **WHEN** a mapping without `identifier` is validated
- **THEN** no finding names `identifier`

#### Scenario: A flow task form uses the same service

- **GIVEN** a user-task step whose form names a read-only property
- **WHEN** the step is saved
- **THEN** the refusal comes from `FormDestinationValidator`, and `TaskFormReader` delegates to it

### Requirement: A submit MUST create the destination in one request and return its reference

`FormSubmitService::submit(destination, mapping, payload, subject, idempotencyKey)` SHALL validate the payload with the destination schema's full validator, regardless of the schema's hard-validation flag, then create the object under the subject's RBAC. It SHALL return `reference`, `id`, `receivedAt` and a `confirmation` map of every property marked `x-openregister.confirmation: true`, read after every create listener ran. `POST /api/forms/{formId}/submit` SHALL wrap it for forms stored in OpenRegister, with `#[PublicPage]`, `#[AnonRateLimit]` and `#[UserRateLimit]` per ADR-082.

#### Scenario: The response carries the listener-computed fields

- **GIVEN** a destination whose `termStartsAt` is set by an `ObjectCreatingEvent` listener and marked `x-openregister.confirmation: true`
- **WHEN** a valid payload is submitted
- **THEN** the response is 201 and `confirmation.termStartsAt` holds the listener's value

#### Scenario: A schema without hard validation is still validated

- **GIVEN** a destination schema with hard validation off
- **WHEN** a payload missing a required property is submitted
- **THEN** the response is 422 with a `required` finding and nothing is created

#### Scenario: A repeated idempotency key repeats the answer

- **GIVEN** a submit with key `k1` that created object 7
- **WHEN** the same key is submitted again within 24 hours
- **THEN** the response repeats object 7's reference and no object is created

### Requirement: A submit writing several objects MUST commit all or none

The service SHALL validate every write before the first. When a write is refused after earlier writes succeeded, it SHALL delete those earlier writes before responding, and SHALL audit both the create and the delete.

#### Scenario: A refused second write deletes the first

- **GIVEN** writes `organisation` then `contact`, where `contact` breaks a uniqueness rule
- **WHEN** submitted
- **THEN** the response is 422 naming the `contact` finding, and the organisation no longer exists

### Requirement: Upload tokens MUST hold bytes only and expire

`POST /api/forms/{formId}/uploads` SHALL accept one file, check it against the destination's file rules, and return a token. A submit SHALL claim tokens by id. Unclaimed tokens SHALL be deleted after 24 hours by a background job that logs its count.

#### Scenario: An oversized file is refused at upload

- **GIVEN** a destination file rule of 10 MB
- **WHEN** a 12 MB file is uploaded
- **THEN** the response is 422 and no token is issued

### Requirement: Saving a schema MUST re-check the forms that submit into it

When a schema is saved, the validator SHALL run for every published form whose destination it is. The owning app's setting `formDestinationBreak` (`unpublish`, the default, or `refuse`) SHALL decide the outcome. The save response SHALL list every affected form with its findings.

#### Scenario: A new required property unpublishes a form that lacks it

- **GIVEN** a published form into `ticket` without `priority`, and the default setting
- **WHEN** `priority` becomes required
- **THEN** the form is unpublished, the response names it, and its author is notified

### Requirement: A create refusal MUST use the per-property error shape

`ObjectsController::create` SHALL answer a validation failure with the same `{ status, message, errors: [{ property, message }] }` body that update and patch use, with HTTP 422.

#### Scenario: Create and update refuse alike

- **GIVEN** an object payload missing a required property
- **WHEN** it is sent to create and, for an existing object, to update
- **THEN** both answers carry the property in `errors[]` with the same status
