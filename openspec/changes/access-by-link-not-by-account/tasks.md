# Tasks: access-by-link-not-by-account

## 1. The link

- [x] 1.1 A minted link over one object, one saved view or one file, with a random anchor (D-1).
- [x] 1.2 The link resolves a principal of its own, never a user's rights (D-2).

## 2. Capabilities, expiry and password

- [x] 2.1 Declared capabilities of read, comment and upload, defaulting to read (D-5).
- [x] 2.2 An undeclared capability is refused.
- [x] 2.3 An expiry required at mint; a password optional and checked at use (D-3).

## 3. Revocation and the record

- [x] 3.1 A revoked, expired or disabled link answers 404, never a reduced page (D-4).
- [x] 3.2 Every use audited with link, act, time and address.

## 4. Visibility

- [x] 4.1 Property visibility, field-level rules and timeline entry visibility applied to link reads (D-6).
- [x] 4.2 A regression test that a link cannot serve what a person could not.

## 5. Tests

- [x] 5.1 `tests/e2e/ci/publication-link.spec.ts`: mint, open without an account, comment, fail an upload, revoke, get 404.
- [x] 5.2 Unit tests: anchor non-derivability, the missing-expiry refusal, the password check, the four audit entries, the internal entry filter.
- [x] 5.3 `openspec validate access-by-link-not-by-account --strict`.

## 6. Hand over

- [x] 6.1 Hand the link to the dossiq lane for `CaseSharingService`, with candidate ids C-communication-7, C-access-and-privacy-5, C-access-and-privacy-25 and C-access-and-privacy-83.
- [x] 6.2 Tell the D9 lane that C-access-and-privacy-25 is `platform-cloud-federation-provider`.

## What landed

`lib/Db/AccessLink.php` and `lib/Db/AccessLinkMapper.php` hold the row and its
lookups. `lib/Migration/Version1Date20260915204500.php` creates
`openregister_access_links`, with `expires_at` NOT NULL so a link with no end
date cannot exist. `lib/Service/Sharing/AccessLinkService.php` mints, resolves,
revokes and records. `lib/Service/Sharing/AccessLinkReader.php` serves the
subject under the object's own rules. `lib/Controller/AccessLinkController.php`
carries three public endpoints and four owner endpoints.

## Two decisions taken while building

**410 Gone is never sent.** The change named four status codes and only three
are used. Gone confirms that a link once existed and stopped, which is the fact
a revoked link must not disclose, so revocation answers 404 and the distinction
is not offered. 401 is the one place a distinct answer is right: whoever holds
the anchor already knows the link exists, and without a 401 there is no way to
ask for the password.

**The reader applies the filters itself rather than riding `_rbac`.** Everywhere
else in this app `_rbac: false` means "trusted internal read", and it switches
off exactly the property stripping a link needs most. So the subject is fetched
with the group rules off, because there is no session for them to judge, and the
write-only strip, the property-authorisation strip, the public-only timeline and
the `@self` allow-list are applied explicitly in `AccessLinkReader`.
