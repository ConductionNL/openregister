# Tasks: remove-file-ownership-storage-rename

- [x] Remove `FileValidationHandler::ownFile()` and `::getUser()`; drop the `FileMapperInterface` and `IUserSession` constructor dependencies.
- [x] Remove `FileMapper::setFileOwnership()` and the `FileMapperInterface` signature.
- [x] Reword `FileService::checkOwnership()` so the docblock no longer calls it a repair hack.
- [x] Replace the seven `ownFile()` unit tests with a guard that the methods no longer exist; drop the `setFileOwnership` expectations from the `checkOwnership()` tests.
- [x] Delta specs: `file-actions` (renamed; the `ownFile()` bullet replaced by a no-repair bullet) and `object-interactions` (the listing-repair scenario replaced by "Listing never repairs ownership").
- [x] Rig check on Nextcloud 35: a sync with attachments run by `admin` logs no `ownFile` / `checkOwnership` lines and leaves `oc_storages` unchanged.
- [x] Delta specs carry the full requirement text with a "(Previously: …)" note; the file-actions requirement is renamed, its old title named a repair it now forbids.
- [ ] After the 2.x release on a WOO environment: a sync with attachments leaves the log reader empty on `ownFile`.
