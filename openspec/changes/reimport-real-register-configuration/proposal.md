---
kind: code
---

## Why

An administrator could not re-import an app's real register configuration on demand. Ruben decided on 14 Oct 2026 that this must work.

Three findings on the Zuiddrecht demo instance led here.

1. `occ openregister:descriptors:list --app=portaliq --import=portaliq` imported the app's sample data, not its configuration. It reported "164 of 186 demo object(s) imported" and set the register to the mock's version 0.27.0. The schema authorization stayed as it was. `RegisterDescriptorService::descriptorFor()` took the first file in glob order that declared the slug. `portaliq_mock_register.json` sorts before `portaliq_register.json`.
2. The real import runs only from each app's `InitializeSettings` repair step, on install and upgrade. A register change without an app-version bump never reaches an existing instance. That happened twice in one week: portaliq #1549 and pipelinq #2471.
3. After the real import ran, portaliq's schemas had `read: [portaliq-medewerkers]` from the file but kept `export: [authenticated]` from the instance. The shipped-baseline guard merges per action key. It took `read` from the app and kept `export` as a local change. The result is a policy nobody wrote: an account that may not read a record may still export it.

## What changes

- `--import` re-imports the app's real configuration, forced. It runs through the app's own SettingsService when that has a forced entry point, so the app's fragment merge and post-import steps run as on upgrade. Otherwise OpenRegister imports `<app>_register.json` with its `register.d` fragments itself, and says the app's own steps did not run.
- An app without one base document, such as OpenRegister, re-imports the non-mock descriptor that declares the slug.
- `--import --mock` imports the sample descriptor. Only a file marked `x-openregister.type: mock` qualifies.
- The real re-import reports registers and schemas created, updated and unchanged, read from the database before and after.
- An authorization block (a schema's, or a property's) settles whole on import. It is never assembled from both sides.
- The administrator's re-import is the explicit decision REQ-LCA-003 asks for. Each schema's authorization becomes the file's. Every block replaced over a local change is counted, listed and written to the audit trail with who asked.

Not in scope: making an unattended upgrade replace a locally changed authorization block. That changes REQ-LCA-003. See design.md, decision D-4.

## Capabilities

### Modified capabilities

- `register-descriptor-admin`: the re-import imports the real configuration, `--mock` imports the sample, and the outcome is counted in registers and schemas.
- `schema-import`: an authorization block settles whole, and an administrator's re-import takes the shipped block.

## Impact

- `lib/Service/RegisterDescriptorService.php`: `reimport()` imports the real configuration. `reimportMock()` keeps the sample import.
- New `lib/Service/AppRegisterImportRunner.php`: finds and runs the app's forced entry point, or imports the files itself.
- New `lib/Service/ShippedBaseline/ShippedAuthorizationDecision.php`: carries the administrator's decision to the guard for the length of one import. It is one shared service per request, registered in `Application`.
- New `lib/Service/CountedRegisterImport.php`: runs a re-import under the decision and counts registers and schemas before and after.
- New `lib/Service/ShippedBaseline/AuthorizationBlocks.php`: finds, settles and reports the authorization blocks for `GuardedDescriptorMerge`.
- `lib/Service/ShippedBaseline/GuardedDescriptorMerge.php`, `ShippedConfigurationGuard.php`: the block rule and the decision.
- `lib/Command/DescriptorListCommand.php`: `--mock`, the help text, the counts.
- `lib/Controller/RegisterDescriptorController.php`, `lib/Service/ExampleSet/ExampleSetRegisters.php`: pass who asked.
- Fleet guard for finding 2: gate-120 `register-version-needs-app-version` in `conduction/hydra-gates`.
