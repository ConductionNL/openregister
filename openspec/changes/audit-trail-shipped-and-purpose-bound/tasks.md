# Tasks: audit-trail-shipped-and-purpose-bound

## 1. The file sink

- [x] 1.1 A configured location and a documented structured format, written from the same entries (D-1).
- [x] 1.2 A write failure recorded as an entry and reported on the operations console (D-2).

## 2. Doelbinding

- [x] 2.1 An administered purpose list, each purpose bound to a processing activity (D-3).
- [x] 2.2 A registry query without a bound purpose is refused, naming it.
- [x] 2.3 The purpose on the audit entry, countable per purpose.

## 3. Token attribution

- [ ] 3.1 Token, owner and consumer on the audit entry of a write made with a token (D-4).
- [ ] 3.2 No request or response payload stored, with a test that asserts the absence.

## 4. Reported content

- [ ] 4.1 A copy written when a report is filed, not when a removal runs (D-5).
- [ ] 4.2 Reviewer-only access and its own retention; a removal names the copy.

## 5. Announcement

- [ ] 5.1 A security-relevant marker on a setting (D-6).
- [ ] 5.2 Administrators notified on change, with both values where neither is a secret.
- [ ] 5.3 A secret announced as changed without being quoted.

## 6. Tests

- [x] 6.1 `tests/e2e/ci/audit-shipping.spec.ts`: a purpose-bound query and a refused unbound one. The security setting announcement waits for section 5.
- [~] 6.2 Unit tests: the sink failure entry and the purpose done; the token attribution, the payload absence, the report copy and its access and the secret announcement wait for sections 3, 4 and 5.
- [x] 6.3 `openspec validate audit-trail-shipped-and-purpose-bound --strict`.

## 7. Hand over

- [x] 7.1 Hand the purpose list to the dossiq lane for its BRP and KvK lookups. The contract is in the PR body under "The consumer contract".
- [ ] 7.2 Hand the purpose parameter to the integriq lane for the registry adapters.

## Where this stopped

Part one ships sections 1, 2 and the half of 6 that belongs to them. Sections
3 (token attribution), 4 (reported content) and 5 (the announcement) are not
started and continue on a second branch. The sink and the purpose are each
complete on their own: an instance can configure a sink and see whether it is
working, and a schema can demand a declared purpose and refuse a read without
one, with neither depending on the three sections still to come.
