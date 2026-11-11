## 1. Provisioning

- [x] 1.1 Add `lib/Service/File/RegisterFolderProvisioner.php` with `ensureFolders(registers): array{provisioned, present, failed}` over `FileService::createEntityFolder()`; verify with `tests/Unit/Service/File/RegisterFolderProvisionerTest.php` (new id is provisioned, same id is present, null and throw are failed, non-registers skipped, one failure does not stop the rest)
- [x] 1.2 Give `ImportHandler` an optional provisioner (setter) and call it in `importFromApp()` after `autoCreateRegisterIfApplication()` for `$result['registers']`; wire it in `Application::attachOptionalImportServices()`; verify with an `importFromApp()` test that provisioning receives the returned registers and that a throwing provisioner does not fail the import

## 2. Repair

- [x] 2.1 Add `lib/Repair/CreateMissingRegisterFolders.php` and its step at the end of `<post-migration>` in `appinfo/info.xml`; verify with `tests/Unit/Repair/CreateMissingRegisterFoldersTest.php` (reads registers across organisations, reports the tally, skips when services are missing)

## 3. Verification

- [x] 3.1 Run `composer check:strict`, `npm run lint` and the hydra gates once before push, and record each exit code in the PR body
