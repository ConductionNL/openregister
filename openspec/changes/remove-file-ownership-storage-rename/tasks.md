# Tasks: remove-file-ownership-storage-rename

- [x] Remove `FileValidationHandler::ownFile()` and `::getUser()`; drop the `FileMapperInterface` and `IUserSession` constructor dependencies.
- [x] Remove `FileMapper::setFileOwnership()` and the `FileMapperInterface` signature.
- [x] Reword `FileService::checkOwnership()` so the docblock no longer calls it a repair hack.
- [x] Replace the seven `ownFile()` unit tests with a guard that the methods no longer exist; drop the `setFileOwnership` expectations from the `checkOwnership()` tests.
- [x] Delta specs: `file-actions` (bullet removed) and `object-interactions` (listing repair scenario removed).
- [ ] Rig check on Nextcloud 35: a sync with attachments run by `admin` logs no `ownFile` / `checkOwnership` lines and leaves `oc_storages` unchanged.
