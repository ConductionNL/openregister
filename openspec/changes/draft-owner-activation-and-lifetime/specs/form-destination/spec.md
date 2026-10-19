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

#### Scenario: A stored empty value is a property nobody filled in
@e2e exclude unit; DraftActivationTest covers the stored nulls, the Newman collection openregister-draft-activation asserts the `required` finding on a draft read back from its table

- **GIVEN** a stored draft whose table holds null for `channel`, for a property with a default and for a server-set property
- **WHEN** its owner activates it
- **THEN** the finding for `channel` has code `required`, not a wrong-type finding about null
- **AND** the property with a default and the server-set property get no finding

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

#### Scenario: The register of another organisation does not hide the owner's draft
@e2e exclude unit; ObjectDraftControllerTest covers the lookup with mappers that answer as the real ones do, the Newman collection openregister-draft-activation runs after openregister-crud has switched multitenancy on

- **GIVEN** multitenancy is on, and an administrator made the register and the schema in their organisation
- **AND** a user of another organisation made a draft in them, which they can read
- **WHEN** that user activates it
- **THEN** the register and the schema are found as the object API finds them, and the draft's own read decides
- **AND** the answer is not 404

#### Scenario: A scope-value owner activates its own draft
@e2e exclude unit; DraftActivationTest covers the scope-value owner and its control

- **GIVEN** a draft owned by `portal:subject-1`
- **WHEN** in-process code activates it asserting `portal:subject-1`
- **THEN** it becomes `active`
- **AND** the same call asserting `portal:subject-2` is refused with `not-the-owner`

### Requirement: A draft MUST NOT be changed by somebody who cannot read it

A draft answers to its owner only, unless its schema shows drafts to others (`draftsVisible`). A save under RBAC whose own lookup does not find the object SHALL look the uuid up once more past the read rule, and SHALL refuse the write when it is a draft kept from the caller. So a PATCH, and a create that names the draft's id, cannot write over somebody else's draft or return its content. The refusal is `NotAuthorizedException`, delivered as 403. A save with RBAC off (an administrator, a job, a scope-value owner, decision 195) is judged by its caller, as before. A stored object that is not a draft, and a uuid nobody stored, are judged as before.

#### Scenario: A colleague cannot change a draft through PATCH
@e2e exclude unit; ObjectServiceDraftWriteTest covers the save with the mapper answering as the read rule does, the Newman collection openregister-draft-activation asserts the delivered refusal and the unchanged draft from a third account

- **GIVEN** a draft owned by one user, on a schema every signed-in user may create and update in
- **WHEN** another user, not an administrator, sends a PATCH to it, or a POST that names its id
- **THEN** the write is refused with 403
- **AND** the draft holds what its owner typed

#### Scenario: The rule is about drafts only
@e2e exclude unit; ObjectServiceDraftWriteTest covers each control

- **GIVEN** the same save on a uuid nobody stored, on a stored object that is not a draft, with RBAC off, and on a schema with `draftsVisible`
- **WHEN** it runs
- **THEN** each is judged as it was before this requirement

### Requirement: A draft lifetime MUST default to thirty days, and a schema MUST be able to change it or opt out

A draft SHALL have a lifetime of 30 days unless its schema says otherwise (decisions 201 and 228). A schema SHALL be able to declare `draftLifetimeDays` in its configuration: a whole number of days, 1 or more, sets another lifetime; `false` opts out and the schema's drafts SHALL be kept. An absent key or `null` SHALL mean the default. The key SHALL survive a schema save, an import and an export, the value `false` included. Any other value (zero, a negative number, a fraction, `true`, text) SHALL be refused: it SHALL be stored as `false` and reported as a dropped key, so a typing error keeps drafts and never reads as "remove every draft now" or "remove after 30 days". A stored value nobody can read SHALL keep the drafts too.

#### Scenario: No setting means thirty days
@e2e exclude unit; DraftStatusPolicyTest covers the default, DraftExpiryTest the cutoff to the second

- **GIVEN** schema `case` without `draftLifetimeDays`, a draft last changed 30 days and one second ago and a draft last changed exactly 30 days ago
- **WHEN** the cleanup runs
- **THEN** the first draft is removed and the second stays

#### Scenario: A schema opts out
@e2e exclude unit; SchemaFormConfigurationTest covers the stored value, DraftExpiryTest that the schema is not even listed

- **GIVEN** schema `memo` with `draftLifetimeDays: false` and a draft last changed years ago
- **WHEN** the cleanup runs
- **THEN** the draft stays and the schema's drafts are not listed

