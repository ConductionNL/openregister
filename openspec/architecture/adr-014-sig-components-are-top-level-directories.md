# ADR-014: SIG components are the top-level directories

**Status**: accepted (2026-11-09). Supersedes ADR-012 in part: the SIG component definition.

**Date**: 2026-11-09

## Context

SIG rates component independence and component entanglement per component. What counts as a component is
therefore a scoping choice, and it moves both ratings.

SIG's guidance for producers defines it. "Evaluation Criteria Trusted Product Maintainability: Guidance for
Producers" (SIG and TÜV NORD CERT), section 2.2, says: "Top-level components are the first subdivision of a
software system as seen by the developers, and are therefore visible in the source code, typically through
the directory structure or through naming conventions." It adds: "only top-level components are considered
during the maintainability evaluation." Section 2.1 says the system owner defines the system boundary during
scoping.

Until now `sigrid.yaml` read `component_base_dirs: ["lib", ""]`. That made every directory under `lib/` a
component. Those directories are technical layers (`Controller`, `Service`, `Db`, `Event` and so on), so
every call from a controller to a service was a call between components. ADR-012 proposed functional
components defined by file pattern instead.

Measured on main with the gate-122 estimate tool:

| component definition | exposed code | independence | entanglement |
|---|---|---|---|
| every directory under `lib/` | 10.3% | 2.63 | 3.91 (degree 0.0598) |
| functional components by pattern (ADR-012) | 35.7% | about 1 | about 2 (degree 0.1963) |
| top-level directories | 0% (100% hidden) | 5.5 | 5.5 (0 lines, 0 violations) |

With top-level directories the overall maintainability estimate is 4.65 to 4.73.

## Decision

A SIG component is a top-level directory of the repository, as developers see it:

- `lib/` is the backend (PHP).
- `src/` is the frontend (Vue).
- `appinfo/`, `templates/` and every other top-level directory with production code is a component of its own.

`sigrid.yaml` declares this with `component_base_dirs: [""]`. Every subdirectory of the repository root
becomes a component. Files in the root itself are grouped into one component, "Root files", as automatic
detection does. A new top-level directory becomes a component without a change to `sigrid.yaml`.

`component_depth: 1` was the other candidate. Sigrid's scope reference does not say how it treats files in
the root. The "Root files" behaviour is documented with `component_base_dirs`, so we use that option.

## Consequences

- The component view matches what a developer sees on opening the repository.
- The layering inside `lib/` stays an internal architecture rule. ADR-013 still governs it: controller to
  service to mapper, no calls back from a lower layer, events instead of calls upward. Our own architecture tests in `tests/Unit/Architecture/` enforce it, with
  the dependency test of ADR-013 Rule 5.
- ADR-012's functional component block is not adopted as the SIG definition. ADR-012 is superseded in part by
  this ADR, for the SIG component definition. Its guidance on published contracts in `lib/Kernel` may stay as
  internal guidance.
- `sigrid.components.yaml` is no longer a candidate for `sigrid.yaml`.
- The two SIG ratings no longer tell us about coupling inside the backend. Our own architecture test does.

## Alternatives considered

- **Functional components by file pattern (ADR-012).** Measured 35.7% exposed today. It needs about 780
  interface moves before it rates well, and 335 patterns to maintain. Rejected.
- **Every directory under `lib/` as a component.** Measured 10.3% exposed, with a floor near 4.4% however many
  interfaces are added. Those are technical layers, not the first subdivision as developers see it. Rejected.

## Source

SIG and TÜV NORD CERT, "Evaluation Criteria Trusted Product Maintainability: Guidance for Producers",
sections 2.1 and 2.2. Sigrid scope reference:
https://docs.sigrid-says.com/reference/analysis-scope-configuration.html. Measurements with the gate-122
estimate tool on main.

## Related

- ADR-012: functional components over the Nextcloud layout (superseded in part).
- ADR-013: component dependency rules, now the internal rules of the backend.
- `ARCHITECTURE.md`: the component section for assessors.
