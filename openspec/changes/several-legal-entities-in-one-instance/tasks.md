# Tasks: several-legal-entities-in-one-instance

## 1. Shared master data

- [ ] 1.1 A holder and consumer declaration on a register and on a schema (D-1).
- [ ] 1.2 The tenant-scoped query resolves a consumer's read to the holder's rows, with no copy (D-1).
- [ ] 1.3 A consumer's write to a shared row is refused at the write path, naming the holder (D-2).
- [ ] 1.4 An organisation declaring nothing behaves exactly as before, with a regression test.

## 2. The move between organisations

- [ ] 2.1 A per-object-type policy of move, copy or drop under the root (D-3).
- [ ] 2.2 A preview listing every object and its policy, and an approval the move requires (D-3).
- [ ] 2.3 The move written to both organisations' audit trails under one correlation (D-4).

## 3. Logging

- [ ] 3.1 The organisation UUID and a pseudonymous actor reference on every line (D-5).
- [ ] 3.2 Redaction of token, password and credential values before writing.
- [ ] 3.3 An unredactable line is dropped and counted, never written (D-5).

## 4. The administration session

- [ ] 4.1 A fresh authentication before the administration surface renders (D-6).
- [ ] 4.2 An administered expiry on the elevated session, refusing writes after it lapses.

## 5. Tests

- [ ] 5.1 `tests/e2e/ci/shared-master-data.spec.ts`: two organisations, one code list, a refused write, an unchanged third organisation.
- [ ] 5.2 Unit tests: the move preview and its three policies, the two-chain audit write, the redactor including the drop path, the elevated session expiry.
- [ ] 5.3 `openspec validate several-legal-entities-in-one-instance --strict`.

## 6. Re-rate and hand over

- [ ] 6.1 Re-rate the four members with the dossiq lane: the build plan's table reads all four `partial` against isolation, and three of the four requirements here are absent.
- [ ] 6.2 Hand the shared master declaration to the dossiq lane for `TenantAuthenticationService`, with candidate ids C-case-core-14, C-access-and-privacy-51, C-access-and-privacy-68 and C-configuration-72.
