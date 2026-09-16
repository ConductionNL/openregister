# Tasks: several-legal-entities-in-one-instance

## 1. Shared master data

- [x] 1.1 A holder and consumer declaration on a register and on a schema (D-1). `shared_with` on `openregister_registers` and `openregister_schemas` (Version1Date20260916094217); the `organisation` column the row already carries is the holder.
- [x] 1.2 The tenant-scoped query resolves a consumer's read to the holder's rows, with no copy (D-1). Both halves: `MultiTenancyTrait::applyOrganisationFilter()` widens by ROW ID, `MagicOrganizationHandler::resolveOrganizationScope()` widens by holder for the one register+schema pair named, and `AggregationRunner` reads the same decision so the list and the KPI tiles cannot disagree.
- [x] 1.3 A consumer's write to a shared row is refused at the write path, naming the holder (D-2). `SharedMasterDataWriteException` from `MultiTenancyTrait::verifyOrganisationAccess()` (registers and schemas) and from `SaveObject::saveObject()` (objects), recorded on the audit trail via `AuditTrailMapper::createSharedMasterDataRefusalEntry()`.
- [x] 1.4 An organisation declaring nothing behaves exactly as before, with a regression test. `testAnInstanceThatDeclaresNothingResolvesToNothing` and `testAnOrganisationNamedInNoDeclarationResolvesToNothing`; the column is nullable and every resolver path short-circuits on an empty declaration set.

## 2. The move between organisations

- [ ] 2.1 A per-object-type policy of move, copy or drop under the root (D-3).
- [ ] 2.2 A preview listing every object and its policy, and an approval the move requires (D-3).
- [ ] 2.3 The move written to both organisations' audit trails under one correlation (D-4).

## 3. Logging

- [x] 3.1 The organisation UUID and a pseudonymous actor reference on every line (D-5). `TenantLogRedactor::pseudonym()`, applied at the tenancy log sites in `MultiTenancyTrait` and at the refusal sites. NOT retrofitted across every log line in the app: that is a sweep, not this change.
- [x] 3.2 Redaction of token, password and credential values before writing. By key fragment and by value pattern (bearer, basic, JWT, credential-in-URL), nested contexts included.
- [x] 3.3 An unredactable line is dropped and counted, never written (D-5). `TenantLogRedactor::line()` returns null and increments `openregister`/`tenant_log_dropped_lines`; mutation-checked by failing it open.

## 4. The administration session

- [x] 4.1 Consume `instance-hardening-controls` REQ-IHC-002 for C-configuration-72; write no second elevated session (D-6). Nothing was written here. The elevated administration session stays REQ-IHC-002's, per D-6.

## 5. Tests

- [x] 5.1 `tests/e2e/workflows/shared-master-data.spec.ts`: three organisations, one code list, a refused write naming the holder, an unchanged third organisation. Placed in the ROOT suite rather than `tests/e2e/ci/`: the CI floor admits a file after a run has been seen, and this lane has no Playwright runner.
- [x] 5.2 Unit tests: the redactor including the drop path (13 tests) and the share resolver (16 tests), both mutation-checked. The move preview and the two-chain audit write belong to task 2 and are NOT done.
- [x] 5.3 `openspec validate several-legal-entities-in-one-instance --strict`. Valid.

## 6. Re-rate and hand over

- [ ] 6.1 Re-rate the four members with the dossiq lane: the build plan's table reads all four `partial` against isolation, and three of the four requirements here are absent.
- [x] 6.2 Hand the shared master declaration to the dossiq lane for `TenantAuthenticationService`, with candidate ids C-case-core-14, C-access-and-privacy-51, C-access-and-privacy-68 and C-configuration-72.

## Where this stopped

The first PR ships sections 1, 3, 4 and the half of 5 that belongs to them.
**Section 2, the move of an object graph between organisations under a
per-type policy (REQ-SLE-002), is NOT built** and continues on
`feat/several-legal-entities-in-one-instance-part-2`. Task 6.1, the re-rate of
the four candidates with the dossiq lane, goes with it.
