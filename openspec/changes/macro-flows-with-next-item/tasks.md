# Tasks: macro-flows-with-next-item

## 1. Binding

- [ ] 1.1 `flow` and `macro` on declared actions with the three refusals at schema save.
- [ ] 1.2 `next` on the manual trigger node config and on end nodes; effective `next` in the run result.

## 2. Execution

- [ ] 2.1 Single-object action route queueing the flow with subject and attribution, sync by default, answering run id, outcome, `next`; audit entry.
- [ ] 2.2 Selection route through the bulk write path with a per-object summary.

## 3. Consumers

- [ ] 3.1 Manifest action schema accepts `macro` so a host renders it in the actions menu and bulk bar (nextcloud-vue).

## 4. Tests

- [ ] 4.1 `tests/e2e/ci/macro-action.spec.ts`: invoke a macro from a list, see the changes and land on the next item.
- [ ] 4.2 Unit tests for the validator, authorisation, sync result, bulk summary and `next`.
