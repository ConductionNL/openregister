---
kind: code
depends_on: [register-folder-on-first-upload]
---

# Proposal: register-folder-at-import

## Why

A register created through the API gets its Files folder at creation: `RegisterService::createFromArray()` calls `ensureRegisterFolderExists()`. A register created by an app's configuration import does not. `ConfigurationService::importFromApp()` reaches `ImportHandler::importRegister()`, which creates the row with `RegisterMapper::createFromArray()` and never asks for a folder, so every app-shipped register (portaliq, learniq, dossiq and the rest) starts life without one. Until #4116 that made the first upload into such a register fail for a request without a Nextcloud session (portaliq#29, openregister#2515). #4116 fixed the upload path: the first upload now makes the folder and records its id as bookkeeping. This change is option 1 of portaliq#29, the follow-up #4116's design named as a candidate: provision the folder where the register is made, so an app-imported register behaves like an API-created one and the first upload finds a folder instead of making one.

## What Changes

- `ImportHandler::importFromApp()` ensures a Files folder for every register the import returned (created, updated, skipped on version, or auto-created for an `application` configuration), after the registers exist and before it returns.
- Provisioning reuses the folder path #4116 made safe: `FileService::createEntityFolder()` reaches `FolderManagementHandler::createRegisterFolderById()`, which reuses a recorded folder that still resolves, finds or makes `Open Registers/<title> Register`, and records the id through `RegisterFolderRecorder`. No `RegisterMapper::update()` runs, so no register-updated event fires, no version changes, and no organisation check can refuse a register that belongs to another organisation than the importing context.
- It is idempotent: a register whose recorded folder still resolves is left alone, and re-running an import records nothing new.
- It never fails the import. A folder that cannot be made is logged at warning with the register id and left for the first upload, which since #4116 makes it itself.
- A post-migration repair step, `CreateMissingRegisterFolders`, runs the same provisioning over every register on the instance, across organisations, so registers imported before this change get their folder on the next upgrade. It reports how many it provisioned, found present and could not make, and never throws.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `file-actions`: a requirement is added: a register created or updated by an app configuration import has its Files folder when the import returns, and a repair step provisions folders for registers imported earlier.

## Impact

- `lib/Service/File/RegisterFolderProvisioner.php` (new): ensures folders for a list of registers and tallies the outcome.
- `lib/Service/Configuration/ImportHandler.php`: an optional provisioner (setter, like its other optional services) and one call in `importFromApp()`.
- `lib/AppInfo/Application.php`: the provisioner joins the optional services the import handler is given.
- `lib/Repair/CreateMissingRegisterFolders.php` (new) and its `<step>` at the end of `<post-migration>` in `appinfo/info.xml`.
- Tests: provisioner, repair step, and an `importFromApp()` test that asserts provisioning runs for the registers the import returned and that a provisioning failure does not fail the import.
- No route, schema, migration or dependency change.
- Consumer: portaliq can drop its `GREP_INVERT` e2e exclusion in `tests/e2e/playwright.config.ts` (portaliq#29). #4116 on its own already makes the excluded test pass; this change makes it pass without depending on the upload path making the folder.
- Evidence: portaliq#29 (options 1 and 2), openregister#2515, and the non-goal paragraph of `openspec/changes/register-folder-on-first-upload/design.md`.
