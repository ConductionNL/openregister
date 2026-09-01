# Tasks: retrofit-2026-05-24-file-actions (partial)

This change retroactively annotates code that already exists in production. All covered tasks below are `[x]` because no new implementation work is required.

This is a **partial pass** capping at 5 REQs out of an estimated 8–10 REQs needed for full file-actions coverage. The `## DROP — future-pass:next` section lists the methods deferred to subsequent retrofit runs.

## Covered (annotated this run)

- [x] task-1: file-actions#REQ-001 — File CRUD operations on objects (retroactive annotation; 11 methods)
- [x] task-2: file-actions#REQ-002 — Distributed file locking (retroactive annotation; 6 methods)
- [x] task-3: file-actions#REQ-003 — File preview & download streaming (retroactive annotation; 3 methods)
- [x] task-4: file-actions#REQ-004 — Object & register folder management (retroactive annotation; 8 methods)
- [x] task-5: file-actions#REQ-005 — Object tagging via Nextcloud system tags (retroactive annotation; 7 methods)

## DROP — future-pass:next

The following in-scope methods (i.e. not pre-triaged as DROP-to-other-capability) are deferred to follow-up retrofit runs. Grouped by likely future REQ. Numbers in parentheses are the file::method counts from the 2026-05-24 coverage scan.

### Likely REQ — Publish / depublish / public sharing
- `lib/Service/File/FilePublishingHandler.php::publishFile` — Generic public-share creation
- `lib/Service/File/FilePublishingHandler.php::unpublishFile` — Remove public share
- `lib/Service/File/FilePublishingHandler.php::createObjectFilesZip` — Object-files ZIP build
- `lib/Service/File/FileSharingHandler.php::createShare` — Generic Nextcloud share creation
- `lib/Service/File/FileSharingHandler.php::getShareLink` — Share URL builder
- `lib/Service/File/FileSharingHandler.php::findShares` — Share lookup
- `lib/Service/File/FileSharingHandler.php::shareFileWithUser` — User file share
- `lib/Service/File/FileSharingHandler.php::shareFolderWithUser` — Folder share
- `lib/Controller/FilesController.php::depublish` — File depublish endpoint

### Likely REQ — File metadata & enrichment (labels, description, category)
- `lib/Controller/FilesController.php::updateLabels` — Update file labels endpoint
- `lib/Service/File/FileFormattingHandler.php::*` — Format file JSON output (10 methods)
- `src/services/fileMetadata.js::buildFileUrl` / `updateFileLabels` / `updateFileMetadata` — Frontend metadata client (3 methods)

### Likely REQ — File upload & creation pipeline
- `lib/Controller/FilesController.php::save` — File save endpoint
- `lib/Controller/FilesController.php::createMultipart` — Multipart upload helper
- `lib/Controller/FilesController.php::processUploadedFiles` — Upload processor
- `lib/Controller/FilesController.php::extractUploadedFiles` — Request-to-files extractor
- `lib/Controller/FilesController.php::normalizeSingleFile` / `normalizeMultipleFiles` / `normalizeMultipartFiles` — Multipart normalisers
- `lib/Controller/FilesController.php::validateUploadedFile` — Upload validator
- `lib/Controller/FilesController.php::getUploadErrorMessage` — Upload-error string helper
- `lib/Service/File/CreateFileHandler.php::*` — File creation (4 methods)
- `src/modals/file/UploadFiles.vue::closeModal` — Upload modal close

### Likely REQ — File versioning (list / restore / show)
- `lib/Controller/FilesController.php::show` — File metadata GET
- `lib/Controller/FilesController.php::update` — File metadata PUT
- `lib/Controller/FilesController.php::batch` — Bulk file operations endpoint
- `lib/Controller/FilesController.php::downloadById` — File download by ID
- `lib/Controller/FilesController.php::recordDownloadEvent` — Download audit event
- `lib/Service/File/UpdateFileHandler.php::*` — File update (4 methods)
- `lib/Service/File/ReadFileHandler.php::*` — File reads (6 methods)
- `lib/Service/File/FileBatchHandler.php::*` — Batch operations (4 methods)
- `lib/Service/File/FileCrudHandler.php::*` — CRUD facade (the non-stub methods; ~5 methods)
- `lib/Service/File/FileValidationHandler.php::*` — File validation (6 methods, 1 here-covered: ownFile)

### Likely REQ — File locking internals (helpers)
- `lib/Service/File/FileLockHandler.php::getLockInfo` / `readLockEntry` / `writeLockEntry` / `removeLockEntry` / `setLock` / `isLocked` / `assertCanModify` / `isCurrentUserAdmin` / `getCurrentUserId` — Lock cache layer + helpers (already covered indirectly by REQ-002; explicit REQs for the cache contract are deferred)

