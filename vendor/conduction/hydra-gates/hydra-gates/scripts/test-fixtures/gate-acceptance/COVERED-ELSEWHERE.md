# COVERED-ELSEWHERE.md — gates fixtured by a suite outside `gate-acceptance/`

The coverage ratchet in `scripts/lib/test_gate_acceptance_matrix.sh` computes
coverage from `expect.conf` rows inside `gate-acceptance/` bundles. Several gates
are already driven through the real wrapper by a dedicated suite that predates —
or is too specific for — the generic bundle format. Without this file the ratchet
would report them as untested, which is a false alarm, and a ratchet that cries
wolf gets switched off.

**This is not an exemption list.** Every gate below HAS repo-shaped planted/clean
coverage; only the location differs. The driver counts these as covered, so:

* a gate listed here must be genuinely covered by the named suite — if that suite
  is deleted or stops asserting the gate, this row becomes a lie the ratchet
  cannot see. Keep the suite name accurate.
* a gate listed here must NOT also appear in `UNCOVERED.md`.

The driver reads this file by grepping `^\| *gate-[0-9]+` and extracting the number,
identically to `UNCOVERED.md`.

| gate | name | suite | fixtures |
|---|---|---|---|
| gate-16 | spec-coverage | `test_gate16_spec_coverage_scope.sh` | `test-fixtures/spec-coverage-scope/app` — diff/full scope matrix over a real two-commit history, `.github#361` |
| gate-19 | e2e-coverage | `test_gate19_coverage_credibility.sh` | `test-fixtures/e2e-credibility/{req-inherit,scenario-level,file-level-tag,honest}` — `.github#356`, `#343`, `#345` and the never-false `test.skip` guard |
| gate-23 | or-abstraction-anti-patterns | `test_gates_23_33_never_green_over_nothing.sh` | `test-fixtures/gates-23-33/{planted,clean}` |
| gate-24 | integration-parity | `test_gates_23_33_never_green_over_nothing.sh` | `test-fixtures/gates-23-33/{planted,clean}` |
| gate-26 | visual-coverage | `test_gates_23_33_never_green_over_nothing.sh` | `test-fixtures/gates-23-33/{planted,clean}` |
| gate-27 | no-phantom-cross-app-rpc | `test_gates_23_33_never_green_over_nothing.sh` | `test-fixtures/gates-23-33/{planted,clean}` + an injected crashing checker |
| gate-29 | gitignore-then-commit | `test_gates_23_33_never_green_over_nothing.sh` | `test-fixtures/gates-23-33/clean` (asserts `na`, never `PASS`, on an unscoped run) |
| gate-30 | public-monitoring | `test_gates_23_33_never_green_over_nothing.sh` | `test-fixtures/gates-23-33/{planted,clean}` |
| gate-31 | img-alt | `test_gates_23_33_never_green_over_nothing.sh` | `test-fixtures/gates-23-33/{planted,clean}` |
| gate-32 | semantic-controls | `test_gates_23_33_never_green_over_nothing.sh` | `test-fixtures/gates-23-33/{planted,clean}` |
| gate-33 | axe-core | `test_gates_23_33_never_green_over_nothing.sh` | `test-fixtures/gates-23-33/{planted,clean}` |
| gate-61 | listener-work-placement | `test_gate_scope_matrix.sh` | `test-fixtures/scope-matrix/app` — push / full / diff matrix, `.github#347` |
