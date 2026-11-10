# Tasks: archival-for-apps

- [x] 1.1 `RetentionService::destructionRefusal()`; the sweep's eligibility reads it.
- [x] 1.2 `DestructionListCreator::createFor()` and `POST /api/archival/destruction-lists`.
- [x] 1.3 `DestructionListRepository::findCertificates()` and a real `GET /api/archival/certificates`.
- [x] 1.4 `SelectionListSeeder::seed()` and `components.selectionLists` in `ImportHandler::importFromJson()`.
- [x] 1.5 Tests, red on development first: `DestructionListCreatorTest`, `ArchivalCertificatesTest`, `SelectionListSeederTest`, `ImportHandlerSelectionListsTest`.