#### Scenario: The lifetime and the opt-out survive an import and an export
@e2e exclude unit; DraftLifetimeImportExportTest runs the real import and export handlers, with an undeclared key as its control

- **GIVEN** an app's register file with a schema that sets `configuration.draftLifetimeDays` to 7 and one that sets it to `false`
- **WHEN** the file is imported, the register is exported and the export is imported again
- **THEN** the first schema's draft lifetime is 7 and the second keeps its drafts, after each step

#### Scenario: A value that is no lifetime keeps the drafts
@e2e exclude unit; SchemaFormConfigurationTest covers every refused value

- **WHEN** a schema is saved with `draftLifetimeDays` 0, -7, 1.5, `true` or "a week"
- **THEN** the key is stored as `false`, it is reported as dropped, and the rest of the configuration is kept

### Requirement: One background job MUST remove drafts past their schema's lifetime

OpenRegister SHALL run one background job, declared where it declares its jobs (`appinfo/info.xml`), once a day (decision 216). For every schema that stores its own objects and has a draft lifetime, in every register that carries it, the job SHALL remove each object in status `draft` whose last change (`updated`, else `created`) is older than that lifetime. It SHALL remove nothing else: an object that is not a draft, a draft of a schema that opts out, and a draft without any date SHALL stay. A schema served from an object source SHALL NOT be asked for drafts. Removal SHALL go through the ordinary object delete (`ObjectService::deleteObject()`), so its guards apply and the draft's files go with it; a delete that path refuses SHALL be logged and counted, and the sweep SHALL carry on.

The work of one run SHALL be bounded (decision 228): at most 1,000 delete attempts per run, and at most 5,000 drafts read per register and schema, the store being asked for the drafts past their lifetime alone (last changed before the cutoff, or never changed and created before it) so that the rows read are expired rows. What is left, beyond the delete attempts and beyond the rows read, SHALL be counted as deferred and SHALL wait for the next run, so that expired = removed + refused + deferred. The job SHALL log one line per register and schema that held an expired draft (register, schema, lifetime, expired, removed, refused, deferred) and one line with the totals, a zero included, at a level the default log level shows (warning) whenever a draft was removed or refused, and SHALL surface a failure rather than report success. Generic by construction (decision 182): no app carries a draft cleanup of its own.

Because the default applies to drafts that exist at the upgrade, the first run after it removes drafts that were kept until then. The changelog and the release notes SHALL say so.

#### Scenario: An abandoned draft is removed
@e2e exclude unit; DraftExpiryJobTest starts from the job appinfo/info.xml declares and runs it down to the delete, DraftExpiryTest covers the cutoff

- **GIVEN** schema `ticket` with `draftLifetimeDays` 7, a draft last changed eight days ago and a draft changed yesterday
- **WHEN** the job runs
- **THEN** the first draft is deleted through the ordinary delete and the second stays
- **AND** the log holds a line for `ticket` with its counts

#### Scenario: One run is bounded
@e2e exclude unit; DraftExpiryTest covers the limit and the deferred count

- **GIVEN** 1,002 expired drafts over two schemas
- **WHEN** the job runs
- **THEN** 1,000 are removed and 2 are counted as deferred

#### Scenario: The job is registered
@e2e exclude unit; DraftExpiryJobTest fails when appinfo/info.xml does not declare the job

- **WHEN** `appinfo/info.xml` is read
- **THEN** it declares exactly one background job that runs the draft expiry

### Requirement: An administrator MUST be able to see what the draft cleanup would remove

OpenRegister SHALL offer `occ openregister:drafts:expired` (decision 228). It SHALL list, per register and schema, how many drafts a run of the cleanup at that moment would remove and the lifetime that applies, and SHALL remove nothing. `--json` SHALL print the same counts as JSON. A schema that opts out SHALL NOT be listed. The command SHALL say how a schema keeps its drafts.

#### Scenario: A look before the first run after an upgrade
@e2e exclude unit; DraftsExpiredCommandTest runs the command over the real sweep and counts the deletes, DraftExpiryTest checks that the sweep removes exactly what the preview counted

- **GIVEN** schema `case` without a setting, holding two drafts older than 30 days and one of three days
- **WHEN** an administrator runs `occ openregister:drafts:expired`
- **THEN** the output names `case` with 2 drafts and 30 days
- **AND** no draft is removed

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
- **AND** the delete handler is not reached, so nothing is removed and nothing is recorded as removed

