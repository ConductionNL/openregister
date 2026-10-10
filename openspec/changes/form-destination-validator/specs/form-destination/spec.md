# form-destination Delta: form-destination-validator

**Status**: draft
**Scope**: OpenRegister validator, submit service and route, upload tokens, schema-save check. Canonical requirements: `hydra/openspec/changes/form-submits-into-its-destination-object/specs/form-destination/spec.md`.

## ADDED Requirements

### Requirement: OpenRegister MUST judge a form's mapping against its destination schema

`FormDestinationValidator::validate(mapping, schema, options)` SHALL return one finding per problem, each `{ field?, property, code, message }`. The mapping is `{ fields: [ { field, property, type?, format?, options?, maxLength?, minLength?, maximum?, minimum?, pattern? } ], fixed: { property: value } }`. It SHALL report a required property (schema `required` list or property `required: true`) with no field, no fixed value, no `default`, no `computed` and no `x-openregister.serverSet` marker as `required-unmapped`; a field or fixed value into a property the schema lacks as `property-unknown`, unless the schema's configuration sets `additionalProperties: true` (OpenRegister schemas carry no top-level `additionalProperties`, so "forbids extras" would never hold); a field into a read-only property as `property-read-only`; a type or format that cannot produce the property's as `type-mismatch` or `format-mismatch`; a free-text field into an enum as `enum-unconstrained`; a choice option outside the enum as `enum-value-unknown`; a field bound looser than the property's (or missing where the property sets one) as `constraint-looser`; a fixed value the property refuses as `fixed-value-invalid`; a form for a `public` or `authenticated` audience into a schema whose authorization does not grant that audience create as `destination-not-public`; and a schema whose configuration sets `staging: true` as `destination-is-staging`. It SHALL extend `TaskFormReader::validate` so flow task forms and object forms are judged by one service. `POST /api/forms/validate` SHALL return `{ accepted, findings }` for an author; a mapping with findings is not accepted, from the first release (Q9, Ruben, 10 October 2026: no report-only release).

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

`FormSubmitService::submit(destination, mapping, payload, subject, idempotencyKey, scope)` and `submitAll(writes, payload, subject, idempotencyKey, scope)` SHALL map the payload through the mapping (only mapped fields and fixed values reach the object; without a mapping the payload minus `_` and `@` keys is the object), validate it with the destination schema's full validator regardless of the schema's hard-validation flag (properties the server fills are excused from `required`), then create the object under the subject's RBAC; an anonymous submit is saved unowned. It SHALL return `reference` (the property marked `x-openregister.reference: true`, else the uuid), `id`, `receivedAt` (the object's created moment), a `confirmation` map of every property marked `x-openregister.confirmation: true`, read after every create listener ran, and `objects[]` listing each write. A refused payload SHALL answer 422 `{ message, findings }`; a subject not allowed to create 403; an unknown destination 404; an unreachable destination 503 "try again later" with nothing created (Q3). `POST /api/forms/{formId}/submit` SHALL wrap it for forms stored in OpenRegister, with `#[PublicPage]`, `#[AnonRateLimit]`, `#[UserRateLimit]` and `#[BruteForceProtection]` per ADR-082; an unknown, unpublished or destination-less form SHALL answer one identical 404 and register a brute-force attempt; a filled honeypot field `_hp` SHALL answer 202 and store nothing; a form whose `audience` is not `public` SHALL answer 401 to an anonymous visitor. A form stored in OpenRegister is any object whose body carries `status: published` (or `published: true`), `destination: { register, schema }` or `writes[]`, and optionally `mapping` and `audience`.

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

`POST /api/forms/{formId}/uploads` SHALL accept one file and the destination `property` it is for, check it against that property's file rules (`maxSize` in bytes or `fileConfiguration.maxSize` in MB, `allowedTypes` or `fileConfiguration.allowedMimeTypes`, the same reader the save path uses), and return `{ token, expiresAt }`. A submit SHALL claim tokens by a payload value `{ "uploadToken": "<token>" }` for the property the token was issued for, and only after its last write succeeded, so a refused submit leaves the files for the retry. Unclaimed tokens SHALL be deleted after 24 hours by an hourly background job that logs its count, a zero included.

#### Scenario: An oversized file is refused at upload

- **GIVEN** a destination file rule of 10 MB
- **WHEN** a 12 MB file is uploaded
- **THEN** the response is 422 and no token is issued

### Requirement: Saving a schema MUST re-check the forms that submit into it

When a schema's properties or required list are saved, OpenRegister SHALL dispatch `FormDestinationDependentsEvent` with the proposed definition; each owning app adds its published forms into that schema, and the validator SHALL run for each. The owning app's setting `formDestinationBreak` (`unpublish`, the default, or `refuse`) SHALL decide the outcome (Q7): any `refuse` blocks the save with 409 listing the forms and nothing is unpublished; otherwise, after the save, each broken form is unpublished through its owning app, its author is notified (`form_unpublished`), and the save response SHALL list every affected form with its outcome and findings under `affectedForms`. This applies from the first release (Q9).

#### Scenario: A new required property unpublishes a form that lacks it

- **GIVEN** a published form into `ticket` without `priority`, and the default setting
- **WHEN** `priority` becomes required
- **THEN** the form is unpublished, the response names it, and its author is notified

### Requirement: A create refusal MUST use the per-property error shape

`ObjectsController::create` SHALL answer a validation failure through the same handler update and patch use, so the body is the same `{ status, message, errors: [{ property, message }] }` and the status is the same (400 today; moving all three to 422 is question Q-openregister-F1).

#### Scenario: Create and update refuse alike

- **GIVEN** an object payload missing a required property
- **WHEN** it is sent to create and, for an existing object, to update
- **THEN** both answers carry the property in `errors[]` with the same status

### Requirement: An object MUST be able to carry the explicit lifecycle status `draft`

Object metadata SHALL carry a stored `@self.status` with at least the values `draft` and `active` (decision 180). An object without the field SHALL keep its status deduced from its dates, as today. Saving an object in `draft` SHALL run every schema rule except `required`. Moving an object from `draft` to `active` SHALL go through `FormSubmitService`, SHALL run full validation, and SHALL fire the create-time effects that count as receipt (reference, `receivedAt`, listeners marked as receipt listeners). A form's draft save SHALL create or update its destination object in `draft`.

#### Scenario: A draft without required data is saved
- **GIVEN** schema `case` requiring `description`
- **WHEN** a form saves a draft with no `description`
- **THEN** the object exists with `@self.status` `draft` and no `receivedAt`

#### Scenario: A type-invalid draft is refused
- **GIVEN** a property `preferredDate` of format date-time
- **WHEN** a draft is saved with `preferredDate` holding a phone number
- **THEN** the save is refused with a `format-mismatch` finding and nothing is stored

#### Scenario: Leaving draft runs full validation and stamps receipt
- **GIVEN** a draft that now holds every required property
- **WHEN** it is submitted
- **THEN** `@self.status` becomes `active`, `receivedAt` is set, and the response carries the reference and confirmation fields

#### Scenario: An object without the field reads as today
- **GIVEN** an object created before this change, with no stored status
- **WHEN** it is read
- **THEN** its status is deduced from its dates exactly as before

### Requirement: The form destination check MUST refuse from the first release

Form save, journey save and schema save SHALL refuse on a finding from the first release that ships the validator. There SHALL be no report-only mode (decision 181).

#### Scenario: A form with one finding is not saved
- **GIVEN** a form with one `required-unmapped` finding
- **WHEN** it is saved
- **THEN** the save is refused and the finding is returned
