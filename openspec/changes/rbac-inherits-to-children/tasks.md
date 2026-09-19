# Tasks: rbac-inherits-to-children

## 1. The declaration

- [ ] 1.1 `x-openregister-hierarchy` (`parent`, `maxDepth`) accepted by the schema annotation validator; the property must be a declared reference to the same schema, otherwise the save fails with 422 (D-1).

## 2. Resolution

- [ ] 2.1 `PermissionHandler`: a per-object grant on an ancestor answers for a descendant, with the ancestor's verbs and no others (D-2).
- [ ] 2.2 `MagicRbacHandler`: the same ancestor term in the list filter, as one recursive query, so a list and an object read agree (D-3).
- [ ] 2.3 Cycle detection and the depth cap, both failing closed with a logged refusal (D-4).

## 3. Provenance

- [ ] 3.1 `GET /api/scopes` and the scope audit report an inherited grant with the ancestor object it came from (D-5).

## 4. Tests

- [ ] 4.1 `tests/e2e/ci/rbac-inherits-to-children.spec.ts`: grant read on a root, read a grandchild, be refused a write on it.
- [ ] 4.2 Unit tests for the verb rule, the cycle, the depth cap and the list filter; a performance test on a tree of depth 5 that keeps the list inside the ADR-009 budget.
- [ ] 4.3 `openspec validate rbac-inherits-to-children --strict`.
