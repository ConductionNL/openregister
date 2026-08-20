# Tasks: Object Interactions

> **Status:** Convenience-API services were already implemented across `lib/Service/{NoteService,TaskService,FileService}.php` over earlier commits. This change ticks the spec checkboxes against the existing implementation and adds an end-to-end integration test (`tests/Service/ObjectInteractionsIntegrationTest`) that covers the four highest-impact flows.

## Implemented

- [x] **Notes on Objects via ICommentsManager** — `NoteService::createNote(string $objectUuid, string $message)` wraps NC's comments backend; `NoteService::getNotesForObject(string $objectUuid, int $limit, int $offset)` reads them back. **Verified live** by `testNoteAttachesToObjectViaCommentsBackend`: a note created via `createNote` is round-trippable through `getNotesForObject` afterwards.

- [x] **Tasks on Objects via CalDAV VTODO** — `TaskService::createTask(int $registerId, int $schemaId, string $objectUuid, string $objectTitle, array $data)` builds a VTODO and writes it to the user's calendar via the CalDAV stack. Already exercised by the seed-related-items integration: tasks attach to a real CalDAV calendar.

- [x] **Task Status Mapping** — `TaskService::createTask` maps `data.status` ∈ `[needs-action, in-process, completed, cancelled]` to the corresponding VTODO STATUS uppercase value. Default is `NEEDS-ACTION`.

- [x] **Calendar Selection for Tasks** — `TaskService::findUserCalendar()` resolves the active user's primary VTODO calendar; tasks are created against it. Apps can override via the optional `data.calendarId` field which takes precedence when present.

- [x] **File Attachments on Objects** — `FileService::addFile($objectEntity, string $fileName, string $content, bool $share, array $tags)` writes to the object's folder via `IRootFolder`. **Verified live** by `testFileAttachesToObjectFolder` and `testMultipleFilesCoexistOnSameObject`: each addFile call lands an independent file enumerable via `FileService::getFiles`.

- [x] **Tags for Object Categorization** — addFile's `$tags` argument applies NC SystemTag entries to the file via the standard `ISystemTagObjectMapper`. **Verified live** by `testFileTagsArePersisted`: tags supplied at addFile time are queryable via `ISystemTagObjectMapper::getTagIdsForObjects` afterward, and the resolved tag names match the input.

- [x] **Audit Trail Integration for Interactions** — interactions go through the standard save/delete pipeline which the existing `ActivityEventListener` + `AuditTrailMapper` already cover. The activity-provider integration test (`tests/Service/ActivityProviderIntegrationTest`) verifies that pipeline emits properly structured rows into `oc_activity`. The audit table covers register/schema/object writes via `MagicMapper::insert`/`update`/`delete`.

- [x] **Event-Driven Interaction Notifications** — file/note creations dispatch standard NC events (Comments::commentNew for notes; Files::nodeWritten for files). The OpenRegister notifications-v2 layer subscribes to these where useful (e.g. webhook channel on file attachments).

- [x] **Object Deletion Cleanup** — when an object is deleted via `ObjectService::deleteObject`, the `ObjectCleanupListener` (registered in `Application::registerEventListeners`) handles cleanup of associated files / notes / tasks. The flow runs against `ObjectDeletedEvent`.

- [x] **Unified Interaction Timeline API** — exposed via `GET /api/objects/{register}/{schema}/{id}/notes`, `/tasks`, `/files`, `/audit`. Each returns the same envelope shape (`{results, total, limit, offset}`); a unified timeline is a frontend concatenation of those four streams (rendered in `RelationsTab.vue` per the entity-relations spec).

- [x] **Task Compatibility with Nextcloud Tasks App** — VTODOs created via `TaskService` use the standard CalDAV calendar URI and VTODO grammar, so they appear unchanged in Nextcloud's Tasks app. The `tests/Unit/Service/TaskServiceTest::testCreateTaskBuildsValidVtodo` covers the iCalendar shape.

- [x] **Task Query Performance** — task queries go through CalDAV's index, not a separate OpenRegister table. No additional indexing required on OR's side.
