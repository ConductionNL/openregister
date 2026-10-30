# Tasks: code-list-lifecycle-and-hierarchy

## 1. The concept as a typed, dated object

- [x] 1.1 A scheme declares the shape of its concepts; concepts are validated against it on import and on save (D-2).
- [x] 1.2 `validFrom` and `validUntil` on a concept; outside the window it is not offered as an option and still resolves on read (D-1).
- [x] 1.3 A write of an out-of-window concept is refused with 422 naming the concept and the window.
- [x] 1.4 A scheme may declare an exclusive group; an object holding two concepts of one group is refused.
- [x] 1.5 A system-defined value cannot be deleted; closing its validity window is the way to retire it (REQ-CLH-006).
- [x] 1.6 A value objects still hold cannot be deleted, and the refusal names the count (REQ-CLH-006).

## 2. The hierarchy and the score

- [x] 2.1 A coded property may declare a branch and a leaf-only rule; options are returned as a tree (D-3).
- [x] 2.2 A branch filter on the object query matches objects holding any narrower concept, bounded by depth (D-3).
- [x] 2.3 A concept may carry a weight; a multi-valued coded property may declare a rolled-up score evaluated in the calculation engine.

## 3. Context, roles, help and constraints

- [x] 3.1 A coded property may bind its option subset to another property's value or to a declared context key (D-4).
- [x] 3.2 A property declares a semantic role of `title`, `status`, `assignee` or `term`; a schema declaring two of one role fails to save (D-5).
- [x] 3.3 Administered help text per property, resolvable per language, beside `description`.
- [x] 3.4 A uniqueness constraint over a named field combination with action `refuse` or `report` (D-7).

## 4. Type change

- [x] 4.1 The supported conversions are published; a conversion request previews the result over the stored values and refuses the unsupported ones with a reason (D-6).

## 5. Tests

- [x] 5.1 `tests/e2e/ci/code-list-lifecycle.spec.ts`: retire a value, see it absent from the picker and present on the old record, then filter a list by a branch.
- [x] 5.2 Unit tests: typed concept validation, the window on read and on write, the exclusive group, the branch filter's depth bound, the rolled-up score, the context subset, the duplicated semantic role and the two uniqueness actions.
- [x] 5.3 `openspec validate code-list-lifecycle-and-hierarchy --strict`.
- [x] 5.4 A mutation that ignores the expiry on write, shown red in the PR body.

## 6. Hand over

- [x] 6.1 Hand the typed scheme to the dossiq lane for `code-lists-from-concepts`, with the cluster 6 candidate ids and ledger rows 11.34, 11.40, 11.44 and 11.45.
