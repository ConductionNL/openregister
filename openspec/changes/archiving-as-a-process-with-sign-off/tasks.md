# Tasks: archiving-as-a-process-with-sign-off

## 1. Nomination

- [x] 1.1 On reaching a terminal state, derive the archival nomination and the archiefactiedatum from the selectielijst and write both on the object with the rule that produced each (D-2).
- [x] 1.2 An object that cannot be nominated is reported with the reason, never skipped silently.
- [x] 1.3 Recomputation is an explicit act and is recorded.

## 2. The preservation regime

- [ ] 2.1 A preservation state beside the archive state of `object-archive-state`: out of working views, refusing content writes, keeping references (D-3). **BLOCKED**: `object-archive-state` has not shipped, so there is no archive state for a preservation state to sit beside. Building half of it and calling the requirement met is the failure this note exists to prevent. Nomination already moves a closed record to `RecordState::SEMI_STATIC`, which is the state the regime will govern.
- [ ] 2.2 The two states are declared side by side and a schema may offer either, both or neither. **BLOCKED on `object-archive-state`**, see 2.1.

## 3. The reviewer and the worklist

- [x] 3.1 A destruction list entry carries an accountable reviewer; a list with unassigned entries names them (D-4).
- [x] 3.2 A reviewer reads their own pending items across lists.
- [x] 3.3 A declared reminder frequency while items wait, through the notification engine (D-4).

## 4. Three answers, and the record

- [x] 4.1 A review answer is destroy, retain with a new date and a reason, or transfer; the choice is recorded in one decision history (D-5).
- [x] 4.2 A transfer answer hands the item to `edepot-transfer` and keeps the record here.

## 5. The facts on the object, the mapping and the plan

- [x] 5.1 The object read carries nomination, archiefactiedatum, selectielijst row, statutory basis, any hold and the destruction or transfer record.
- [x] 5.2 An administered MDTO and TMLO element mapping with a validator; a transfer with an unmapped mandatory element is refused naming the element (D-6).
- [x] 5.3 Import a selectielijst or classification plan from a file, versioned, with a diff against the version in use (D-7).

## 6. Tests

- [x] 6.1 `tests/e2e/ci/archiving-process.spec.ts`: assign, worklist, retain, transfer, one history, and now closing an object and reading its nomination, its date, its selectielijst row and its recomputation.
- [~] 6.2 Unit tests: the unassigned list, the three answers, the reviewer guard, the reminder pass, the derivation and its rule, the unnominatable object, the facts on the read, the element mapping, the transfer refusal and the plan diff are written (92 tests). Only the preservation state is left, and it waits on task 2.
- [x] 6.3 `openspec validate archiving-as-a-process-with-sign-off --strict`.

## 7. Hand over

- [ ] 7.1 Hand the format half to the filinq lane (C-documents-9, C-documents-21, C-documents-33) and the resultaattype half to the dossiq lane, with ledger rows 11.22, 13.24, 7.7 and 8.1.
- [x] 7.2 Record C-integrations-24 as an audit to commission rather than code to write (proposal, "Out of scope").

## Shipped so far

Part one (the review half) shipped as openregister#3736: tasks 3.1 to 3.3, 4.1,
4.2, 6.3 and 7.2, with the review half of 6.1 and 6.2. The process contract it
publishes is in that PR body, for the dossiq and filinq consumer lanes.

Part two (nomination at closure and the facts on the object) ships on
`feat/archiving-nomination-and-preservation`: tasks 1.1 to 1.3 and 5.1.

What is left, and why:

- Task 2, the preservation regime, is BLOCKED on `object-archive-state`. That
  change is unimplemented: there is no `@self.archived` marker and no
  `x-openregister-archive` annotation anywhere in `lib/`, so a preservation
  state cannot be shown to be distinct from an archive state that does not
  exist. It goes in the same PR as `object-archive-state` or straight after it.
- Tasks 5.2 and 5.3 shipped in the third PR: the element mapping with its
  validator and the transfer refusal, and the versioned selectielijst import
  with its diff.

Only task 2 is left. The preservation regime needs an archive state to be
distinct from, and `object-archive-state` is being built in its own lane.
