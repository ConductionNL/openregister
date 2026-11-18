# ADR-015: Functional areas depend in one direction

**Status**: proposed (2026-11-18). Refines ADR-013 Rule 1 and Rule 5.

**Date**: 2026-11-18

## Context

`docs/sig/component-analysis.md` measures the backend in the ten functional areas of ADR-012
(`sigrid.components.yaml`). Measured on main `d356db5b71` with `tools/sig/rate_component_views.py`:

| Measure | Today | 4.0 | 4.5 |
|---|---|---|---|
| Exposed code | 37.5% (171,795 lines) | at most 4.1% (about 18,900) | at most 2.75% (about 12,600) |
| Violation degree | 0.194 | at most 0.056 | at most 0.0375 |
| Estimated rating, independence / entanglement / modularity | 1.30 / 2.21 / 2.85 | | |

Two findings explain most of it:

- The foundation refers back. Core and Kernel refer to all eight other areas in 1,210 file references, mostly
  to entities and interfaces those areas own (`Db/Task`, `Db/AuditTrail`, `Db/Source`, `Db/Configuration`).
  With Core and Kernel counted as one foundation, removing those references raises the estimated entanglement
  from 2.01 to 3.45 (simulation E on the page).
- Areas use each other's concrete classes. 1,479 concrete classes (141,213 lines) are referenced from another
  area. Interfaces are 8,723 exposed lines, Kernel itself 8,079.

ADR-013 Rule 1 says a functional area references no other functional area (a star through the Kernel). That
rule leaves one gap: an area may call another area's interface in `lib/Kernel/Contracts`, and the reverse,
without any line in the measured graph. A cycle between two areas then moves into the Kernel, where the
measure no longer sees it. A rating reached that way would not be justified.

## Decision

### Rule 1: one order of the areas

The areas have one order, bottom to top:

| Rank | Area |
|---|---|
| 0 | Kernel |
| 1 | Core |
| 2 | Notifications |
| 3 | Search |
| 4 | Compliance |
| 5 | Files |
| 6 | Integrations |
| 7 | Processes |
| 8 | Configuration |
| 9 | Platform |

Kernel is the shared base, Core the object store with its access control, Platform the wiring and the
pages. Inside the middle of the order, the rank follows the heavier direction of today's references between
two areas (for example Processes to Integrations 113 against 82 back).

An area uses only areas of a lower rank. It uses a lower area through that area's contracts in
`lib/Kernel/Contracts/<Area>/`, never through its concrete classes. A call upward goes through an event or a
contract that the lower area defines and the higher area implements and registers.

### Rule 2: contracts name their owner

A contract in `lib/Kernel/Contracts/<Area>/` belongs to that area. Only areas of a higher rank may call it, and
only that area or a higher one may implement it. The architecture test checks this, so a cycle cannot hide in
the Kernel.

### Rule 3: entities stay in their area

An entity is passed to another area as an id or a value object in `lib/Kernel`. An entity that every area
needs is part of Kernel or Core. Today these are `ObjectEntity`, `Schema` and `Register`.

### Rule 4: wiring stays with its area

Each area binds its own implementations in its own registrar. A registrar of one area does not bind the classes
of another. `Db/DbRegistrar.php` alone holds 183 references against the order today.

### Rule 5: the measure is a test

- `tests/Unit/Architecture/ComponentDependencyRulesTest.php` (ADR-013 Rule 5) fails on a new reference against
  Rule 1 or Rule 2.
- `tools/sig/rate_component_views.py --check` fails when the exposed lines of any area or the violation degree
  rise above `tools/sig/modularity-baseline.json`. A PR that lowers them updates the file in the same PR.
- deptrac (`tools/sig/deptrac/areas.yaml`) checks the same order with a PHP parser, with a baseline that only
  shrinks.
- Kernel has a size ceiling in the baseline. Kernel holds contracts, events, value objects and the three core
  entities, nothing else.

The two readings (the scorer's reader and deptrac) must agree on the direction of every violation they report.

### Rule 6: what does not count as progress

These changes do not count towards a rating, and the review refuses them:

- changing the component definition to make lines disappear;
- moving a class into Kernel only to hide it;
- merging two areas;
- an interface that copies a concrete class method for method;
- `class_alias` or moving files out of the directories Nextcloud prescribes.

## Consequences

- The concrete classes keep their names and directories. Other apps keep working; a new contract is added, not
  renamed.
- About 1,850 file references in 908 files run against the order today. Each one is removed by a change a
  reviewer recognises: an event instead of an upward call, an id instead of an entity, a binding in the right
  registrar, a call through a contract.
- The milestones are 4.0 and then 4.5 on all three ratings of the functional view. 5.5 is not a goal: it means
  nothing exposed and no violation at all, which only one component reaches.

## Alternatives considered

- **Keep the star of ADR-013 without an order.** Rejected: cycles between areas move into the Kernel contracts
  and the measure stops seeing them.
- **Derive the whole order from the data.** Partly adopted: the data decides inside the middle of the order.
  Kernel at the bottom and Configuration and Platform at the top are a design choice.
- **Only deptrac as the gate.** Rejected: the scorer's reader is the one whose numbers we report. deptrac is
  the second reading.

## Related

- ADR-012: the functional areas.
- ADR-013: the layering inside an area and the gate; this ADR refines its Rule 1 and Rule 5.
- ADR-014: the top-level directories as the declared components.
- `docs/sig/component-analysis.md`: the measurements this ADR rests on.
