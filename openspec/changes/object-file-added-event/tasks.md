# Tasks: object-file-added-event

## 1. Event and dispatch (REQ-OFAE-001)

- [x] 1.1 Add `lib/Event/FileAddedEvent.php` beside the other `AbstractObjectFileEvent` classes. Verify: `tests/Unit/Event/ObjectFileEventsTest.php` data set `added`.
- [x] 1.2 Dispatch it from `FilesController` after `create` (content and `source`) and per file of `createMultipart`; a throwing listener does not fail the add. Verify: `tests/Unit/Controller/FilesControllerFileAddedEventTest.php::testCreatingAFileDispatchesFileAdded`, `::testEveryUploadedFileDispatchesFileAdded`, `::testAFailingListenerDoesNotFailTheAdd`, `::testAFailedAddDispatchesNothing` (three fail on main: `Tests: 4, Assertions: 9, Failures: 3.`); the files-actions golden regenerated with `UPDATE_GOLDEN=1`, its diff only the dispatch line on 31 cases.

## 2. Cross-app

- [ ] 2.1 OpenCatalogi listens and stamps the publisher's RSIN (`opencatalogi/publications-name-their-responsible-organisation` task 2.2d), after this has merged.

## V. Verification

- [x] V.1 The touched unit tests pass: `ObjectFileEventsTest`, `FilesControllerFileAddedEventTest` and every `FilesController*Test`.
- [ ] V.2 Full unit suite and gates in CI (the suite needs a Nextcloud tree, which the cloud sandbox lacks).
