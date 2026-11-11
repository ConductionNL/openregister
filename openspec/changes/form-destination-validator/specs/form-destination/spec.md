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

`FormSubmitService::submit(destination, mapping, payload, subject, idempotencyKey, scope)` and `submitAll(writes, payload, subject, idempotencyKey, scope)` SHALL map the payload through the mapping (only mapped fields and fixed values reach the object; without a mapping the payload minus `_` and `@` keys and minus `id` and `uuid` is the object; a submit never chooses the uuid of the object it writes, so an `id` or `@self.id` is dropped from the object whatever the mapping says, and the only way to name an existing object is the draft id), validate it with the destination schema's full validator regardless of the schema's hard-validation flag (properties the server fills are excused from `required`), then create the object under the subject's RBAC; an anonymous submit is saved unowned. It SHALL return `reference` (the property marked `x-openregister.reference: true`, else the uuid), `id`, `receivedAt` (the object's created moment), a `confirmation` map of every property marked `x-openregister.confirmation: true`, read after every create listener ran, and `objects[]` listing each write. A refused payload SHALL answer 422 `{ message, findings }`; a subject not allowed to create 403; an unknown destination 404; an unreachable destination 503 "try again later" with nothing created (Q3). `POST /api/forms/{formId}/submit` SHALL wrap it for forms stored in OpenRegister, with `#[PublicPage]`, `#[AnonRateLimit]`, `#[UserRateLimit]` and `#[BruteForceProtection]` per ADR-082; an unknown, unpublished or destination-less form SHALL answer one identical 404 and register a brute-force attempt; a filled honeypot field `_hp` SHALL answer 202 and store nothing; a form whose `audience` is not `public` SHALL answer 401 to an anonymous visitor. A form stored in OpenRegister is any object whose body carries `status: published` (or `published: true`), `destination: { register, schema }` or `writes[]`, and optionally `mapping` and `audience`.

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
- **WHEN** the same key is submitted again within 24 hours by the same subject with the same payload
- **THEN** the response repeats object 7's reference and no object is created

#### Scenario: A key is not reserved on an instance without a locking provider

- **GIVEN** an instance whose locking provider is the no-op one (no memory cache configured for locking)
- **WHEN** two submits with the same key run at the same time
- **THEN** the key is not reserved, so both can write; the stored answer still repeats for every retry after the first submit finished, and the store logs once per process that a locking cache (`memcache.locking`) closes this gap

#### Scenario: A repeated key from somebody else, or with another payload, is refused

- **GIVEN** a submit with key `k1` that created object 7
- **WHEN** another subject, or the same subject with another payload, submits key `k1` within 24 hours
- **THEN** the response is 409, carries nothing of object 7's answer, and no object is created

#### Scenario: A parallel retry with the same key is refused

- **GIVEN** a submit with key `k1` that is still being written
- **WHEN** the same key arrives again before it finishes
- **THEN** the second request is answered 409 and only one object is created

### Requirement: A submit writing several objects MUST commit all or none

The service SHALL validate every write before the first. When a write is refused after earlier writes succeeded, it SHALL delete those earlier writes before responding, and SHALL audit both the create and the delete.

#### Scenario: A refused second write deletes the first

- **GIVEN** writes `organisation` then `contact`, where `contact` breaks a uniqueness rule
- **WHEN** submitted
- **THEN** the response is 422 naming the `contact` finding, and the organisation no longer exists

### Requirement: Upload tokens MUST hold bytes only and expire

`POST /api/forms/{formId}/uploads` SHALL accept one file and the destination `property` it is for, check it against that property's file rules (`maxSize` in bytes or `fileConfiguration.maxSize` in MB, `allowedTypes` or `fileConfiguration.allowedMimeTypes`, the same reader the save path uses), and return `{ token, expiresAt }`. A submit SHALL claim tokens by a payload value `{ "uploadToken": "<token>" }` for the property the token was issued for, and only after its last write succeeded, so a refused submit leaves the files for the retry. A draft save SHALL claim its tokens the same way once the draft is written, because the files then sit on the draft. A property that declares no `maxSize` SHALL still be capped at 20 MiB (`FormUploadStore::DEFAULT_MAX_BYTES`), because the upload endpoint is a public page; a property that declares a limit, larger or smaller, keeps it. A token claimed for a draft SHALL leave a record of its form, property and draft for 24 hours, and a later autosave or the final submit of THAT draft that still carries the same token SHALL be satisfied by it (the property stays as the draft stores it) instead of refused 422; a token claimed by another draft, issued for another property or form, expired, or never issued stays unknown. Unclaimed tokens SHALL be deleted after 24 hours by an hourly background job that logs its count, a zero included.

