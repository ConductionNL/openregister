## Purpose

An administrator re-imports an app's real register configuration on demand, and sees what changed.

@e2e exclude occ command and backend import service, covered by PHPUnit through the real command, service, runner and guard

## ADDED Requirements

### Requirement: Re-import imports the app's real register configuration (REQ-RDA-010)

`occ openregister:descriptors:list --app=<app> --import=<slug>` SHALL import the app's real register configuration, forced. That is `lib/Settings/<app>_register.json` with its `lib/Settings/register.d/*.json` fragments, the same configuration an install or upgrade imports. It SHALL run through the app's own SettingsService when that has a forced entry point. Otherwise OpenRegister SHALL import those files itself and SHALL say the app's own post-import steps did not run. A mock descriptor SHALL NOT be imported by `--import` alone. Of OpenRegister's own descriptors, the ones whose install and upgrade steps import under a configuration id of their own (`openregister.decision-tables`, `openregister.dismissed-pair`, `openregister.edepot-transfers`, `openregister.merge-operation`, `openregister.trust-configuration`, `openregister.vocabulary`, `openregister.dsar-cases`, `openregister.dsar-policy-packs`) SHALL be re-imported under that same id, read from the one map the install steps read, so the re-import moves no version gate another descriptor shares under `openregister`; the others import under `openregister`. The result SHALL carry the import job and the configuration id it ran under when the import recorded one. An instance that re-imported one of those eight descriptors under the shared `openregister` id before this was true is not repaired by an upgrade: only a development instance can carry that (the `beta` release never had the re-import of a real descriptor), and its shared `openregister` configuration then holds the version of whichever descriptor was imported last. Importing a descriptor that does share the id (for example `--import=flow`) sets that version again.

#### Scenario: The app ships both a mock and a real descriptor for the same register

- **GIVEN** portaliq ships `portaliq_mock_register.json` (version 0.27.0, demo objects) and `portaliq_register.json` (version 0.97.0), both declaring register `portaliq`
- **WHEN** an administrator runs `occ openregister:descriptors:list --app=portaliq --import=portaliq`
- **THEN** the configuration of version 0.97.0 is imported under configuration id `portaliq`
- **AND** no demo object from the mock is imported

#### Scenario: A core descriptor keeps its install-time configuration id

- **GIVEN** OpenRegister ships `merge_operation_register.json`, which its repair step imports under `openregister.merge-operation`
- **WHEN** an administrator re-imports register `merge-operation` of `openregister`
- **THEN** the import runs under configuration id `openregister.merge-operation`, not `openregister`

#### Scenario: Fragments are part of the configuration

- **GIVEN** an app ships `register.d/10-extra.json` adding a schema
- **WHEN** the administrator re-imports the app's register
- **THEN** the imported configuration contains that schema
- **AND** the imported version carries the fragment signature

#### Scenario: The app's own forced entry point runs

- **GIVEN** the app's SettingsService has `loadSettings(bool $force)`
- **WHEN** the administrator re-imports the app's register
- **THEN** `loadSettings(force: true)` runs once
- **AND** OpenRegister does not import the files a second time
- **AND** the output names the method that ran

#### Scenario: An app without a forced entry point

- **GIVEN** the app's SettingsService has no forced entry point
- **WHEN** the administrator re-imports the app's register
- **THEN** OpenRegister imports `<app>_register.json` with its fragments, forced
- **AND** the output says the app's own post-import steps did not run

#### Scenario: An app without one base document

- **GIVEN** an app ships no `<app>_register.json` and one non-mock descriptor declares the slug
- **WHEN** the administrator re-imports that slug
- **THEN** that descriptor is imported, forced
- **AND** a mock descriptor declaring the same slug is not

#### Scenario: A slug the configuration does not declare

- **WHEN** the administrator re-imports a slug no non-mock descriptor of the app declares
- **THEN** the command exits 1
- **AND** the message lists the registers the configuration declares and points to `--mock`

### Requirement: Sample data is imported only when asked for (REQ-RDA-011)

`--import=<slug> --mock` SHALL import the app's descriptor marked `x-openregister.type: mock` that declares the slug, under configuration id `<app>.mock`, and SHALL report the demo objects imported. The help text SHALL say that `--import` imports the real configuration and that `--mock` imports sample data.

#### Scenario: The mock import never picks the real descriptor

- **GIVEN** the real descriptor's file name sorts before the mock's
- **WHEN** the administrator runs `--import=<slug> --mock`
- **THEN** the mock descriptor is imported
- **AND** the output counts the demo objects imported and skipped

#### Scenario: The help says which is which

- **WHEN** the administrator reads `occ openregister:descriptors:list --help`
- **THEN** `--import` is described as the real register configuration including `register.d`
- **AND** `--mock` is described as sample data

### Requirement: The re-import reports what changed (REQ-RDA-012)

The real re-import SHALL report registers and schemas as created, updated and unchanged, measured from the database before and after the import. It SHALL report how many authorization blocks it took from the file over a change made on the instance, and name each. The administrator panel (Register descriptors) SHALL ask for confirmation before it runs a re-import, saying that the app's schema authorization is taken from the file over any local change and that the app's other registers may be re-imported with it, and SHALL show the response's count and every replaced block (schema and path) in its outcome note, as a warning and not as a plain success when any block was replaced.

#### Scenario: Counts come from the database

- **GIVEN** register `flows` holds schemas 1 and 2 before the import
- **WHEN** the import changes schema 1, leaves schema 2, adds schema 3 and raises the register version
- **THEN** the output reads registers 1 updated, 0 created, 0 unchanged
- **AND** schemas 1 updated, 1 created, 1 unchanged

#### Scenario: Replaced authorization is listed

- **WHEN** the re-import takes `portalAccount`'s authorization from the file over a local change
- **THEN** the output counts 1 replaced block and names `portalAccount: authorization`
- **AND** the panel's outcome note shows the count and `portalAccount: authorization` as a warning, after the administrator confirmed the re-import
