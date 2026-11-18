# Design: functional areas depend in one direction

The decision is ADR-015 (`openspec/architecture/adr-015-functional-areas-depend-in-one-direction.md`). This
file describes how the change is carried out and measured.

## The measure and the test are one thing

The numbers on the documentation page come from `tools/sig/rate_component_views.py`, which uses the reader
and the arithmetic of `tools/sig/check_sig_maintainability.py`. The test uses the same reading, so a PR cannot
improve the test without improving the number, or the other way round.

| Check | Reads | Fails when |
|---|---|---|
| `ComponentDependencyRulesTest` (PHPUnit) | the module graph, as the scorer builds it | a reference runs against the order, or a contract is called or implemented by the wrong rank |
| `rate_component_views.py --check` (Python, about 5 s) | the same graph | exposed lines of an area, Kernel size or the violation degree rise above `tools/sig/modularity-baseline.json` |
| `tools/sig/deptrac.sh areas` | a PHP parser | a violation outside `tools/sig/deptrac/areas.baseline.yaml` |

`composer check:modularity` runs the Python check and deptrac. `composer check:strict` runs it after phpstan.

The baseline records, per area, the exposed lines, and overall the violation degree and the Kernel size.
Lowering a number means updating the baseline in the same PR. Raising one is a failure, also for an unrelated
feature PR.

## Waves

Measured on main `d356db5b71`. Every wave is a set of small PRs, each lowering at least one baseline number.

| Wave | Removes | Size today | Effect |
|---|---|---|---|
| 0 | nothing: the tests and baselines | | the numbers can only go down |
| 1 | wiring in the wrong registrar (Rule 4) | 183 references, mostly `Db/DbRegistrar.php`, part of the wave 3 count | mechanical; each binding moves to its own area's registrar |
| 2 | Kernel referring upward | 114 references (80 to Core, 17 Processes, 9 Integrations, 7 Configuration, 1 Platform) | events and exceptions carry ids or Kernel types |
| 3 | Core referring upward | 1,176 references to eight areas | events and contracts that Core defines and the higher area implements |
| 4 | the remaining references against the order | about 560 | one direction per pair, the other through events |
| 5 | concrete classes used across areas | 1,479 classes, 141,213 exposed lines | a narrow contract set per area in `lib/Kernel/Contracts/<Area>/`, callers typed against it |

Simulated on today's code, with Core and Kernel counted as one foundation, removing the references of waves 2
and 3 raises the estimated entanglement from 2.01 to 3.45. Wave 4 has to bring the violation degree under the
4.5 limit; that is a target, not yet a simulation. Wave 5 carries independence, first to 4.0 (at most about
18,900 exposed lines) and then to 4.5 (at most about 12,600, of which Kernel uses about 8,000).

## Per PR

- One area, one kind of change.
- The baseline goes down in the same PR.
- Tests that build a fake container keyed by a concrete class are retargeted to the contract.
- A contract signature is on one line and imports only what it uses, so it adds no duplicated code.
- Internal contracts go in `lib/Kernel/Contracts`, never in `lib/Contract` (gate 67).
- Helpers take at most two parameters; shared state goes on a value object (ADR-011).
- Classes are named after what they do, not after a procedure.

## Fleet

The scripts and the deptrac recipe also go into the hydra-gates package, next to the scorer, so every app can
run the same views on its own `sigrid.components.yaml`. That is a separate PR in ConductionNL/.github.

## Risks

- Wave 5 is by far the largest. Its size is known per area only after wave 0 records the baseline.
- Sigrid's own reader may count a few references differently. The test is the floor; the assessment is the
  arbiter.
- Events dispatched by class name: an event class keeps its name and namespace.
