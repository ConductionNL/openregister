## 1. Re-import the real configuration

- [x] 1.1 `RegisterDescriptorService::reimport()` imports the app's real configuration (base file plus `register.d`), forced, through the app's own forced entry point or OpenRegister's loader.
- [x] 1.2 An app without one base document re-imports the non-mock descriptor that declares the slug.
- [x] 1.3 `reimportMock()` imports only a descriptor marked `type: mock`.
- [x] 1.4 The result counts registers and schemas created, updated and unchanged, read from the database before and after.

## 2. The command

- [x] 2.1 `--import` runs the real re-import; `--import --mock` the sample. The help text says which is which.
- [x] 2.2 The output names the path it took, the counts, and every authorization block replaced over a local change. On the fallback path it says the app's own post-import steps did not run.

## 3. Authorization

- [x] 3.1 `GuardedDescriptorMerge` settles each authorization block whole, and reports a conflict once, on the block root.
- [x] 3.2 `ShippedAuthorizationDecision` carries the administrator's decision to the guard; each replaced block is recorded on the audit trail with who asked.
- [ ] 3.3 Decide D-4's proposal: does the shipped authorization block also replace a local one on an unattended upgrade?

## 4. Tests

- [x] 4.1 Unit tests through the real service, runner, guard, merge and command. The merge tests and a probe of the old `reimport()` fail on the old code.
- [ ] 4.2 Live check on a test instance: re-import portaliq and read back `authorization.export` on `portalAccount`.

## 5. Fleet guard

- [x] 5.1 gate-120 `register-version-needs-app-version` in `conduction/hydra-gates`: a change to a register document's `info.version` without a change to `appinfo/info.xml` `<version>` fails.
