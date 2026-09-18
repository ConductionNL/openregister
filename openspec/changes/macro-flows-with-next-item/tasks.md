# Tasks: macro-flows-with-next-item

## 1. Binding

- [x] 1.1 `flow` and `macro` on declared actions with the three refusals at schema save.
- [~] 1.2 `next` on the manual trigger node config and on end nodes; effective `next` in the run result.

## 2. Execution

- [ ] 2.1 Single-object action route queueing the flow with subject and attribution, sync by default, answering run id, outcome, `next`; audit entry.
- [ ] 2.2 Selection route through the bulk write path with a per-object summary.

## 3. Consumers

- [ ] 3.1 Manifest action schema accepts `macro` so a host renders it in the actions menu and bulk bar (nextcloud-vue).

## 4. Tests

- [ ] 4.1 `tests/e2e/ci/macro-action.spec.ts`: invoke a macro from a list, see the changes and land on the next item.
- [~] 4.2 Unit tests for the validator, authorisation, sync result, bulk summary and `next`.

## Status, 2026-09-18

**Built: the binding and its refusals (1.1), and the hint's vocabulary (1.2's
declaration half).**

- A declared action may carry `macro: true` and `flow`. `MacroActionBinding`
  owns the SHAPE — a macro with no flow, a flow with `macro` not true, a
  non-string flow — and `MacroActionValidator` owns the three questions about
  the flow itself: it exists, it is published, it has a manual trigger. The
  schema save refuses, naming the action.
- All three flow refusals are SILENT at run time, which is why they are
  refused at save. An action bound to a missing flow appears in the menu, does
  nothing when clicked, and looks exactly like a flow that ran and changed
  nothing. The handler cannot tell those apart and cannot fix either.
- `next` is now the manual trigger's only config key and one of the end node's,
  and `FlowNextHint` resolves the effective value: the end node the run reached
  wins when it declares one, and an end node that declares NOTHING is silence
  rather than an override to `stay`. A word outside the vocabulary is refused
  at authoring time rather than quietly read as the default — read as `stay`, a
  typed `nextItem` would author, save and behave like a setting nobody made.
- `flow` holds the flow's **uuid**. Flows carry no slug; the proposal's word
  was aspirational and the identifier the system actually has is the uuid.

**Two existing tests changed, because this change changes their contract:** the
manual trigger's vocabulary was asserted as empty, and the palette's end-node
vocabulary as `['error', 'message']`. Both now assert the new contract, and the
manual trigger gained a test that its refusal fires.

**Not built:**

- **1.2's second half, the effective `next` in the run RESULT.** `FlowNextHint`
  answers it; nothing puts it in the envelope yet, because the envelope is
  written by the run path that section 2 adds.
- **2.1 and 2.2, the action routes**, single and bulk. There is no
  `/actions/{action}` route on objects at all today, so this is a controller,
  a route pair and the bulk-write path, not a repair.
- **3.1**, the manifest action schema accepting `macro` — nextcloud-vue's, and
  it is inert until the routes exist.
- **4.1**, the e2e, which the spec already excludes until a host honours
  `next`.
- **4.2 is partial**: the validator and the hint are covered; the
  authorisation, sync result and bulk summary are covered by nothing, because
  they are not built.
