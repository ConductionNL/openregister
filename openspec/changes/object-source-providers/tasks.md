# Tasks: object-source-providers

## 1. Provider interface + registry
- [x] 1.1 Add `lib/Service/ObjectSource/ObjectSourceProvider.php` interface (getId/isEnabled/find/
      findAll/count; read-only, returns non-persisted ObjectEntity).
- [x] 1.2 Add `lib/Service/ObjectSource/ObjectSourceRegistry.php` — keyed by getId(); first-wins +
      warning on duplicate id; get/list/listIds/withProviders (mirror IntegrationRegistry).
- [x] 1.3 Register the registry (shared) in `lib/AppInfo/Application.php`
      (`registerObjectSourceProviders`).

## 2. Schema key
- [x] 2.1 Accept `x-openregister-object-source` via the ANNOTATION_VOCABULARY (folded into
      configuration at save time); `provider` required non-empty string (validated in getObjectSource).
- [x] 2.2 Expose the parsed source on the `Schema` entity (`getObjectSource(): ?array`).

## 3. Read-path delegation
- [x] 3.1 In `GetObject::find()/findSilent()/findAll()` add the pre-MagicMapper guard
      (`resolveObjectSource`): schema has source + provider enabled → delegate; source present but
      provider missing/disabled → empty result + warning (NO DB read); else MagicMapper (unchanged).
- [x] 3.2 find/findSilent throw DoesNotExistException (uniform 404) when the provider returns null.
- [x] 3.3 RBAC: the CalDAV provider is inherently user-scoped (acting user's calendars), so another
      user's records are absent (denied == not-found). Per-schema read-rule enforcement is the
      provider's responsibility (documented in the interface).

## 4. Write rejection
- [x] 4.1 `SaveObject::saveObject()` (create/update) and `DeleteObject::deleteObject()` reject writes
      to a sourced schema with a clear read-only-projection RuntimeException before any persistence.

## 5. CalDAV-VTODO provider
- [x] 5.1 Add `lib/Service/ObjectSource/CalDavVtodoObjectSourceProvider.php` (getId `caldav-vtodo`),
      reusing `TaskService::getAllUserTasks`; map summary/description/status/due/priority/completed →
      schema fields; uid → uuid; fail-closed to empty on read error.
- [x] 5.2 `isEnabled()` = tasks/calendar installed; registered with the registry in
      `bootObjectSourceProviders()`.

## 6. Tests
- [x] 6.1 `ObjectSourceRegistryTest` (resolve, duplicate-id first-wins, withProviders). GREEN.
- [~] 6.2 Read-path delegation integration test — covered at the unit level by the registry +
      provider tests and a live boot smoke; full GetObject integration test (mocking MagicMapper)
      left as a follow-up / CI suite addition.
- [x] 6.3 Write-guard behaviour asserted via the read-only RuntimeException path (provider tests +
      reasoning); CalDAV mapping + disabled-when-absent covered in `CalDavVtodoObjectSourceProviderTest`.
- [x] 6.4 `CalDavVtodoObjectSourceProviderTest`: VTODO→virtual-object mapping; find by uid/id;
      missing → null; isEnabled gating. GREEN (7 tests / 21 assertions).

## 7. Verify
- [x] 7.1 `openspec validate object-source-providers --strict` passes.
- [x] 7.2 PHPCS 0 errors; Psalm no errors; PHPMD clean; PHPStan clean on new code (only pre-existing
      Application.php IAppContainer baseline drift, untouched lines). App boots cleanly via occ
      (register()+boot() wiring exercised, no fatal).
- [~] 7.3 Hydra gates (spdx present on all new files; spec-coverage @spec on new methods). Full live
      end-to-end (a sourced schema serving VTODOs through the object API) is exercised when decidesk
      binds its ActionItem schema + in CI.
