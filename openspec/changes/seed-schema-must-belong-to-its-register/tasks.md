## 1. Guard

- [x] 1.1 `seedSchemaIsForeign(Register, Schema, ?appId)` in `ImportHandler`, with a warning naming register, schema and owner.
- [x] 1.2 Call it in the `components.objects` loop (count as `skipped.objects`), in `resolveImportRegisterSchema()` (return a null schema) and in `importSeedDataObjects()` (count as `skipped.seedObjects`).

## 2. Verification

- [x] 2.1 `tests/Unit/Service/Configuration/ImportHandlerForeignSeedSchemaTest.php` drives the real `importFromJson()`: the stale `organization` seed is skipped while the sibling `catalog` seed is saved (fails on the old code); the app's own unlinked schema, an ownerless schema and a cross-app seed into a register that lists the schema are all still saved.
- [x] 2.2 The Configuration unit suite stays green.
- [ ] 2.3 Live: install opencatalogi on a stack where stackiq is installed; the `default-org` seed is skipped with the warning and no row lands in a publication x organization table.
