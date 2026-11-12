## ADDED Requirements

### Requirement: A register's folder is created on its first upload, by whoever uploads (REQ-RFFU-001)

When a file is added to an object whose register has no folder yet, the system SHALL create the register's folder and record its id on the register as part of that upload. Recording the folder id SHALL NOT require permission to update registers and SHALL NOT depend on the caller's active organisation, so an upload without a Nextcloud session (a portal request) succeeds on a fresh instance. The folder id SHALL come from the folder the system created or found at the register's conventional path, never from request data. Whether the caller may upload to the object at all is unchanged.

#### Scenario: The first upload without a session creates and records the register folder

- **GIVEN** a register with no folder and a request with no Nextcloud user
- **AND** the caller is not allowed to update registers
- **WHEN** a file is added to one of the register's objects
- **THEN** the folder `Open Registers/<title> Register` is created in the OpenRegister system user's files
- **AND** its id is recorded on the register
- **AND** the upload does not fail on a register permission or organisation check
- @e2e exclude {backend folder provisioning with no OpenRegister UI; PHPUnit drives it with a fake root, and portaliq's portal-document-download e2e runs it live once its exclusion is lifted}

#### Scenario: A second upload reuses the recorded folder

- **GIVEN** a register whose folder was created and recorded by an earlier upload
- **WHEN** a file is added to another object in that register
- **THEN** no new register folder is created
- **AND** the recorded folder id is not written again
- @e2e exclude {backend folder reuse, covered by PHPUnit with a fake root}

#### Scenario: Two first uploads racing share one folder

- **GIVEN** two first uploads into the same register
- **AND** the other upload creates the register folder after this one found none
- **WHEN** this upload's folder creation is refused because the folder now exists
- **THEN** this upload uses the existing folder and succeeds
- @e2e exclude {a race cannot be staged in a browser; covered by PHPUnit with a fake root}

### Requirement: Recording a register's folder id is bookkeeping (REQ-RFFU-002)

Recording the folder id SHALL change only the register's folder field, on the register the upload resolved. It SHALL NOT change any other field, SHALL NOT change the register's version, and SHALL NOT dispatch a register-updated event, so no activity entry, webhook or notification says the register was edited. It SHALL only take effect while the stored folder field is still empty or holds the value read before the folder was made, so it never overwrites a folder id another request recorded first.

#### Scenario: Only the folder field is written, and no update event fires

- **GIVEN** a register with no folder
- **WHEN** its folder id is recorded
- **THEN** only the folder field of that register changes
- **AND** no register-updated event is dispatched
- @e2e exclude {write shape and event absence, covered by PHPUnit}

#### Scenario: A folder id recorded by another request is left alone

- **GIVEN** a request that read the register while it had no folder
- **AND** another request recorded a folder id since
- **WHEN** this request records its folder id
- **THEN** the stored folder id stays the one the other request recorded
- **AND** this request's upload still succeeds
- @e2e exclude {compare-and-set write, covered by PHPUnit}
