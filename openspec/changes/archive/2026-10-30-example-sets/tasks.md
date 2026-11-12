## 1. Loader

- [x] 1.1 `ExampleSetCatalogue`: read `lib/Settings/example-sets/<set>.json` from every enabled app, require the cast in openregister's file only, list sets (REQ-EXS-001, REQ-EXS-008)
- [x] 1.2 `ExampleSetOrder`: order fragments by `after`, skip and name absent apps, refuse a cycle naming its apps (REQ-EXS-001)
- [x] 1.3 `ExampleSetValues`: `{{cast:}}`, `{{date:}}`, `{{datetime:}}`, `{{object:}}` (same fragment), `{{lookup:}}`; whole-value placeholders keep their type; unresolved is an error naming file, key and placeholder (REQ-EXS-002)
- [x] 1.4 `ExampleSetAccounts`: create cast accounts and groups, generated password returned once or `OC_PASS`, refuse a foreign account unless adopted (REQ-EXS-003)
- [x] 1.5 `ExampleSetStore`: lazy adapter over ObjectService and ValidateObject; validate against the real schema regardless of hard validation (REQ-EXS-004)
- [x] 1.6 `ExampleSetRecord`: install record in app config (REQ-EXS-005)
- [x] 1.7 `ExampleSetInstaller`: check pass, write pass with record after each step, read-back proof (REQ-EXS-004, REQ-EXS-005, REQ-EXS-006)
- [x] 1.8 `ExampleSetRemover`: reverse-order exact removal, adopted accounts and pre-existing groups untouched (REQ-EXS-007)
- [x] 1.9 Commands `openregister:example-set:install|remove|list`, registered in `appinfo/info.xml`, exit codes 0/1/2

- [x] 1.10 `ExampleSetRegisters`: import a required OpenRegister register before the check, record its import jobs, remove exactly those (REQ-EXS-009)
- [x] 1.11 Refuse a cast or fragment group `admin` (REQ-EXS-010)
- [x] 1.12 `ExampleSetChanges`: set and append on an object found by business key, recorded and reverted exactly (REQ-EXS-011)
- [x] 1.13 `jsonFields` sent as JSON text and decoded by the proof (REQ-EXS-012)
- [x] 1.14 `ExampleSetFiles`: files on an object from the fragment's folder, shared, recorded, removed (REQ-EXS-013)
- [x] 1.15 Service accounts with a password nobody sees, in their groups (REQ-EXS-014)
- [x] 1.16 `ExampleSetAppConfig`: own-app config, sensitive keys refused unless generated, exact restore (REQ-EXS-015)
- [x] 1.17 `ExampleSetInstance`: `{{organisation:}}`, cast organisations created and removed; `{{instance:baseUrl}}` (REQ-EXS-016, REQ-EXS-017)
- [x] 1.18 A change on an object written earlier in the run (REQ-EXS-018)
- [x] 1.19 Weekday-pinned dates (REQ-EXS-019) and a stable `contactsUid` per cast member (REQ-EXS-020)

- [x] 1.20 A change carries the `jsonFields` of the object it changes, reads them as structure and writes, validates, proves and reverts them as JSON text (REQ-EXS-012, found by the first live check)
- [x] 1.21 The check lets a `user` format accept the set's own cast and service account uids (scoped to the set's validation), and still refuses any other unknown uid (REQ-EXS-004, found by the first live check)
- [x] 1.22 `{{register:<slug>}}` and `{{schema:<register>/<schema>}}` fill numeric ids, looked up after required registers are imported (REQ-EXS-021, found by the first live install)
- [x] 1.23 Remove deletes the set's own archival objects through the system path (never a held one), deletes attached files by their node, and names the reason for everything that stays (REQ-EXS-022, found by the first live remove)
- [x] 1.24 A kept object resolves its `{{object:}}` and `{{lookup:}}` fields again and writes only what differs, recorded as the change `<app>/<key>#references` (REQ-EXS-023, found after a partial remove on :8080)
- [x] 1.25 `userPreferences`: own app only, only for accounts the set created or adopted, never an administrator or a secret, old value recorded and restored while it is still the set's (REQ-EXS-024, asked by dossiq #3896 for the demo staff's tour state)

## 2. Data

- [x] 2.1 `lib/Settings/example-sets/zuiddrecht.json`: cast (residents, businesses, employees, department groups) and openregister's own BRP and KVK rows
- [x] 2.2 Test BSNs pass the eleven test and are unused in the fleet's registers; KVK numbers fictitious
- [x] 2.3 `requires: ["brp", "kvk"]`, the 18 apps that take part, Marleen Postma in Vergunningen
- [x] 2.4 The municipality as OpenRegister organisation `zuiddrecht`

## 3. Tests

- [x] 3.1 Placeholder resolution, typed whole values, unresolved placeholder names file, key and placeholder
- [x] 3.2 Ordering, absent apps, cycle refusal
- [x] 3.3 Idempotent second install, exact removal, foreign account refusal and adoption
- [x] 3.4 Command exit codes 0, 1 and 2, asserted through the command (the caller)
- [x] 3.5 The shipped zuiddrecht file parses, its cast BSNs pass the eleven test, and every `{{cast:}}` it uses resolves
- [x] 3.7 Files, service accounts, app config, organisations, instance address, changes on earlier objects, weekday dates, contactsUid (`ExampleSetFragmentPartsTest`, `ExampleSetValuesTest`)
- [x] 3.8 Live-check regressions through the installer (`ExampleSetLiveCheckTest`), the store's hand-over to the validator (`ExampleSetStoreTest`) and the real validator (`ValidateObjectPlannedUsersTest`)
- [x] 3.9 Register and schema ids (`ExampleSetRegisterIdsTest`, `ExampleSetStoreTest`)
- [x] 3.10 Remove from occ: files by node, archival and held objects, reasons in the output (`ExampleSetRemoveLiveTest`, `ExampleSetStoreTest`)
- [x] 3.11 Partial remove then reinstall: kept publications follow the theme written again, a further install writes nothing, remove is whole (`ExampleSetReferencesTest`)
- [x] 3.12 User preferences written, kept, restored, left when changed, and refused for a foreign account, an administrator and a secret (`ExampleSetUserPreferencesTest`)
- [x] 3.13 A required register shipped only as a mock is declared `{"register": "<slug>", "mock": true}` and imported from the mock; a plain slug never falls back to it; the shipped zuiddrecht requirements import on a fresh instance (`ExampleSetMockRegisterImportTest`)
- [x] 3.6 Required registers imported once and removed only when imported; admin refused; changes made, proved and reverted exactly; JSON text fields

## 4. Verification

- [ ] 4.1 `composer check:strict` once before push
- [ ] 4.2 Live install, check, second install and remove on a dev instance (not done in this change; see the PR body)
