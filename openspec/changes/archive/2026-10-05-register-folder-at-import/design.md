## Context

See proposal.md for the why. #4116 (`register-folder-on-first-upload`) made `FolderManagementHandler::createRegisterFolderById()` record a register's folder id through `RegisterFolderRecorder`: one column, compare-and-set, no event, no RBAC or organisation check. It named provisioning at import as a candidate follow-up. `FileService::createEntityFolder()` is the facade into that method; it catches everything except a folder access denial and returns null on failure.

`ConfigurationService::importFromApp()` wraps `ImportHandler::importFromApp()` in `SystemOperationContext::run()`. The import runs at app install and upgrade (repair steps, no session), from `SyncConfigurationsJob` (cron, no session), and from `DemoDataService` (an admin's web request). `ImportHandler` receives `FileService` and six other services through optional setters in `Application::attachOptionalImportServices()`, so an instance whose container cannot build one still imports.

## Goals / Non-Goals

**Goals:**
- Every register an app import returns has a Files folder when the import returns, whichever organisation owns it.
- Provisioning is idempotent and never fails an import.
- Registers imported before this change get their folder on the next upgrade.

**Non-Goals:**
- Changing where the folder lives or who owns it. The folder is made exactly where an API-created register's folder is made (`Open Registers/<title> Register` in the acting user's files, the OpenRegister system user when there is no session, with ownership moved to the system user otherwise).
- `importFromJson()` for a manual configuration upload. It shares `importRegister()`, but the brief and portaliq#29 name the app path; the repair step and the first upload cover the rest.
- `RegisterService::ensureRegisterFolderExists()`, which still calls `RegisterMapper::update()` after the folder is recorded. That is API-path behaviour this change does not touch.

## Decisions

### Provision after the import, over the registers it returned

The call sits in `ImportHandler::importFromApp()` after `autoCreateRegisterIfApplication()`, because that step can add a register to `$result['registers']`. Hooking `importRegister()` instead would also run for manual uploads and would miss the auto-created register. The list is exactly the registers the import touched, so the provisioning cannot reach a register the import did not.

### A small provisioner class, shared by the import and the repair step

`RegisterFolderProvisioner::ensureFolders(registers)` calls `FileService::createEntityFolder()` per register and returns a tally `{provisioned, present, failed}`: `present` when the folder id is the one the register already held, `provisioned` when a new id was recorded, `failed` when no folder came back or anything threw. Non-register entries and registers without an id are skipped. Each register is guarded on its own, so one failure does not stop the rest. It logs one info line when it provisioned anything and one warning per failure.

Why a class: the repair step needs the same loop, and `ImportHandler` is already 5,700 lines under a class-level phpmd suppression.

### Tenant safety

- The id is written by `RegisterFolderRecorder`, which changes only the `folder` column and only while it is empty or still holds the value read before the folder was made. The import therefore never needs to pass `RegisterMapper::update()`'s organisation check, and a register owned by another organisation than the importing context gets its folder like any other.
- No organisation, owner or authorization field is written, and no register-updated event fires, so no webhook, notification or activity entry reports a register edit that nobody made.
- The folder id is the node the file service made or found at the register's conventional path, never import data.
- The repair step reads registers with `_rbac: false, _multitenancy: false`, as the import's own register lookup does, because it runs from `occ upgrade` with no organisation and must reach every register.

### The provisioner is an optional import service

It joins the setter list in `attachOptionalImportServices()`. When the container cannot build it, the import runs exactly as before and the first upload still makes the folder. `FileService` is already resolved in that list, so no new circular dependency appears.

### Repair step in post-migration only

On a fresh install every register comes in through an import that now provisions its own folder, so the step is only needed on upgrade. It resolves `RegisterMapper` and the provisioner lazily from the container, like `LogDanglingLinkedTypes`, and skips with an info line when either is unavailable.

### Declarative-vs-imperative decision

Not applicable in the ADR-031 sense: file-storage plumbing, no lifecycle, notification, relation or widget.

## Risks / Trade-offs

- [An upgrade on an instance with many registers makes many folders] → One folder per register, made once; a register whose folder resolves costs one node lookup.
- [The acting user of a web-triggered import (demo data) is an admin, so the folder is made in their files first] → Same as an API-created register: the ownership transfer moves it to the OpenRegister system user. Cron and repair runs have no session and make it in the system user's files directly.
- [A failure is only logged] → Deliberate: an import must finish, and the first upload still makes the folder.

## Migration Plan

The repair step provisions folders for existing registers on the next upgrade. No schema change. Rollback is reverting the PR; recorded folder ids stay valid.

## Seed Data

Not applicable: no OpenRegister schema is introduced or changed.
