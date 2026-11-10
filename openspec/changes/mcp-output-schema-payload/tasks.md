# Tasks

## 1. Measure first

- [ ] Record the current payload: total bytes, per-field breakdown, per-app breakdown
- [ ] Confirm which field dominates before changing anything

Acceptance criteria:
- The change is justified by a measurement, not by an intuition about what is big. Measured: `outputSchema` 335,580 B of 433,198 B (79.7%).

## 2. Emit the envelope

- [ ] `buildOutputSchema()` returns the envelope for `search`, a bare object for `get`, null for write verbs
- [ ] Drop the now-unused `Schema` parameter so the signature stops implying a dependency that would invite re-inlining
- [ ] Leave `inputSchema` untouched

Acceptance criteria:
- No property name from the underlying schema appears anywhere in `outputSchema`.
- `search` still declares `results`, `total`, `hasMore`.

## 3. Make a regression fail loudly

- [ ] Add a byte-budget test over a fixture with 25 wordy properties, search + get only
- [ ] State the measured size and the ceiling in the failure message
- [ ] Record in the test why `create`/`update` are excluded

Acceptance criteria:
- Re-inlining the item properties fails the test. A payload regression is otherwise invisible: no test fails, no gate fires, nothing errors — it shows up as agents getting slower and dumber, which gets blamed on the model.

## 4. Verify on the live instance

- [ ] Deploy and re-measure the real `tools/list` payload and handshake timings
- [ ] Report the latency result even if it is disappointing

Acceptance criteria:
- Payload measured, not estimated. Measured 411,561 B → 97,479 B (−76%).
- The latency finding is stated plainly: the handshake fell only ~20-25%, so it is NOT serialisation-bound and this change does not bring it within the 250 ms budget.
