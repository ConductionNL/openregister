---
kind: code
---

# Proposal: stored-decision-tables

## Why

Decision 186 (Ruben, 10 Oct, answering Q-dossiq-L6-4): a named, reusable decision table lives in OpenRegister as a generic stored capability, and a flow references a table by id. Until now OpenRegister's decision-table flow step carried the table inline, and OpenRegister had no store for a named table. dossiq kept its own (`decisionTable` schema, CRUD, five routes, an admin tab), and the VTH enforcement recommendation evaluated a dossiq-stored table. Under ADR-118 (decisions 182, 184, 185) that stack is procedure logic on generic plumbing, and it moves onto this capability (capability C15).

## What changes

- A core register `decision-tables` with schema `decision-table` (`key`, `name`, `description`, `hitPolicy`, `inputs`, `outputs`, `rules`, `owner`), shipped as `lib/Settings/decision_table_register.json` and imported by the repair step `ImportDecisionTableRegister`.
- `DecisionTableValidationListener` refuses, at save, a stored table the evaluator cannot execute (same `DecisionTableValidator` the inline step uses) and a second table under a key another table carries.
- `DecisionTableStore` resolves a table by `key` or uuid, re-validates it on read, and evaluates it for an app that decides outside a flow (`evaluate(reference, inputs)`). An unknown reference is refused, never answered with an empty table.
- The flow step `openregister.decision-table` takes `tableRef` as an alternative to an inline `table`. Exactly one of the two. A `tableRef` that names no executable table refuses the flow at save and fails the step at run.

## Out of scope

- An authoring screen for stored tables. The generic object views of the register serve until the decision-table editor is pointed at the register (follow-up).
- dossiq's move onto this capability. That ships in dossiq: its `decisionTable` rows move here by a repair step, and its translator and LHS lookup read from here.
