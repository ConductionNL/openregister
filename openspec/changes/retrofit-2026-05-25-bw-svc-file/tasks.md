# Tasks: retrofit-2026-05-25-bw-svc-file

This change retroactively annotates code that already exists in production. All
covered tasks are `[x]` — no new implementation work is required. The batch is 45
methods from `/tmp/or-scan/bw-svc-file.json`; every method ends tagged with an
`@spec` pointer or `@spec exclude <reason>`.

## Covered — new REQs (annotated this run)

- [x] task-1: file-actions#REQ-006 — File creation & upsert pipeline (CreateFileHandler::addFile, ::saveFile; FileOwnershipHandler::transferFileOwnershipIfNeeded)
- [x] task-2: file-actions#REQ-007 — File retrieval + node→metadata projection (ReadFileHandler::getFile, ::getFileById, ::getFiles; FileFormattingHandler::formatFile, ::formatFiles; FileLockHandler::getLockInfo)
- [x] task-3: file-actions#REQ-008 — Content + OR-side metadata update, doc rewrite, write-lock guard (UpdateFileHandler::updateFile, ::updateFileMetadata; DocumentProcessingHandler::replaceWords, ::anonymizeDocument; FileLockHandler::assertCanModify)
- [x] task-4: file-actions#REQ-009 — Publishing, ZIP export, sharing, batch (FilePublishingHandler::publishFile, ::unpublishFile, ::createObjectFilesZip; FileSharingHandler::createShare, ::findShares, ::shareFileWithUser, ::shareFolderWithUser; FileBatchHandler::executeBatch; FileOwnershipHandler::getUser, ::transferFolderOwnershipIfNeeded)
- [x] task-5: file-actions#REQ-010 — Upload security validation + ownership repair (FileValidationHandler::blockExecutableFile, ::detectExecutableMagicBytes, ::checkOwnership, ::ownFile)

## Covered — existing REQs (annotated to the 2026-05-24 pass)

- [x] task-6: file-actions#REQ-004 — Folder management methods in batch (FolderManagementHandler::createEntityFolder, ::createFolder, ::createObjectFolderById, ::createObjectFolderWithoutUpdate, ::getNodeTypeFromFolder, ::getObjectFolder)
- [x] task-7: file-actions#REQ-005 — Object/file tagging methods in batch (TaggingHandler::attachTagsToFile, ::getFileTags)

## Excluded (`@spec exclude <reason>` — boilerplate)

- [x] FileCrudHandler::addFile — Phase-1B stub, throws "pending Phase 2 extraction".
- [x] FileCrudHandler::createFolder — Phase-1B stub, throws unconditionally.
- [x] FileCrudHandler::deleteFile — Phase-1B stub, throws unconditionally.
- [x] FileCrudHandler::getFile — Phase-1B stub, throws unconditionally.
- [x] FileCrudHandler::getFileById — Phase-1B stub, throws unconditionally.
- [x] FileCrudHandler::getFiles — Phase-1B stub, throws unconditionally.
- [x] FileCrudHandler::saveFile — Phase-1B stub, throws unconditionally.
- [x] FileCrudHandler::updateFile — Phase-1B stub, throws unconditionally.
- [x] FileAuditHandler::logDownload — no-op audit shim; only logs, AuditTrail insert is commented out.

## Notes

`FileCrudHandler` is an entire Phase-1B placeholder class — all eight of its
methods throw `"... pending Phase 2 extraction"` and have no observable behavior.
When Phase 2 extraction lands (moving the real logic out of `FileService` into the
handler), these methods should be re-triaged against REQ-006 … REQ-010.
