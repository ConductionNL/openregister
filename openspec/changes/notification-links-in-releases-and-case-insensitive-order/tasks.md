# Tasks: notification-links-in-releases-and-case-insensitive-order

## 1. Deep links from any process

- [x] 1.1 `DeepLinkRegistryService::resolve()` dispatches `DeepLinkRegistrationEvent` once when the registry is empty and has not asked yet, so a process that never booted OpenRegister still resolves a leaf app's deep link. `Application::boot()` asks through the same method, so a booted process asks once. Verify: `tests/Unit/Service/DeepLinkRegistryBackgroundTest.php` (real registry, real event, a real listener on a dispatcher double) fails on the old code.
- [x] 1.2 `GenericDeepLinkRegistrationListener` logs a warning when the leaf app ships no `src/manifest.json`. Verify: the same test.

## 2. Fallback to OpenRegister's object view

- [x] 2.1 `AnnotationNotifier` falls back to `/apps/openregister/objects/{register}/{schema}/{uuid}` instead of the dead `#/registers/...` hash. Verify: `tests/Unit/Notification/AnnotationNotifierLinkTest.php` fails on the old code.
- [x] 2.2 `AnnotationNotificationDispatcher::buildObjectDetailLink()` falls back to the same object view, not the origin app's hash route. Verify: `tests/Unit/Service/Notification/DispatcherObjectDetailFallbackTest.php` fails on the old code.

## 3. Case-insensitive ordering

- [x] 3.1 `MagicSearchHandler::applySorting()` orders a plain string property (not a date), a translatable property, and `_name`, `_description`, `_summary` (also as `@self.*`) by `LOWER(...)`. Verify: `tests/Unit/Db/MagicSearchHandlerTranslatableSortTest.php` runs the ORDER BY against SQLite with mixed-case rows; it fails on the old code.

## 4. Live

- [x] 4.1 On :8099 a pipelinq notification links to the client page; with the registry empty the fallback opens the object in OpenRegister's object view; `_order={"title":"asc"}` returns one case-insensitive list.