### Likely REQ — File ownership (the OpenRegister system user)
- `lib/Service/File/FileOwnershipHandler.php::*` — Ownership transfer (5 methods)
- `lib/Service/File/FileValidationHandler.php::ownFile` — Ownership setter (1 method)

### Likely REQ — File chunking + Solr indexing
- `lib/Controller/FileTextController.php::getChunkingStats` — Chunking stats
- `lib/Service/Index/FileHandler.php::indexFileChunks` / `getChunkingStats` / `getFileIndexStats` — Solr indexing (3 methods)
- `lib/Service/Vectorization/Strategies/FileVectorizationStrategy.php::fetchEntities` — Vectorisation
- `src/modals/settings/FileWarmupModal.vue::loadStats` — Warmup UI

### Likely REQ — File settings admin (Dolphin / Presidio / OpenAnonymiser)
- `lib/Controller/Settings/FileSettingsController.php::getFileSettings` / `updateFileSettings` — Settings CRUD
- `lib/Controller/Settings/FileSettingsController.php::testDolphinConnection` / `testPresidioConnection` / `testOpenAnonymiserConnection` — Provider connection tests
- `lib/Controller/Settings/FileSettingsController.php::reindexFiles` / `getFileIndexStats` — Re-indexing
- `lib/Service/Settings/FileSettingsHandler.php::*` — Settings persistence (3 methods)
- `src/modals/settings/FileManagementModal.vue::loadConfiguration` — Settings UI
- `src/modals/settings/FileVectorizationModal.vue::loadFileTypes` — Vectorization settings UI
- `src/views/settings/sections/FileConfiguration.vue::loadSettings` — Admin settings page

### Likely REQ — Document anonymisation
- `lib/Service/File/DocumentProcessingHandler.php::anonymizeDocument` — Document anonymisation orchestrator
- `lib/Service/FileService.php::anonymizeDocument` — Facade (delegates to handler)
- `lib/Service/File/DocumentProcessingHandler.php::*` — Other processing methods (5 methods)

### Likely REQ — File sidebar & UI
- `lib/Controller/FileSidebarController.php::__construct` — Sidebar controller
- `lib/Service/FileSidebarService.php::__construct` — Sidebar service
- `src/components/FilesSidebar.vue::handleSearchInput` — Sidebar search UI
- `src/composables/UseFileSelection.js::useFileSelection` — File selection composable
- `src/views/files/FilesIndex.vue::toggleSidebar` — Sidebar toggle

### Likely REQ — File integrations / providers (built-in)
- `lib/Service/Integration/BuiltinProviders/FilesProvider.php::getId` / `getLabel` / `getIcon` / `getStorageStrategy` — Files provider registration

### Likely REQ — Trivial constructors / events (defer or skip)
- Event constructors (`FileCopiedEvent::__construct`, `FileLockedEvent::__construct`, etc.) — Already covered by their parent REQs; explicit annotation low-value
- `lib/Event/UserProfileUpdatedEvent.php::__construct` — Not file-actions (likely belongs to user-profile capability)

### Pre-triaged DROP (NOT for future file-actions retrofit)

The original coverage scan pre-triaged 191 methods as belonging to other capabilities. Those are not in scope for any future file-actions retrofit pass — they belong to their original target capability:

- chat-ai (extractUploadedFiles)
- content-versioning (show, update, batch, recordDownloadEvent, normalizers, FileCrudHandler::getFile / getFileById, FileLockHandler::__construct / assertCanModify / setLock / getCurrentUserId, FileSharingHandler::__construct / shareFileWithUser / getCurrentDomain, FilePublishingHandler::__construct / setFileService)
- oas-generation (downloadById, getFileTags, FileCrudHandler::__construct / updateFile)
- archival-destruction-workflow (depublish)
- seed-related-items (getFileViaKnownUsers, save, createMultipart, normalizeTags, FileLockHandler::isCurrentUserAdmin / isLocked, FileSharingHandler::getShareLink / findShares / shareFolderWithUser, FilePublishingHandler::publishFile / unpublishFile, TaggingHandler::findOrCreateTag / attachTagsToFile, FileCrudHandler::addFile)
- data-integrity-relations (getUploadErrorMessage)
- text-extraction-eml (FileLockHandler::getLockInfo)
- extended-field-types (FileLockHandler::writeLockEntry)
- object-lifecycle (FileLockHandler::readLockEntry / removeLockEntry)
- office-document-sanitization (FilePublishingHandler::createObjectFilesZip / getZipErrorMessage)
- openregister-app-manifest (FilesController::page)
- actions (FileCrudHandler::createFolder)
- nextcloud-api-compat (FileCrudHandler::saveFile)

See `/tmp/or-scan/rspec-cluster-file-actions.json` `observed_behavior` fields for the full DROP rationale per method.
