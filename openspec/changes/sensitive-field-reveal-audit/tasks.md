# Tasks: sensitive-field-reveal-audit

## 1. Declaration and collection

- [x] 1.1 `audit: true` in the property authorization validator with the no-read-rule refusal.
- [x] 1.2a The reveal COLLECTOR, and its collection point in
      `PropertyRbacHandler::filterReadableProperties()` — recorded where the
      value survives the filter, not where the check runs (D-1), so a stripped
      property writes nothing. Deduplicated on (user, object, property,
      request): a list of forty reveals forty times, and that count is the
      finding. `recordProcess()` carries D-3's one-entry-per-run.
- [ ] 1.2b The FLUSH: `AuditTrailMapper::insertAuditTrails()` called once per
      request with what the collector took. The batched insert it needs already
      exists, so this is the wiring of a request-teardown hook, but it writes
      to the hash-chained trail and belongs with a live instance to verify the
      chain against rather than with a unit test that asserts a mapper was
      called.

## 2. Reading

- [ ] 2.1 `reveal` kind filter on the audit leaf; the processing-activity log
      reads the rows as read events. Waits on 1.2b: a filter over rows nothing
      writes yet is a page that is always empty, which is indistinguishable
      from a page that is broken.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/reveal-audit.spec.ts`: waits on 1.2b and 2.1, since
      it asserts on rows and a page that do not exist yet.
- [x] 3.2 Unit tests for the validator, the batch, the stripped case and the process entry.
