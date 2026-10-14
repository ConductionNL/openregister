## Context

Three paths import an app's register configuration today:

- The app's `InitializeSettings` repair step calls its SettingsService with `force: false` on install and upgrade.
- The app's admin "reload" action calls the same service, forced.
- `occ openregister:descriptors:list --import` called `ConfigurationService::importFromApp()` directly, on the first file in `lib/Settings` declaring the slug.

The fleet's SettingsServices differ. Measured on the development branches on 14 Oct 2026:

| Forced entry point | Apps |
|---|---|
| `loadConfigurationForced()` | shillinq, humaniq |
| `reloadConfiguration()` | decidiq |
| `loadConfiguration(bool $force)` | portaliq, dossiq, hermiq, stackiq, every AppHost app |
| `loadSettings(bool $force)` | pipelinq |
| none | opencatalogi, integriq, thematiq, launchpad, keepiq, versioniq |

The fragment merge also differs. pipelinq replaces lists except two additive paths. OpenRegister's `RegisterDocumentLoader` concatenates lists. Some apps re-apply instance configuration after the import: pipelinq re-grants its CRM groups, portaliq its page-editor groups.

## Decisions

### D-1. The app's own path first

The re-import calls the app's SettingsService when it has one of the four forced shapes. A method counts only when its first parameter is named `force`, or when it takes no required argument and is one of the two forced names. shillinq's `loadConfiguration()` takes no argument and is not forced, so matching the name alone would run an import the version gate skips.

Without such a method, OpenRegister imports `<app>_register.json` plus `register.d` through `RegisterDocumentLoader`, forced. The output then says the app's own post-import steps did not run.

Alternative rejected: a new interface every app implements. That is the clean end state, but it needs a change in 21 apps before the command works anywhere.

### D-2. Counts from the database

The import's own return value differs per app and lists what it processed, not what changed. The service fingerprints each declared register and its schemas before and after: version, title, description, properties, required, authorization, configuration. It reports created, updated and unchanged.

### D-3. An authorization block settles whole

`GuardedDescriptorMerge` compares flattened paths. `authorization.read` and `authorization.export` were separate parts, so one import could take one from the app and keep the other. On portaliq that produced staff-only `read` with `export` open to every signed-in account.

Each block (the schema's `authorization`, and each `properties.<name>.authorization`) now takes one state for all its keys:

- Only the app changed it: the app's block, whole.
- Only the instance changed it: the instance's block, whole.
- Both, or each changed a different key: the instance's block, whole, reported as one conflict on the block root.

The flattener is unchanged, so the reset of one key (`authorization.read`, spec `shipped-baseline-reset-is-reachable`) still works.

### D-4. The administrator's re-import is the decision; the unattended upgrade is not

REQ-LCA-003 says a part changed on both sides is not applied without an explicit decision, recorded with its actor. That is a documented reason to merge rather than to make the file authoritative everywhere. So:

- An administrator's re-import (occ, the admin panel, an example-set install) runs under `ShippedAuthorizationDecision`, a shared service injected into `GuardedDescriptorMerge`. While a decision is in effect, the merge adds every authorization block root to the decisions. Each block replaced over a local change goes to the audit trail as `configuration.conflict.decided`, with `via` naming who asked.
- An unattended `occ upgrade` keeps the instance's block and reports the conflict, as REQ-LCA-003 requires.

The decision travels as a scope, not a parameter, because the app's SettingsService calls `importFromApp()` with a signature OpenRegister does not own.

**Proposed, not built: authorization follows the app on upgrade too.** An access policy is the app's security contract. An app that closes a register to its staff group (portaliq 0.97.0) does so to fix a leak, and a stale local value keeps the leak open until somebody runs the re-import. The proposal is to amend REQ-LCA-003: on upgrade the shipped authorization block replaces a locally changed one, the replaced block is recorded on the audit trail, and an instance that needs wider access says so through a grant layer that survives upgrades rather than by editing the schema. This needs Ruben's decision, because it removes a guarantee a municipality may rely on.

## Findings while tracing

- **The baseline is keyed by schema slug only** (`ShippedBaselineStore::schemaSubject()` returns `schema:<slug>`). Two apps that ship a schema with the same slug share one baseline. Not changed here.
- **Where portaliq's `export: [authenticated]` came from is not established.** No version of `portaliq_register.json` ever shipped it: the history is no authorization, then `read` only (0.96.0), then `read` and `export` for staff (0.97.0). OpenRegister code does not derive `export` from `read`. The stored baseline for `schema:portalAccount` on the demo instance has no `export` key, so the guard read it as a local addition.
- **A mock writes the real register's version.** A mock declares the real register's slug, so `--mock` sets that register's version to the mock's. The help text says so.

## Risks

- An app's forced entry point can do more than import, such as pipelinq's access sync. That is the point of D-1, but it means the command runs app code.
- A municipality's local authorization change is replaced when an administrator runs the re-import. The command lists every replaced block and the audit trail records each one.
