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

## 2. Data

- [x] 2.1 `lib/Settings/example-sets/zuiddrecht.json`: cast (residents, businesses, employees, department groups) and openregister's own BRP and KVK rows
- [x] 2.2 Test BSNs pass the eleven test and are unused in the fleet's registers; KVK numbers fictitious

## 3. Tests

- [x] 3.1 Placeholder resolution, typed whole values, unresolved placeholder names file, key and placeholder
- [x] 3.2 Ordering, absent apps, cycle refusal
- [x] 3.3 Idempotent second install, exact removal, foreign account refusal and adoption
- [x] 3.4 Command exit codes 0, 1 and 2, asserted through the command (the caller)
- [x] 3.5 The shipped zuiddrecht file parses, its cast BSNs pass the eleven test, and every `{{cast:}}` it uses resolves

## 4. Verification

- [ ] 4.1 `composer check:strict` once before push
- [ ] 4.2 Live install, check, second install and remove on a dev instance (not done in this change; see the PR body)
