# File Actions Specification (delta)

## ADDED Requirements

### Requirement: Adding a file to an object dispatches an event (REQ-OFAE-001)

When a file is added to an object through the files API (`files#create` with content or with a `source` URL, and `files#createMultipart`), OpenRegister MUST dispatch `OCA\OpenRegister\Event\FileAddedEvent` after the file is stored, once per stored file, carrying the object uuid, the file id and the data `fileName`, `register` and `schema`. A listener that throws MUST NOT fail the add or change its answer. A failed add MUST NOT dispatch.

#### Scenario: Posting a file dispatches the event
<!-- @e2e exclude Event contract; proven by FilesControllerFileAddedEventTest::testCreatingAFileDispatchesFileAdded, which fails on main because no event is dispatched. -->

- **GIVEN** an object `obj-1` in register 5, schema 9
- **WHEN** `besluit.pdf` is posted to its files
- **THEN** one `FileAddedEvent` is dispatched after the add, with object `obj-1`, the new file id and data `{fileName: besluit.pdf, register: 5, schema: 9}`

#### Scenario: A multipart upload dispatches per file
<!-- @e2e exclude Event contract; proven by FilesControllerFileAddedEventTest::testEveryUploadedFileDispatchesFileAdded. -->

- **WHEN** two files are uploaded in one multipart request
- **THEN** two `FileAddedEvent`s are dispatched, each after its own add

#### Scenario: A failing listener does not fail the add
<!-- @e2e exclude Listener isolation; proven by FilesControllerFileAddedEventTest::testAFailingListenerDoesNotFailTheAdd. -->

- **GIVEN** a listener of `FileAddedEvent` that throws
- **WHEN** a file is posted
- **THEN** the answer is 200 with the file