#### Scenario: An ordinary delete stays a soft delete
@e2e exclude unit; ObjectServiceDraftDeleteTest covers the control

- **GIVEN** a schema without an archival block and an object that is not a draft
- **WHEN** it is deleted
- **THEN** it is soft-deleted as before

### Requirement: Removing a draft MUST leave one audit-trail entry without its content

The permanent delete of an object in status `draft` SHALL write exactly one entry on OpenRegister's audit trail (decision 226), through the audit trail's own entry point (`AuditTrailMapper::createAuditTrailEntry()`), so it is hash-chained and shows where other audit entries show. The entry SHALL carry the action `draft.removed`, the actor, the moment, the register, the schema and the object's id and uuid. The actor SHALL be the signed-in user, or `job:<name>` when a job removed the draft (`job:DraftExpiryJob` for the cleanup). Beside its columns the entry SHALL say that the object was a draft (`status: draft`, `permanent: true`), the register's and the schema's slug, the job's name, and the draft's owner. The entry SHALL NOT hold any property value or property name of the draft. A permanent delete of an object that is not a draft SHALL NOT write this entry. When the entry cannot be written, the removal stands and the same facts SHALL be logged as an error, without the content.

The entries the draft's own saves wrote before the removal are not changed by this requirement.

#### Scenario: A user removes a draft
@e2e exclude unit; DraftRemovalRecordTest drives the real delete handler, DraftRemovalAuditRowTest reads the row back from the migrated audit table

- **GIVEN** a draft on schema `ticket` holding a subject and an e-mail address
- **WHEN** its owner deletes it
- **THEN** the audit trail holds one entry `draft.removed` with the owner as actor, register `service`, schema `ticket` and the draft's id
- **AND** no column of the entry holds the subject, the e-mail address or their property names

#### Scenario: The cleanup removes a draft
@e2e exclude unit; DraftExpiryJobTest covers the actor of the removal, DraftRemovalAuditRowTest the stored row

- **GIVEN** a draft past its schema's lifetime
- **WHEN** the cleanup removes it
- **THEN** the entry's actor is `job:DraftExpiryJob`

#### Scenario: A record leaves no draft entry
@e2e exclude unit; DraftRemovalRecordTest covers the control

- **GIVEN** an object in status `active`
- **WHEN** it is permanently deleted
- **THEN** no `draft.removed` entry is written

### Requirement: A draft MUST NOT notify anybody until it leaves draft

The notification rules a schema declares (`x-openregister-notifications`) SHALL NOT fire for an object stored in status `draft`: not its first save, not its later saves (decisions 180 and 216). When a draft leaves draft, through the form submit or the owner's activation, its `created` rules SHALL fire once, as if the object had been created at that moment; the save that left draft SHALL NOT also fire `updated` rules. An object created outright is announced as before.

#### Scenario: A half-typed object notifies nobody
@e2e exclude unit; AnnotationNotificationListenerTest covers the draft's create and update, with an object created outright as control

- **GIVEN** schema `ticket` with a `created` rule that notifies a group
- **WHEN** an agent's draft ticket is saved and saved again
- **THEN** nobody is notified

#### Scenario: Leaving draft announces it once
@e2e exclude unit; AnnotationNotificationListenerTest covers the activation and the update event that comes with it

- **GIVEN** the same draft
- **WHEN** its owner makes it definitive
- **THEN** the `created` rule fires once, with the object as it was sent

### Requirement: A schema MUST be able to keep the caller's own drafts out of its lists

A schema SHALL be able to declare `draftsListed: false` in its configuration (decision 216). Lists, searches, counts and facets of that schema SHALL then leave out every draft by default, the caller's own included, on every query path. A caller who asks (`_drafts=true`, `_drafts=any`, or a filter on `@self[status]`) SHALL still get the drafts the read RBAC lets them reach. A schema without the key, or with `true`, SHALL keep the caller's own drafts in its lists, as decision 201 set. The key SHALL survive a schema save, an import and an export. It is for a schema whose drafts are offered back on their own entry screen, so a half-typed entry never sits in a work list or its total.

#### Scenario: A work list of such a schema holds no half-typed entry
@e2e exclude unit; DraftListLensTest and MagicSearchHandlerDraftLensTest cover the default on both query paths, with a schema that does not set the key as control

- **GIVEN** schema `ticket` with `draftsListed: false` and a draft the signed-in agent owns
- **WHEN** the agent lists the schema without `_drafts`
- **THEN** the draft is not in the list, its total or its facets
- **AND** with `_drafts=true` the list holds the draft
