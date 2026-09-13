# Tasks: sensitive-field-reveal-audit

## 1. Declaration and collection

- [ ] 1.1 `audit: true` in the property authorization validator with the no-read-rule refusal.
- [ ] 1.2 Reveal collector in `PropertyRbacHandler` / `RenderObject`, flushed once per request as a batched insert; process entry for trusted internal reads.

## 2. Reading

- [ ] 2.1 `reveal` kind filter on the audit leaf; the processing-activity log reads the rows as read events.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/reveal-audit.spec.ts`: read an object as an authorised user, filter the audit page on reveals.
- [ ] 3.2 Unit tests for the validator, the batch, the stripped case and the process entry.
