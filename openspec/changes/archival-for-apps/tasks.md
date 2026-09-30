# Tasks: archival-for-apps

- [ ] 1.1 `RetentionService::destructionRefusal()`; the sweep's eligibility reads it.
- [ ] 1.2 `DestructionListCreator::createFor()` and `POST /api/archival/destruction-lists`.
- [ ] 1.3 `DestructionListRepository::findCertificates()` and a real `GET /api/archival/certificates`.
- [ ] 1.4 `SelectionListSeeder::seed()` and `components.selectionLists` in `ImportHandler::importFromJson()`.
- [ ] 1.5 Tests, red on development first: `DestructionListCreatorTest`, `ArchivalCertificatesTest`, `SelectionListSeederTest`, `ImportHandlerSelectionListsTest`.
