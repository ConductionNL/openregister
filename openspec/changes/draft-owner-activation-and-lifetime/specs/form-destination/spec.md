# Form destination: the owner leaves draft, and drafts expire

Delta on `form-destination` (change `form-destination-validator`), from decision 216.

## MODIFIED Requirements

### Requirement: An object MUST be able to carry the explicit lifecycle status `draft`

Object metadata SHALL carry a stored `@self.status` with at least the values `draft` and `active` (decision 180). An object without the field SHALL keep its status deduced from its dates, as today. Saving an object in `draft` SHALL run every schema rule except `required`. Moving an object from `draft` to `active` SHALL go through `FormSubmitService` or through the owner's activation (decision 216), SHALL run full validation, and SHALL fire the create-time effects that count as receipt (reference, `receivedAt`, listeners marked as receipt listeners). A plain save SHALL NOT move an object out of `draft`. A form's draft save SHALL create or update its destination object in `draft`.

#### Scenario: A plain save still cannot leave draft
@e2e exclude unit; DraftStatusPolicyTest and DraftActivationTest (the allowance does not outlive the activation) cover the refusal, the Newman collection openregister-draft-activation covers it over HTTP

- **GIVEN** a draft and its owner
- **WHEN** the owner saves it with PUT or PATCH and `@self.status` set to `active`
- **THEN** the save is refused and the object is still a draft

### Requirement: A list MUST hide drafts unless asked

An object list or search SHALL leave out objects in status `draft` by default (decision 201), whoever asks and by whichever path: an administrator and an app's service listing with RBAC off get the same work list. The caller's own drafts SHALL stay in the list. The query parameter `_drafts` SHALL change it, following `_archived`: absent or false is the work list, `_drafts=true` is drafts alone, `_drafts=any` is drafts beside the rest, `_drafts=none` leaves out every draft including the caller's own (decision 216), and an unrecognised value SHALL read as the work list. A query that filters on the stored status (`@self[status]`) without the parameter has named its statuses, so that filter SHALL decide. Counts and facets SHALL follow the same lens as the rows. The lens is a default on what a list shows and SHALL NOT widen what a caller may read. Reading one object by id is not a list and is unchanged. Generic by construction (decision 182).

#### Scenario: A work list without the caller's own drafts
@e2e exclude unit; DraftListLensTest covers the lens and its clause, the Newman collection openregister-draft-activation covers it over HTTP

- **GIVEN** a draft owned by the signed-in caller and an active object in the same schema
- **WHEN** the caller lists the schema with `_drafts=none`
- **THEN** the list holds the active object and not the caller's draft
- **AND** the same list without the parameter holds both

## ADDED Requirements

### Requirement: A draft's owner MUST be able to make it definitive through the object API

The owner of a draft SHALL be able to move it to `active` with `POST /api/objects/{register}/{schema}/{id}/activate` (decision 216). The action SHALL be explicit: no field on a PUT or PATCH does the same. The request MAY carry `object`, property values laid over the stored draft before it is judged; `@self` and `id` in it SHALL be ignored. The object SHALL then pass every rule of its schema, whatever the schema's hard-validation flag, with the same excusals the form submit applies (a property with a default, a computation or the `serverSet` marker). On success the object SHALL keep its id, SHALL be stored with `@self.status` `active`, `ObjectActivatedEvent` SHALL be dispatched with the moment of receipt, and the answer SHALL be the object with HTTP 200.

Only the owner SHALL be admitted: the object's stored owner is exactly the asserted owner (decision 195). Over HTTP the asserted owner is the signed-in user and the request cannot name another. An administrator is not the owner. In-process callers MAY assert a scope value through `DraftActivation`. The save SHALL run under RBAC for a user owner.

A refusal SHALL change nothing and SHALL answer `{ message, reason, findings }` with an HTTP error status that reaches the caller unchanged: 401 `not-signed-in`, 404 `not-found` (unknown, or not readable by the caller), 409 `not-a-draft`, 403 `not-the-owner`, 422 `invalid` with one finding `{ property, code, message }` per property. Generic by construction (decision 182): the caller names the register, the schema and the object, OpenRegister knows no app or procedure.

#### Scenario: The owner makes a complete draft definitive
@e2e exclude unit; DraftActivationTest and ObjectDraftControllerTest cover it, the Newman collection openregister-draft-activation asserts the delivered 200 over HTTP

- **GIVEN** a draft the signed-in user owns, holding every required property
- **WHEN** the user posts to its `activate` action
- **THEN** the answer is 200 with the same id and `@self.status` `active`
- **AND** `ObjectActivatedEvent` is dispatched once

#### Scenario: An incomplete draft is refused per field
@e2e exclude unit; DraftActivationTest runs a real schema validation, the Newman collection openregister-draft-activation asserts the delivered 422 and its findings

- **GIVEN** a draft that misses the required properties `subject` and `channel`
- **WHEN** its owner activates it
- **THEN** the answer is 422 with `reason` `invalid` and a `required` finding for `subject` and one for `channel`
- **AND** the object is still a draft and no event is dispatched

#### Scenario: Somebody else is refused with a real error status
@e2e exclude unit; DraftActivationTest covers the owner check and ObjectDraftControllerTest the plain Controller, the Newman collection openregister-draft-activation asserts the delivered 403 from a caller's side

- **GIVEN** a draft owned by another user, readable by an administrator
- **WHEN** the administrator activates it
- **THEN** the caller receives HTTP 403, not 200, with `reason` `not-the-owner`
- **AND** the object is still a draft

