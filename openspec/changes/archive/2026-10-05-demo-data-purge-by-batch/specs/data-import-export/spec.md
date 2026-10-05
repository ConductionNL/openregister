## ADDED Requirements

### Requirement: An app configuration import MUST run under its own import job id

Every call to `importFromApp()` SHALL generate a fresh import job id (UUID v4) and SHALL
stamp it on every audit row written while the import runs, for created and for updated
objects alike. The stamp SHALL be cleared when the import ends, including when it throws,
and a stamp that was already active before the call (an outer import) SHALL be restored
rather than cleared. The import result SHALL carry the id as `importJobId` when the job was
recorded (see the next requirement), and `null` otherwise.

@e2e exclude Backend import path with no UI of its own; the consuming app's setup wizard owns the button. Asserted in tests/Unit/Service/Configuration/AppImportJobRecorderTest.php (testBeginStampsAndEndRestoresTheOuterScope) and tests/Unit/Service/Configuration/ImportHandlerImportJobTest.php (testImportFromAppStampsTheImportAndClearsTheStamp, testTheStampIsClearedWhenTheImportThrows). Covered by PHPUnit.

#### Scenario: Objects written by an app import carry the job id

- **GIVEN** an app calls `importFromApp()` with data holding seed objects
- **WHEN** the import creates two objects and updates one
- **THEN** the three audit rows MUST carry the same import job id
- **AND** after the call no import job id MUST be active

#### Scenario: A failing import does not leak its stamp

- **GIVEN** an app import that throws halfway
- **WHEN** the exception leaves `importFromApp()`
- **THEN** no import job id MUST be active afterwards

---

### Requirement: The job id of an app import that created objects MUST be recorded per app

After an app import, OpenRegister SHALL count the `create` audit rows carrying the job id.
When there is at least one, it SHALL append `{jobId, version, created, importedAt}` to the
list it keeps in its own app config for that app id (the `appId` passed to
`importFromApp()`, so `learniq` and `learniq.demo` keep separate lists). An import that
created nothing traceable SHALL NOT be recorded, so re-imports that only update or skip do
not grow the list. The list SHALL keep at most the 50 most recent jobs.

When the import wrote objects and no audit row at all carries the job id, OpenRegister
SHALL log a warning naming the app: the audit trail is off, so the import cannot be removed
by job. A silent empty list would read as "nothing to remove".

@e2e exclude Backend bookkeeping with no UI of its own. Asserted in tests/Unit/Service/Configuration/AppImportJobRecorderTest.php (testRecordAppendsAJobThatCreatedObjects, testRecordSkipsAJobThatCreatedNothing, testRecordWarnsWhenObjectsWereWrittenButNothingWasTraced, testRecordKeepsTheFiftyMostRecentJobs). Covered by PHPUnit.

#### Scenario: A first demo import is recorded

- **GIVEN** `importFromApp('learniq.demo', ...)` creates 405 objects with audit trails on
- **WHEN** the import returns
- **THEN** the `learniq.demo` list MUST hold one job with `created` 405
- **AND** the result's `importJobId` MUST be that job's id

#### Scenario: A re-import that only updates is not recorded

- **GIVEN** a second import of the same data whose objects all exist already
- **WHEN** it returns
- **THEN** the list MUST still hold one job

#### Scenario: An untraceable import says so

- **GIVEN** audit trails are disabled and an import writes objects
- **WHEN** it returns
- **THEN** no job MUST be recorded
- **AND** a warning MUST be logged naming the app

---

### Requirement: An app MUST be able to remove the objects its recorded imports created

`ConfigurationService::listImportJobs($appId)` SHALL return the recorded list for that app id.
`ConfigurationService::softDeleteAppImports($appId)` SHALL soft-delete, through
`ImportService::softDeleteByImportJobId()`, every object each recorded job created, and
SHALL run as a system operation, as the import did. A job whose report has no errors SHALL
be forgotten; a job with errors SHALL stay recorded so the removal can be retried or
finished with `occ`. The result SHALL name the app, list each job's report, and total the
soft-deleted objects and the errors.

Objects a job only updated SHALL NOT be removed: they existed before the import. Removal is
a soft delete, so an object can be restored from the trash. Who may remove is the calling
app's decision; this method is not reachable over HTTP.

@e2e exclude Backend service call; the consuming app's setup wizard owns the button. Asserted in tests/Unit/Service/ConfigurationServiceAppImportsTest.php (testSoftDeleteAppImportsRemovesEveryRecordedJobAndForgetsCleanOnes, testAJobWithErrorsStaysRecorded, testAnAppWithNoRecordedJobsRemovesNothing). Covered by PHPUnit.

#### Scenario: Removing an example set

- **GIVEN** `learniq.demo` has two recorded jobs that created 405 and 5 objects
- **WHEN** `softDeleteAppImports('learniq.demo')` runs
- **THEN** 410 objects MUST be soft-deleted
- **AND** the `learniq.demo` list MUST be empty afterwards
- **AND** the `learniq` list MUST be untouched

#### Scenario: A partial removal stays recorded

- **GIVEN** one object of a recorded job cannot be deleted
- **WHEN** `softDeleteAppImports()` runs
- **THEN** that job MUST stay in the list
- **AND** the result MUST name the object and the error

---

### Requirement: The HTTP rollback route MUST refuse an app import's job id

`POST` to the import rollback route SHALL answer `409` when the job id belongs to a
recorded app import, and SHALL delete nothing. Archival schemas refuse HTTP deletes, and an
app's example data spans archival and append-only schemas, so it leaves through the app's
own service call or through `occ openregister:objects:purge --import-job`, never through
HTTP. The response SHALL name those two paths. CSV and Excel import rollbacks SHALL keep
working unchanged.

@e2e exclude REST refusal with no UI surface; asserted in tests/Unit/Controller/RegistersControllerTest.php (testRollbackRefusesAnAppImportJob). Covered by PHPUnit.

#### Scenario: An admin tries to roll back a demo import over HTTP

- **GIVEN** a job id recorded for `learniq.demo`
- **WHEN** an administrator posts it to the rollback route
- **THEN** the response MUST be `409`
- **AND** no object MUST be deleted
