# Tasks: example-set-access-links-and-grants

## 1. Services
- [x] 1.1 `ObjectSharingService::grant()` takes optional `expiresAt` and `sharedAt`; live callers unchanged (D-3)
- [x] 1.2 A scope that runs one call as a given user and restores the previous one in `finally` (D-2)

## 2. Parts
- [x] 2.1 `ExampleSetAccessLinks`: refusal, write through `AccessLinkService::mint()`, lost (no row or revoked), revert by deleting the row (D-1, D-5)
- [x] 2.2 `ExampleSetGrants`: refusal, write through `grant()` as `sharedBy`, folder resolved or reported (D-4), lost, revert through `IManager::deleteShare()`
- [x] 2.3 Both registered in `ExampleSetEngineKinds`; written after objects and before changes (D-6)
- [x] 2.4 `--refresh` reverts and writes both parts again, then runs the changes (D-5)

## 3. Placeholder and check
- [x] 3.1 `ExampleSetValues` resolves `{{accessLink:<key>.<field>}}` for `id`, `uuid`, `url`; the check accepts a declared key before minting and refuses an unknown one
- [x] 3.2 The check refusals of D-7, each naming the file and the row key

## 4. Tests
- [x] 4.1 PHPUnit per part: install, second install writes nothing, missing after a revoke, refresh mints a new link, remove deletes exactly the held rows
- [x] 4.2 PHPUnit: a grant whose `sharedBy` may not share is reported, the set goes on
- [x] 4.3 PHPUnit: two grants by different accounts in one refresh job leave no session behind (D-8)
- [x] 4.4 PHPUnit: the anchor never appears in the record or the report

## 5. Consumers
- [ ] 5.1 portaliq Zuiddrecht fragment: Sanne's link, the change that names it on the `caseShare`, and the Juridische zaken grant by Pieter
- [ ] 5.2 Re-measure DqZaakBetrokkenen Toegang after a window with the set refreshed
