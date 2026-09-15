# Tasks: data-subject-rights-across-the-instance

## 1. The erasure preview

- [x] 1.1 Counts of objects, files, timeline entries and party records, split erasable, pseudonymised, protected (D-1).
- [x] 1.2 The preview writes nothing.
- [x] 1.3 An unresolvable hold counts as protected and is named (D-2).

## 2. The erasure

- [x] 2.1 An erasure runs only from an approved preview.
- [x] 2.2 Destruction goes through the delete window's recorded destruction, naming the request (D-3).
- [x] 2.3 The audit of the erasure survives the erasure.

## 3. The subject's own export

- [x] 3.1 A machine readable export of everything held about a subject, as a background job (D-4).
- [x] 3.2 A delivered file with its own expiry, and an audit entry naming requester and subject.

## 4. Reach and revocation

- [x] 4.1 A reach listing read from the permission resolver, with the source of each grant (D-5).
- [x] 4.2 One revocation act, recorded naming every grant removed.

## 5. External grants

- [x] 5.1 An end date required on a grant to an external principal, refused without one (D-6).
- [x] 5.2 A warning before it lapses, and contributions that stay attributed after it does.

## 6. Tests

- [x] 6.1 `tests/e2e/ci/data-subject-rights.spec.ts`: the four routes reachable, the unapproved erasure refused, the spent preview refused, one handler's preview unreadable by another. The COUNTS are excluded with a reason and a named unit test: the PII index has no HTTP write door for objects, so an API assertion would run against an empty index.
- [x] 6.2 Unit tests: the unresolvable hold, the unapproved erasure refusal, the export expiry (clock fixture), the external grant refusal and lapse.
- [x] 6.3 `openspec validate data-subject-rights-across-the-instance --strict`.

## 7. Hand over

- [ ] 7.1 Hand the preview and the revocation to the dossiq lane, with candidate ids C-access-and-privacy-19, -22, -57, -58, -61 and -74.
- [ ] 7.2 Tell the D9 lane that C-access-and-privacy-57 is `platform-user-migrator` and not this change.
