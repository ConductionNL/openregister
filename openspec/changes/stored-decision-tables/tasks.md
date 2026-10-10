# Tasks: stored-decision-tables

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 6. -->

- [x] 1.1 Ship the `decision-tables` register and its import repair step (REQ-SDT-01)
- [x] 1.2 Refuse a stored table the evaluator cannot run, and a duplicate key, at save (REQ-SDT-02)
- [x] 1.3 `DecisionTableStore` resolves by key or uuid and refuses an unknown reference (REQ-SDT-03)
- [x] 1.4 The decision-table step accepts `tableRef`, exactly one of `table` and `tableRef` (REQ-SDT-04)
- [ ] 1.5 Live: import the register on a running instance, store a table, run a flow step by `tableRef` (owed to the live pass)
- [ ] 1.6 Point the decision-table editor at the register (follow-up)
