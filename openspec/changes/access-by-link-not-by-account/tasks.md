# Tasks: access-by-link-not-by-account

## 1. The link

- [ ] 1.1 A minted link over one object, one saved view or one file, with a random anchor (D-1).
- [ ] 1.2 The link resolves a principal of its own, never a user's rights (D-2).

## 2. Capabilities, expiry and password

- [ ] 2.1 Declared capabilities of read, comment and upload, defaulting to read (D-5).
- [ ] 2.2 An undeclared capability is refused.
- [ ] 2.3 An expiry required at mint; a password optional and checked at use (D-3).

## 3. Revocation and the record

- [ ] 3.1 A revoked, expired or disabled link answers 404, never a reduced page (D-4).
- [ ] 3.2 Every use audited with link, act, time and address.

## 4. Visibility

- [ ] 4.1 Property visibility, field-level rules and timeline entry visibility applied to link reads (D-6).
- [ ] 4.2 A regression test that a link cannot serve what a person could not.

## 5. Tests

- [ ] 5.1 `tests/e2e/ci/publication-link.spec.ts`: mint, open without an account, comment, fail an upload, revoke, get 404.
- [ ] 5.2 Unit tests: anchor non-derivability, the missing-expiry refusal, the password check, the four audit entries, the internal entry filter.
- [ ] 5.3 `openspec validate access-by-link-not-by-account --strict`.

## 6. Hand over

- [ ] 6.1 Hand the link to the dossiq lane for `CaseSharingService`, with candidate ids C-communication-7, C-access-and-privacy-5, C-access-and-privacy-25 and C-access-and-privacy-83.
- [ ] 6.2 Tell the D9 lane that C-access-and-privacy-25 is `platform-cloud-federation-provider`.
