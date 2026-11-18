# Tasks: functional-areas-depend-in-one-direction

## 0. The measure is a test
- [ ] 0.1 hydra ADR-119 (revised 2026-11-18) accepted
- [ ] 0.1b `scripts/component-map.csv`: every contract in `lib/Kernel/Contracts/<Area>/` assigned to its owning area, so a call through it counts as a line to its owner
- [ ] 0.2 `rate_component_views.py --check BASELINE` and `--write-baseline`: exposed lines per area, Kernel size and violation degree in the functional view, no lizard needed
- [ ] 0.3 `tools/sig/modularity-baseline.json` recorded on main
- [ ] 0.4 `tests/Unit/Architecture/ComponentDependencyRulesTest.php`: the order of the areas (design.md) and the contract ranks, with today's references as a shrinking exception list
- [ ] 0.5 `tools/sig/deptrac/areas.yaml` follows the order; `areas.baseline.yaml` recorded
- [ ] 0.6 `composer check:modularity` runs 0.2 and 0.5; `check:strict` calls it
- [ ] 0.7 Unit tests for the check, the baseline writer and the rank rules

## 1. Wiring stays with its area
- [ ] 1.1 Bindings of other areas out of `Db/DbRegistrar.php` into each area's own registrar
- [ ] 1.2 The other registrars that bind classes of another area (183 references in total)

## 2. Kernel does not refer upward
- [ ] 2.1 `Db/Schema`, `Db/Register`, `Db/ObjectEntity`: references to Core, Processes and Configuration replaced by Kernel types or ids (80 + 17 + 7)
- [ ] 2.2 Events naming Integrations and Platform classes (`RegisterLeafProvidersEvent`, `ToolRegistrationEvent`, `CircularReferenceException`) carry ids or Kernel contracts

## 3. Core does not refer upward
- [ ] 3.1 Core to Processes (317): schema declaration validators and task references through contracts Core defines
- [ ] 3.2 Core to Compliance (244): audit and transfer through Core events
- [ ] 3.3 Core to Integrations (236): webhooks and sources through Core events and contracts
- [ ] 3.4 Core to Configuration (143), Search (92), Files (60), Platform (57), Notifications (27)

## 4. One direction per pair
- [ ] 4.1 Integrations to Processes (82), and the other pairs inside one level
- [ ] 4.2 The remaining references to Platform and Configuration from lower areas
- [ ] 4.3 Milestone: violation degree at most 0.0375 (entanglement 4.5)

## 5. A narrow contract set per area
- [ ] 5.1 Contract set per area in `lib/Kernel/Contracts/<Area>/`, callers outside the area typed against it, starting with Core and Processes
- [ ] 5.2 Entities that cross an area passed as ids or Kernel value objects
- [ ] 5.3 Milestone: exposed code at most 4.1% (independence 4.0)
- [ ] 5.4 Milestone: exposed code at most 2.75% (independence 4.5), modularity at least 4.5

## 6. Documentation and fleet
- [ ] 6.1 `docs/sig/component-analysis.md` updated with the numbers after each milestone
- [ ] 6.2 The scripts and the deptrac recipe in the hydra-gates package (ConductionNL/.github)
- [ ] 6.3 Handover note for the fleet in `~/memcap-work/sig/`
