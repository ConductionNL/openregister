# Tasks: legal-hold-per-matter

- [x] 1.1 `LegalHoldLedger` places and releases holds per owner key on a retention array; a single slot reads as a list of one.
- [x] 1.2 `LegalHoldService::placeHold()` / `releaseHold()` take an optional owner key and write through the ledger.
- [x] 1.3 `RetentionService::placeLegalHold()` / `releaseLegalHold()` write through the same ledger.
- [x] 1.4 `ArchivalController` and `RetentionController` hold endpoints accept `ownerKey`.
- [x] 1.5 Tests: two matters, release one, stored single slot (`LegalHoldPerMatterTest`), with the real service over a real `ObjectEntity`.
