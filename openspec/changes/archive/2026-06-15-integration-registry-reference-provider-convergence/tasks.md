# Tasks

## Phase 0 — Dedup

- [x] 0.1 Search `openspec/changes/` for an existing convergence / reference-provider
  investigation change — none found (this is the first; ADR-041 defers it here).
- [x] 0.2 Confirm OR already ships `OCA\OpenRegister\Reference\ObjectReferenceProvider`
  (registered via `registerReferenceProvider` in `Application.php`) so the investigation builds
  on an existing precedent rather than greenfield.

## Phase 1 — Investigate (read-only)

- [x] 1.1 Read ADR-041 and extract Decision #3 + the deferred open question.
- [x] 1.2 Inspect the OR registry surface: `IntegrationProvider`, `IntegrationRegistry`,
  `AbstractIntegrationProvider`, `ExternalIntegrationRouter`, the 22 built-in providers, and the
  boot/registration in `Application.php`.
- [x] 1.3 Inspect NC's `OCP\Collaboration\Reference\*` contracts in vendor
  (`IReferenceProvider`, `IReference`, `ISearchableReferenceProvider`,
  `IDiscoverableReferenceProvider`, `RenderReferenceEvent`, `IReferenceManager`).
- [x] 1.4 Determine which built-in providers implement write verbs (CRUD-capable) vs read-only,
  and the storage-strategy distribution (magic-column / link-table / external / query-time).

## Phase 2 — Author the deliverable

- [x] 2.1 Write the responsibilities matrix (READ/RENDER vs VALUE-ADD). [REQ-CONV-001]
- [x] 2.2 Write the contract comparison (OR registry vs `IReferenceProvider`). [REQ-CONV-001]
- [x] 2.3 Write the go/no-go recommendation + rationale + phased plan + risks. [REQ-CONV-002]
- [x] 2.4 Write the migration blast-radius enumeration. [REQ-CONV-003]
- [x] 2.5 Commit the decision record under `docs/development-notes/`. [REQ-CONV-001..003]

## Phase 3 — Verify

- [x] 3.1 Confirm no production code under `lib/` or frontend `src/` was modified. [REQ-CONV-004]
- [x] 3.2 `openspec validate integration-registry-reference-provider-convergence --strict`.
