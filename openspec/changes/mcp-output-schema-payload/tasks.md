# Tasks

## 1. Measure first

- [x] Record the current payload: total bytes, per-field breakdown, per-app breakdown
      > 2026-11-13: done: recorded in the acceptance criteria below and in `SchemaDerivedToolProvider::buildOutputSchema()` (2026-08-16, 122 tools: 433,198 B total; `shillinq.ARInvoice.search` the worst tool).
- [x] Confirm which field dominates before changing anything
      > 2026-11-13: done: `outputSchema`, 335,580 of 433,198 B (79.7%).

Acceptance criteria:
- The change is justified by a measurement, not by an intuition about what is big. Measured: `outputSchema` 335,580 B of 433,198 B (79.7%).

## 2. Emit the envelope

- [x] `buildOutputSchema()` returns the envelope for `search`, a bare object for `get`, null for write verbs
      > 2026-11-13: done on main: `{results, total, hasMore}` for `search`, `{type: object}` for `get`, null otherwise.
- [x] Drop the now-unused `Schema` parameter so the signature stops implying a dependency that would invite re-inlining
      > 2026-11-13: done on main: `buildOutputSchema(string $verb)`.
- [x] Leave `inputSchema` untouched
      > 2026-11-13: done: `buildInputSchema()` unchanged; `search` still narrows to its declared filters.

Acceptance criteria:
- No property name from the underlying schema appears anywhere in `outputSchema`.
- `search` still declares `results`, `total`, `hasMore`.

## 3. Make a regression fail loudly

- [x] Add a byte-budget test over a fixture with 25 wordy properties, search + get only
      > 2026-11-13: done on main: `SchemaDerivedToolProviderTest::testDerivedToolPayloadStaysWithinBudget` (passes, 2026-11-13).
- [x] State the measured size and the ceiling in the failure message
      > 2026-11-13: done: "search+get payload is %d bytes against a ceiling of 2000".
- [x] Record in the test why `create`/`update` are excluded
      > 2026-11-13: done: the fixture comment explains that write verbs legitimately carry the property set in their input schema.

Acceptance criteria:
- Re-inlining the item properties fails the test. A payload regression is otherwise invisible: no test fails, no gate fires, nothing errors — it shows up as agents getting slower and dumber, which gets blamed on the model.

## 4. Verify on the live instance

- [x] Deploy and re-measure the real `tools/list` payload and handshake timings
      > 2026-11-13: done: measured 411,561 B to 97,479 B (−76%), as recorded below.
- [x] Report the latency result even if it is disappointing
      > 2026-11-13: done: the handshake fell only ~20-25%, as stated below.

Acceptance criteria:
- Payload measured, not estimated. Measured 411,561 B → 97,479 B (−76%).
- The latency finding is stated plainly: the handshake fell only ~20-25%, so it is NOT serialisation-bound and this change does not bring it within the 250 ms budget.
