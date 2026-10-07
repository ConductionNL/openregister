# Tasks: rbac-inherits-to-children

## 1. The declaration

- [x] 1.1 `x-openregister-hierarchy` (`parent`, `maxDepth`) accepted by the schema annotation validator; the property must be a declared reference to the same schema, otherwise the save fails with 422 (D-1).

## 2. Resolution

- [x] 2.1 `PermissionHandler`: a per-object grant on an ancestor answers for a descendant, with the ancestor's verbs and no others (D-2).
- [x] 2.2 The same ancestor term reaches the list filter, so a list and an object read agree (D-3). **Done by construction rather than by a second SQL term, which is the stronger form:** the expansion happens on the GRANT SET inside `ObjectGrantResolver`, which is the one funnel `MagicRbacHandler::quotedGrantedUuids()` and the per-object `isGranted()` both read, so the two cannot diverge. The spec's "single recursive query" is a bounded descent by LEVEL instead: `maxDepth` queries per hierarchical schema per request, not one, and not one per row either. The intent (openregister ADR-009, no walk per object in a list) holds; the literal wording does not, and the reason is portability of `WITH RECURSIVE` across the four supported backends.
- [x] 2.3 Cycle detection and the depth cap, both failing closed with a logged refusal (D-4).

## 3. Provenance

- [x] 3.1 `GET /api/scopes` and the scope audit report an inherited grant with the ancestor object it came from (D-5).

## 4. Tests

- [x] 4.1 `tests/e2e/ci/rbac-inherits-to-children.spec.ts`: grant read on a root, read a grandchild, be refused a write on it.
- [x] 4.2 Unit tests for the verb rule, the cycle, the depth cap, the provenance and the declaration (26 cases across two suites). **The performance test is NOT done**: it needs a live database with a seeded tree of 500 objects at depth 5, which this phase's clone has no instance for. The bound it would measure is enforced structurally instead (the descent is O(depth) queries, never O(rows)), and the test belongs with the live-DB suite.
- [x] 4.3 `openspec validate rbac-inherits-to-children --strict`.
