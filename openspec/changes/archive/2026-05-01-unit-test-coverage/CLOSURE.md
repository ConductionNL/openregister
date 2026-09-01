# Closed as superseded — 2026-05-01

Proposal-only stub (0/19 tasks ticked). The spec.md inside the change carries `status: implemented`. The 75% coverage gate infrastructure already ships and CI enforces it; the only "open" item is the 100% stretch goal, which is an ongoing effort, not a single change.

**Superseded by:**
- `scripts/coverage-guard.php` — Clover XML guard that blocks PRs which drop coverage below the baseline
- `.coverage-baseline` — persisted floor (currently 0.00 — meaning the gate is wired but the floor has not been raised yet; raising the floor is per-PR work, not a stub-spec deliverable)
- `composer.json` scripts: `test:coverage`, `test:coverage-docker`, `coverage:check`, `coverage:update`
- `.github/workflows/quality.yml` with `enable-coverage-guard: true` (CI gate active)
- `tests/Unit/` — 426 unit-test files across `Service/`, `Controller/`, `Db/`, `Event/`, `Listener/`, `Cron/`, `BackgroundJob/`, `Middleware/`, `Repair/`, `Formats/`, `Dto/`, `Activity/`, `CustomSniffs/`
- ADR-008/ADR-009 governing the standard

**Canonical specs to keep evolving instead of this change:**
- `openspec/changes/archive/2026-05-01-unit-test-coverage/specs/unit-test-coverage/spec.md` (status: implemented) — promote to `openspec/specs/unit-test-coverage/spec.md` when next touched.

**What did NOT ship from this proposal (real ongoing work, not stub-fixable):**
- The 100% stretch goal — this is a per-PR push, not a single change. Each new feature PR is expected to land its own tests and ratchet `.coverage-baseline` up.
- `phpunit-unit.xml` Db exclusion review — minor; tackle inline next time the file is touched, not via a stub spec.

If you want to push coverage up materially, open focused changes per area (e.g. `unit-tests-magic-mapper`, `unit-tests-organisation-multitenancy`) — don't revive this catch-all proposal.
