# Tasks: seed-related-items

## 1. ImportHandler -- Inject Related Item Services

- [ ] 1.1 Add constructor parameters to `lib/Service/Configuration/ImportHandler.php` for `?TaskService $taskService = null`, `?NoteService $noteService = null`, and `?FileService $fileService = null`. Add corresponding `use` statements for `OCA\OpenRegister\Service\TaskService`, `OCA\OpenRegister\Service\NoteService`, and `OCA\OpenRegister\Service\FileService`. Store as private nullable properties. This follows the existing pattern of nullable service injection already used in ImportHandler (e.g., `$magicMapper`, `$routingMapper`).

## 2. ImportHandler -- Strip and Extract _relatedItems

- [ ] 2.1 In the `importSeedData()` method, inside the `foreach ($objects as $objectData)` loop (line 2820), add extraction of `_relatedItems` BEFORE the existing object processing logic. Extract `$relatedItems = $objectData['_relatedItems'] ?? null;` then `unset($objectData['_relatedItems']);`. This MUST happen before the `$objectSlug` extraction (line 2949) and before `ObjectEntity::setObject($objectData)` (line 3037) to ensure `_relatedItems` is never persisted to the database.

## 3. ImportHandler -- User Context Check

- [ ] 3.1 At the start of `importSeedData()`, after the null/empty check for seedData (line 2686), add a user context check. Get `$user = $this->userSession->getUser()` (IUserSession is already injected into ImportHandler). Store as `$hasUserContext = ($user !== null)`. Log a DEBUG message indicating whether user context is available. This flag is used later to skip task and note creation when no user is logged in.

## 4. ImportHandler -- Process Related Items After Object Creation

- [ ] 4.1 After the successful object insert block (after line 3051 `$result['objects'][] = $createdObject->getId()`), add a call to a new private method `processRelatedItems(ObjectEntity $object, ?array $relatedItems, int $registerId, int $schemaId, string $objectTitle, bool $hasUserContext, array &$result): void`. Pass the `$createdObject`, `$relatedItems`, `$targetRegId`, `$objectSchema->getId()`, `$objectData['title'] ?? $objectSlug`, `$hasUserContext`, and `$result`. Skip the call if `$relatedItems` is null or empty.

## 5. ImportHandler -- Implement processRelatedItems Method

- [ ] 5.1 Create private method `processRelatedItems()` in ImportHandler. Log an INFO message at the start with the count of each related item type (files, notes, tasks). Initialize counters: `$filesCreated = 0`, `$notesCreated = 0`, `$tasksCreated = 0`.

- [ ] 5.2 **Files processing block**: If `$relatedItems['files']` is set and `$this->fileService !== null`, iterate over each file definition. For each: extract `name` (required, skip if missing), `content` (required, skip if missing), `tags` (default `[]`), `share` (default `false`). If `content` starts with `base64:`, decode the remainder via `base64_decode()`. Call `$this->fileService->addFile($object, $name, $content, $share, $tags)`. Wrap in try/catch; on failure log WARNING with file name and error message, continue to next file. Increment `$filesCreated` on success.

- [ ] 5.3 **Notes processing block**: If `$relatedItems['notes']` is set and `$this->noteService !== null` and `$hasUserContext === true`, iterate over each note definition. For each: extract `message` (required, skip if missing). Call `$this->noteService->createNote($object->getUuid(), $message)`. Wrap in try/catch; on failure log WARNING with object UUID and error message, continue. Increment `$notesCreated` on success. If `$hasUserContext` is false, log a single WARNING: "Skipping note creation for seed object -- no user session available".

- [ ] 5.4 **Tasks processing block**: If `$relatedItems['tasks']` is set and `$this->taskService !== null` and `$hasUserContext === true`, iterate over each task definition. For each: extract `summary` (required, skip if missing). Build `$taskData` array with keys: `summary`, `description` (default `''`), `status` (default `needs-action`), `priority` (default `0`), `due` (default `null`). Call `$this->taskService->createTask($registerId, $schemaId, $object->getUuid(), $objectTitle, $taskData)`. Wrap in try/catch; on failure log WARNING with task summary and error message, continue. Increment `$tasksCreated` on success. If `$hasUserContext` is false, log a single WARNING: "Skipping task creation for seed object -- no user session available".

- [ ] 5.5 Log a DEBUG message at the end of `processRelatedItems()` with the counts: files created, notes created, tasks created, and the object UUID.

## 6. ImportHandler -- Summary Counters

- [ ] 6.1 Add summary counters at the `importSeedData()` method level: `$totalFilesCreated = 0`, `$totalNotesCreated = 0`, `$totalTasksCreated = 0`. Have `processRelatedItems()` update these via the `$result` array (add keys `relatedFiles`, `relatedNotes`, `relatedTasks`). In the final INFO log (line 3076-3084), include total related items created per type alongside the existing object count.

## 7. Application Registration

- [ ] 7.1 Verify that `lib/AppInfo/Application.php` registers `TaskService`, `NoteService`, and `FileService` in the DI container. These services are likely already registered since they are used by controllers. If any is missing, add the registration. Confirm ImportHandler can resolve them via constructor injection.

## 8. Unit Tests

- [ ] 8.1 Create `tests/Unit/Service/Configuration/ImportHandlerSeedRelatedItemsTest.php`. Test that `_relatedItems` is stripped from object data before persistence. Mock `ObjectEntityMapper::insert()` and verify the `ObjectEntity::getObject()` result does not contain `_relatedItems`.

- [ ] 8.2 Test that `processRelatedItems()` calls `NoteService::createNote()` for each note entry. Use a mock NoteService, verify it receives the correct objectUuid and message for each note defined in `_relatedItems.notes`.

- [ ] 8.3 Test that `processRelatedItems()` calls `TaskService::createTask()` for each task entry with correct parameters (registerId, schemaId, objectUuid, objectTitle, task data array).

- [ ] 8.4 Test that `processRelatedItems()` calls `FileService::addFile()` for each file entry, including base64 decoding when content starts with `base64:`.

- [ ] 8.5 Test error isolation: mock `TaskService::createTask()` to throw an exception. Verify that note and file creation still proceed, and a warning is logged.

- [ ] 8.6 Test idempotency: when an object already exists (idempotency check returns existing object), verify that `processRelatedItems()` is NOT called.

- [ ] 8.7 Test no-user-context: when `IUserSession::getUser()` returns null, verify tasks and notes are skipped but files are still created.

## 9. Verification

- [ ] 9.1 Install OpenRegister on a clean Nextcloud instance. Create a test `_register.json` with a schema and seed objects that include `_relatedItems` with notes, tasks, and files. Import via `importFromApp()`. Verify: objects are created, notes appear via `/api/objects/{r}/{s}/{id}/notes`, tasks appear via `/api/objects/{r}/{s}/{id}/tasks`, files appear via `/api/objects/{r}/{s}/{id}/files`.

- [ ] 9.2 Re-import the same configuration. Verify no duplicate objects or related items are created.

- [ ] 9.3 Verify that persisted object JSON does not contain `_relatedItems` key (query database or GET object via API).
