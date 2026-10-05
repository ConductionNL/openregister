## ADDED Requirements

### Requirement: Recording an object's folder id is bookkeeping (REQ-OFIB-001)

When the system makes the files folder of an object outside a save, it SHALL record the folder id by changing only the object's folder field, on that one object. It SHALL NOT change any other field and SHALL NOT change the object's version. It SHALL NOT dispatch an object updating or updated event. It SHALL NOT write an audit trail entry, as for a register's folder id (REQ-RFFU-002). It SHALL only take effect while the stored folder field is still empty or holds the value read before the folder was made.

#### Scenario: Only the folder field is written, and no lifecycle event fires

- **GIVEN** an object with no files folder
- **WHEN** its folder id is recorded
- **THEN** only the folder field of that object changes
- **AND** no object updating or updated event is dispatched
- @e2e exclude {write shape and event absence, covered by PHPUnit in MagicMapperRecordFolderTest}

#### Scenario: A folder id recorded by another request is left alone

- **GIVEN** a request that read the object while it had no folder
- **AND** another request recorded a folder id since
- **WHEN** this request records its folder id
- **THEN** the stored folder id stays the one the other request recorded
- **AND** this request still gets the object's folder
- @e2e exclude {compare-and-set write, covered by PHPUnit in MagicMapperRecordFolderTest and FolderManagementHandlerObjectFolderBookkeepingTest}

### Requirement: Reading an object's files never saves the object (REQ-OFIB-002)

Listing or reading the files of an object SHALL NOT run save-time logic on that object. This holds when the read is the first one and the object's files folder has to be made. Save-time logic includes calculations, quality scoring, retention and any listener for object updating or updated events.

#### Scenario: The first file listing on an object without a folder

- **GIVEN** an object with no files folder
- **AND** a request with no Nextcloud session, such as a portal listing a case's documents
- **WHEN** the object's files are listed
- **THEN** the folder is made and its id is recorded on the object
- **AND** the object is not saved, so no save-time listener runs
- @e2e exclude {backend read path with no OpenRegister UI; PHPUnit drives the real handler with a fake root in FolderManagementHandlerObjectFolderBookkeepingTest}

#### Scenario: A legacy folder path is replaced without a save

- **GIVEN** an object whose folder field holds a legacy path instead of a node id
- **WHEN** the object's files are listed
- **THEN** the path is replaced by the new folder id, only while it is still the stored value
- **AND** the object is not saved
- @e2e exclude {legacy data shape, covered by PHPUnit in FolderManagementHandlerObjectFolderBookkeepingTest}
