# Tasks: sensitive-field-reveal-audit

## 1. Declaration and collection

- [x] 1.1 `audit: true` in the property authorization validator with the no-read-rule refusal.
- [x] 1.2a The reveal COLLECTOR, and its collection point in
      `PropertyRbacHandler::filterReadableProperties()` — recorded where the
      value survives the filter, not where the check runs (D-1), so a stripped
      property writes nothing. Deduplicated on (user, object, property,
      request): a list of forty reveals forty times, and that count is the
      finding. `recordProcess()` carries D-3's one-entry-per-run.
- [x] 1.2b The FLUSH: `RevealFlusher` hands what the collector took to
      `AuditTrailMapper::insertAuditTrails()`, called once per request by
      `RevealAuditMiddleware` on `afterController` **and on
      `afterException`** — a request that threw halfway has still shown the
      rows it rendered, and recording only the happy path would make a failed
      request the way to read a BSN untraceably.
      The earlier caution about this "touching the chain" was conservative: the
      mapper inserts and seals each chunk itself, so the flush adds no second
      implementation of the hashing. A failed write is logged and swallowed,
      because the reads have already happened and a recording problem must not
      become an availability one.
      Chain integrity is proven against a FIXTURE chain
      (`RevealChainIntegrityTest`, 10 cases including edit, deletion and
      reorder tamper), not by seeding the live trail: rows written to an
      append-only chain to prove a test cannot be removed afterwards without
      breaking everything after them. What the live instance can answer was
      asked read-only — 2000 consecutive real rows link with zero breaks, and
      its first sealed row carries the v2 genesis.

## 2. Reading

- [ ] 2.1 `reveal` kind filter on the audit leaf; the processing-activity log
      reads the rows as read events. **No longer blocked** now 1.2b writes the
      rows; it is a read surface over an action that exists.

## 3. Tests

- [ ] 3.1 `tests/e2e/ci/reveal-audit.spec.ts`: waits on 2.1, since it filters
      the audit page on reveals and that filter is not built.
- [x] 3.2 Unit tests for the validator, the batch, the stripped case and the process entry.