#### Scenario: What the caller may not read does not exist
@e2e exclude unit; ObjectDraftControllerTest covers the read through the RBAC boundary

- **GIVEN** a draft the caller may not read, and an id that does not exist
- **WHEN** the caller activates either
- **THEN** both answer 404 with `reason` `not-found`

#### Scenario: A scope-value owner activates its own draft
@e2e exclude unit; DraftActivationTest covers the scope-value owner and its control

- **GIVEN** a draft owned by `portal:subject-1`
- **WHEN** in-process code activates it asserting `portal:subject-1`
- **THEN** it becomes `active`
- **AND** the same call asserting `portal:subject-2` is refused with `not-the-owner`

### Requirement: A schema MUST be able to set a draft lifetime

A schema SHALL be able to declare `draftLifetimeDays` in its configuration: a whole number of days, 1 or more (decision 216). The key SHALL survive a schema save, an import and an export. Any other value (zero, a negative number, a fraction, text) SHALL NOT be stored and SHALL be reported as a dropped key, so a mistyped lifetime never reads as "remove every draft now". A schema without the key has no draft lifetime and its drafts SHALL be kept.

#### Scenario: The lifetime survives an import and an export
@e2e exclude unit; DraftLifetimeImportExportTest runs the real import and export handlers, with an undeclared key as its control

- **GIVEN** an app's register file whose schema sets `configuration.draftLifetimeDays` to 7
- **WHEN** the file is imported, the register is exported and the export is imported again
- **THEN** the schema's draft lifetime is 7 after each step

#### Scenario: A value that is no lifetime is refused
@e2e exclude unit; SchemaFormConfigurationTest covers every refused value

- **WHEN** a schema is saved with `draftLifetimeDays` 0, -7, 1.5 or "a week"
- **THEN** the key is not stored, it is reported as dropped, and the rest of the configuration is kept

### Requirement: One background job MUST remove drafts past their schema's lifetime

OpenRegister SHALL run one background job, declared where it declares its jobs (`appinfo/info.xml`), once a day (decision 216). For every schema that sets `draftLifetimeDays`, in every register that carries it, the job SHALL remove each object in status `draft` whose last change (`updated`, else `created`) is older than that many days. It SHALL remove nothing else: an object that is not a draft, a draft of a schema without the setting, and a draft without any date SHALL stay. Removal SHALL go through the ordinary object delete (`ObjectService::deleteObject()`), so its guards apply and the draft's files go with it; a delete that path refuses SHALL be logged and counted, and the sweep SHALL carry on. The job SHALL log its counts, a zero included, and SHALL surface a failure rather than report success. Generic by construction (decision 182): no app carries a draft cleanup of its own.

#### Scenario: An abandoned draft is removed
@e2e exclude unit; DraftExpiryJobTest starts from the job appinfo/info.xml declares and runs it down to the delete, DraftExpiryTest covers the cutoff

- **GIVEN** schema `ticket` with `draftLifetimeDays` 7, a draft last changed eight days ago and a draft changed yesterday
- **WHEN** the job runs
- **THEN** the first draft is deleted through the ordinary delete and the second stays

#### Scenario: No setting, no removal
@e2e exclude unit; DraftExpiryTest covers a schema without the setting

- **GIVEN** schema `case` without `draftLifetimeDays` and a draft last changed years ago
- **WHEN** the job runs
- **THEN** the draft stays and the schema's drafts are not even listed

#### Scenario: The job is registered
@e2e exclude unit; DraftExpiryJobTest fails when appinfo/info.xml does not declare the job

- **WHEN** `appinfo/info.xml` is read
- **THEN** it declares exactly one background job that runs the draft expiry

### Requirement: A draft MUST be deletable on every schema, and its delete MUST be permanent

A draft is not yet a record (decisions 180 and 216): it holds unsent input, nothing refers to it, and it was never received. The ordinary object delete SHALL therefore admit an object stored in status `draft` on a schema that declares `x-openregister-archival`, where every other user delete is refused. It SHALL admit nothing else: an object in any other status, an object without a stored status, and an object the caller's lookup cannot reach SHALL be refused as before. Every other rule of the delete SHALL still apply (the caller's delete permission, append-only, referential integrity). Deleting a draft SHALL remove it permanently, with its files, on every schema: no tombstone of unsent input is kept. A saved object can never return to `draft`, so no record can be made deletable this way.

#### Scenario: A draft on an archival schema is removed for good
@e2e exclude unit; ObjectServiceDraftDeleteTest drives the real delete path

- **GIVEN** schema `ticket` with an `x-openregister-archival` block and a stored draft
- **WHEN** its owner, or the draft cleanup, deletes it
- **THEN** the delete is not refused and the object is removed permanently

#### Scenario: A record on the same schema is still refused
@e2e exclude unit; ObjectServiceDraftDeleteTest covers active, no stored status and an unreachable object as controls

- **GIVEN** the same schema and an object in status `active`, or without a stored status
- **WHEN** a user deletes it
- **THEN** the delete is refused with `SCHEMA_ARCHIVAL_IMMUTABLE`, as before

#### Scenario: An ordinary delete stays a soft delete
@e2e exclude unit; ObjectServiceDraftDeleteTest covers the control

- **GIVEN** a schema without an archival block and an object that is not a draft
- **WHEN** it is deleted
- **THEN** it is soft-deleted as before
