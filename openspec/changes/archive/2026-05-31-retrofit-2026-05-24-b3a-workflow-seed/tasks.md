# Tasks: Bucket 3a investigation — workflow/seed (5 REQs)

Retroactive annotations. Each task records the canonical spec requirement and
the code that implements it.

- [x] task-1: approval-workflow#REQ-003 — List and filter approval steps (retroactive annotation)
      `lib/Controller/ApprovalController.php::steps()`, `lib/Db/ApprovalStepMapper.php::findAllFiltered()`
- [x] task-2: archival-destruction-workflow#REQ-005 — Destruction Certificate Generation (retroactive annotation)
      `lib/Service/RetentionService.php::generateDestructionCertificate()`, `lib/BackgroundJob/DestructionExecutionJob.php::run()`
- [x] task-3: seed-related-items#REQ-03 — Process Related Items After Object Creation (retroactive annotation)
      `lib/Service/Configuration/ImportHandler.php::importSeedData()`, `::processRelatedItems()`
- [x] task-4: seed-related-items#REQ-04 — Note Seeding (retroactive annotation)
      `lib/Service/Configuration/ImportHandler.php::processRelatedItems()`
- [x] task-5: seed-related-items#REQ-10 — Logging (retroactive annotation)
      `lib/Service/Configuration/ImportHandler.php::importSeedData()`, `::processRelatedItems()`
