# Tasks: notification-links-in-releases-and-case-insensitive-order

## 1. Deep links from any process

- [x] 1.1 `DeepLinkRegistryService::resolve()` dispatches `DeepLinkRegistrationEvent` once when the registry is empty and has not asked yet, so a process that never booted OpenRegister still resolves a leaf app's deep link. `Application::boot()` asks through the same method, so a booted process asks once. Verify: `tests/Unit/Service/DeepLinkRegistryBackgroundTest.php` (real registry, real event, a real listener on a dispatcher double) fails on the old code.
- [x] 1.2 `GenericDeepLinkRegistrationListener` logs a warning when the leaf app ships no `src/manifest.json`. Verify: the same test.

## 2. Fallback to OpenRegister's object view

- [x] 2.1 `AnnotationNotifier` falls back to `/apps/openregister/objects/{register}/{schema}/{uuid}` instead of the dead `#/registers/...` hash. Verify: `tests/Unit/Notification/AnnotationNotifierLinkTest.php` fails on the old code.
- [x] 2.2 `AnnotationNotificationDispatcher::buildObjectDetailLink()` falls back to the same object view, not the origin app's hash route. Verify: `tests/Unit/Service/Notification/DispatcherObjectDetailFallbackTest.php` fails on the old code.

## 3. Case-insensitive ordering

- [x] 3.1 `MagicSearchHandler::applySorting()` orders a plain string property (not a date), a translatable property, and `_name`, `_description`, `_summary` (also as `@self.*`) by `LOWER(...)`. Verify: `tests/Unit/Db/MagicSearchHandlerTranslatableSortTest.php` runs the ORDER BY against SQLite with mixed-case rows; it fails on the old code.

## 4. Translatable placeholders

- [x] 4.1 `NotificationTemplating::interpolate()` and `unanswered()` resolve a language map: recipient language (and its base), then the register's languages, then the first value. The dispatcher passes the recipient locale and the register's languages. Verify: `tests/Unit/Service/Notification/TranslatablePlaceholderTest.php` fails on the old code (5 of 6).

## 5. Related without a full scan

- [x] 5.0 `getUses()` locates the related UUIDs with `findMultipleAcrossAllMagicTables()` and `getUsedBy()` locates references with `findByRelationAcrossAllMagicTables()`; each then reads only the matching tables through `findAllInRegisterSchemaTable()` as before. Register and schema loads are cached per request. Verify: `tests/Unit/Service/Object/RelationHandlerScalesWithRelationsTest.php` fails on the old code (2 of 2); live on :8099 for lead 09071313-4c14-420e-a419-d3f2a7d270b8 the same two objects come back for each, `/uses` and `/used` about 1.1 s each against 2.1 to 3.3 s for the old code on the same warm instance (7 s under load earlier).

## 6. Live

- [x] 6.1 On :8099, in a PHP process that loads Nextcloud but no apps (as `occ background-job:worker` does), the registry resolves the pipelinq client deep link (`/apps/pipelinq/clients/{uuid}`); with the old registry the same probe returns null.
- [x] 6.2 On :8099 the fallback `/index.php/apps/openregister/objects/20/28/{uuid}` opens that client in OpenRegister's object view.
- [x] 6.3 On :8099 `_order={"name":"asc"}` on pipelinq clients lists "Gemeente Voorbeeld" before "GGD Rotterdam-Rijnmond" (case-insensitive).
- [ ] 6.4 After ConductionNL/.github#832 is merged and a pipelinq beta is released: `GET /apps/openregister/api/manifest/pipelinq` on cloud.conduction.nl answers 200 and a "Client changed" notification opens the client. (live pass, decision 139)