#### Scenario: An oversized file is refused at upload

- **GIVEN** a destination file rule of 10 MB
- **WHEN** a 12 MB file is uploaded
- **THEN** the response is 422 and no token is issued

### Requirement: Saving a schema MUST re-check the forms that submit into it

When a schema's properties or required list are saved, OpenRegister SHALL dispatch `FormDestinationDependentsEvent` with the proposed definition; each owning app adds its published forms into that schema, and the validator SHALL run for each. The owning app's setting `formDestinationBreak` (`unpublish`, the default, or `refuse`) SHALL decide the outcome (Q7): any `refuse` blocks the save with 409 listing the forms and nothing is unpublished; otherwise, after the save, each broken form is unpublished through its owning app, its author is notified (`form_unpublished`), and the save response SHALL list every affected form with its outcome and findings under `affectedForms`. This applies from the first release (Q9). It holds for every writer of a schema, not only the schema API: a schema written by the configuration importer, the import endpoint or the AI and MCP tools has no response to refuse, so the broken forms are unpublished and their authors notified there too (an owning app's `refuse` setting is logged as not honourable), and the schema API, which judges the forms itself, claims its own save so no form is unpublished twice.

#### Scenario: A new required property unpublishes a form that lacks it

- **GIVEN** a published form into `ticket` without `priority`, and the default setting
- **WHEN** `priority` becomes required
- **THEN** the form is unpublished, the response names it, and its author is notified

#### Scenario: A schema import that adds a required property unpublishes a form that lacks it

- **GIVEN** a published form into `ticket` without `priority`
- **WHEN** a configuration import makes `priority` required on `ticket`
- **THEN** the form is unpublished and its author is notified, as for an edit through the schema API

### Requirement: A create refusal MUST use the per-property error shape

`ObjectsController::create` SHALL answer a validation failure through the same handler update and patch use, so the body is the same `{ status, message, errors: [{ property, message }] }` and the status is the same (400 today; moving all three to 422 is question Q-openregister-F1).

#### Scenario: Create and update refuse alike

- **GIVEN** an object payload missing a required property
- **WHEN** it is sent to create and, for an existing object, to update
- **THEN** both answers carry the property in `errors[]` with the same status

### Requirement: An object MUST be able to carry the explicit lifecycle status `draft`

Object metadata SHALL carry a stored `@self.status` with at least the values `draft` and `active` (decision 180). An object without the field SHALL keep its status deduced from its dates, as today. Saving an object in `draft` SHALL run every schema rule except `required`. Moving an object from `draft` to `active` SHALL go through `FormSubmitService` or through the owner's activation (decision 216, change `draft-owner-activation-and-lifetime`), SHALL run full validation, and SHALL fire the create-time effects that count as receipt (reference, `receivedAt`, listeners marked as receipt listeners). A plain save SHALL NOT move an object out of `draft`. A form's draft save SHALL create or update its destination object in `draft`.

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

### Requirement: A draft MUST be able to be owned by a scope value

A draft's owner SHALL be either a Nextcloud user or a scope value `<scope>:<reference>`, such as `portal:<subjectRef>` for a resident signed in at a portal who has no Nextcloud account (decision 195). A scope is lower case letters, digits and hyphens; the whole value SHALL fit the owner column (64 characters). A Nextcloud user id cannot hold a colon, so a scope value never names a user. A draft owned by a scope value SHALL be found, read, saved and sent only by a caller asserting that same owner; every other caller, a signed-in user included, SHALL get the same 404 an unknown draft id gets. The owner assertion SHALL be the authorisation for every write of that draft, its start included: starting it SHALL NOT need the destination schema to allow public create, because the in-process caller vouches for its subject, as it does when it creates the object outright. Only a draft is authorised this way; a fresh submit without a draft SHALL stay under the destination's RBAC. An owner assertion SHALL be reachable in process only: no controller builds one or forwards an explicit owner to a save, so a request cannot authorise its own draft. The draft clause in the read RBAC and the draft lens on lists keep such a draft out of every list, as they keep any draft to its owner. Generic by construction (decision 182): the owner value carries no app or procedure logic.

#### Scenario: A portal subject saves, reads and sends its own draft
- **GIVEN** a caller asserting owner `portal:subject-1`
- **WHEN** it saves a draft, reads it back by id, finds it by destination, and sends it
- **THEN** the draft is stored with owner `portal:subject-1` and status `draft`, each read answers its data, and the send moves it to `active`

#### Scenario: Anyone else gets 404
- **GIVEN** a draft owned by `portal:subject-1`
- **WHEN** a caller asserting `portal:subject-2`, or a signed-in user, reads, saves or sends it by id
- **THEN** each answers 404 and the draft is unchanged

#### Scenario: A scope-owned draft starts where the public may not create
- **GIVEN** a destination schema that does not let the public create
- **WHEN** an in-process caller asserting owner `portal:subject-1` saves a first draft, and later sends it
- **THEN** the draft is stored with that owner and the send moves it to `active`
- **AND** an anonymous submit without a draft into the same destination still answers 403

#### Scenario: A request cannot name the owner
- **WHEN** a draft save or a submit arrives over HTTP with `_owner` or `@self.owner` in its body
- **THEN** the service is handed the session user only, and neither key reaches the payload

#### Scenario: A malformed scope value is refused
- **WHEN** an owner is built from an empty reference, a scope with upper case or a colon, or a value longer than 64 characters
- **THEN** it is refused before anything is read or written

### Requirement: A draft MUST be able to be discarded by its owner

The owner of a draft SHALL be able to discard it (decisions 180 and 195). The discard SHALL admit only an object in status `draft` whose stored owner is exactly the asserted owner; a sent object, someone else's draft, an unknown id and an unknown destination SHALL all answer "not found" and remove nothing. A discarded draft SHALL be deleted permanently: it holds unsent answers and nothing refers to it. Generic by construction (decision 182): the caller names the destination and the owner, OpenRegister knows no app or procedure.

#### Scenario: The owner discards its draft
- **GIVEN** a draft owned by `portal:subject-1`
- **WHEN** a caller asserting `portal:subject-1` discards it
- **THEN** the object is gone, not soft-deleted

#### Scenario: Anything else is left alone
- **GIVEN** a draft owned by `portal:subject-2` and an active object owned by `portal:subject-1`
- **WHEN** a caller asserting `portal:subject-1` discards either
- **THEN** both answer "not found" and both objects remain

### Requirement: A list MUST hide drafts unless asked

An object list or search SHALL leave out objects in status `draft` by default (decision 201), whoever asks and by whichever path: an administrator and an app's service listing with RBAC off get the same work list. The caller's own drafts SHALL stay in the list. The query parameter `_drafts` SHALL change it, following `_archived`: absent or false is the work list, `_drafts=true` is drafts alone, `_drafts=any` is drafts beside the rest, `_drafts=none` leaves out every draft including the caller's own (decision 216), and an unrecognised value SHALL read as the work list. A query that filters on the stored status (`@self[status]`) without the parameter has named its statuses, so that filter SHALL decide. Counts and facets SHALL follow the same lens as the rows. The lens is a default on what a list shows and SHALL NOT widen what a caller may read: asking for drafts shows the drafts the read RBAC already lets that caller reach. Reading one object by id is not a list and is unchanged. Generic by construction (decision 182).

#### Scenario: A work list leaves drafts out
- **GIVEN** schema `case` with one active object and one draft owned by somebody else
- **WHEN** an app's service lists `case` with RBAC off, or an administrator lists it
- **THEN** the list, its total and its facets hold the active object only

#### Scenario: The owner still sees their own drafts
- **GIVEN** a draft owned by the signed-in caller
- **WHEN** the caller lists the schema without `_drafts`
- **THEN** their draft is in the list beside the objects that are not drafts

#### Scenario: The filter shows them
- **WHEN** the list is asked with `_drafts=true`, with `_drafts=any`, or with `@self[status]=draft`
- **THEN** it answers drafts alone, drafts beside the rest, and the drafts the status filter names, each within what the caller may read

#### Scenario: A typo does not widen the list
- **WHEN** the list is asked with `_drafts=al`
- **THEN** it answers the work list without drafts

### Requirement: The form destination check MUST refuse from the first release

Form save, journey save and schema save SHALL refuse on a finding from the first release that ships the validator. There SHALL be no report-only mode (decision 181).

#### Scenario: A form with one finding is not saved
- **GIVEN** a form with one `required-unmapped` finding
- **WHEN** it is saved
- **THEN** the save is refused and the finding is returned
