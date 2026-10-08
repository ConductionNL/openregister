# Tasks: brp-family-members-lookup

Kind: code. Size S. Row: portaliq `int-family-prefill`.

## 1. Provider

- [ ] 1.1 Add `lookupFamily()` to `lib/Service/Integration/Providers/BrpPersonProvider.php` with the two calls of design D1, the filters and the answer of D2. Verify: `tests/Unit/Service/Integration/Providers/BrpPersonProviderFamilyTest.php` with recorded answers covers same address, another address, a dissolved partnership, a deceased child and the degraded path; it asserts no BSN in the result.
- [ ] 1.2 Add `resolveFamilyReference()` per design D3. Verify: the same test asserts a match, a reference from another resident and a relative who is no longer a relative.

## 2. Mock

- [ ] 2.1 Answer `lookupFamily()` from the mock BRP register per design D4. Verify: `tests/Unit/Service/Integration/Providers/BrpPersonProviderFamilyMockTest.php` uses the seeded family of BSN 999990627.

## 3. Docs

- [ ] 3.1 Document both methods and the minimised shape in `docs/features/integration-person-lookup.md`. Verify: `npm run build` in `docs/` succeeds.
