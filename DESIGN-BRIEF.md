# Brief: wave 5 contract design for one functional area (cloud)

Read `BRIEF.md` on this branch first; its goal, rules and decisions apply. This package produces a DESIGN, no code changes in `lib/`.

## The goal of wave 5

Component independence: the share of code that no other area references. Today about 36% of `lib/` is exposed to another area; the target for an estimated 4.0 is at most about 4.1% (about 18,900 lines) and for 4.5 at most about 2.75% (about 12,600 lines), of which Kernel alone takes about 9,700. Every file another area names counts in full, interfaces included. One interface per class cannot reach the target (hydra ADR-119 Decision 9): each area gets a NARROW set of contracts, one per capability, and other areas use only those.

## Your package: one area (named in `packages/<ID>.md`)

Measure on fresh `origin/main` and write `openspec/changes/functional-areas-depend-in-one-direction/contracts/<area>.md`:

1. **The exposed files of your area today**: every file of your area that another area references, with its lines and the outside callers per area (use `tools/sig/modularity_check.py` and `tools/sig/analyse_dependencies.py`; the graph helpers in `tools/sig/check_sig_maintainability.py`).
2. **What outside callers actually need**, grouped into capabilities (the methods and constants they call, not the class surface).
3. **The proposed contract set**: per capability one port in `lib/Kernel/Contracts/<Area>/`, its methods (one line each), which concrete classes implement it, and the lines it costs. Entities that cross the boundary: id or Kernel value object instead, or the reason it must stay.
4. **The budget**: exposed lines before and after, per contract; flag anything that only fits by breaking a rule.
5. **The build packages**: the caller changes cut into PR-sized packages with disjoint files, in dependency order, each with its file list. These become the wave 5 build packages.
6. **Fleet impact**: which of these classes other apps use (`tools/sig/fleet_surface.py` with `tools/sig/fleet_checkout.sh`); nothing other apps use is renamed or removed, and a port other apps need is a candidate for promotion to `lib/Contract` (ADR-119 Decision 14), named but not done here.

Open one PR against `main` with only that design file (title `docs(architecture): contract design for the <area> area`). Do not change `lib/`. Finish with the PR URL and the before/after budget numbers.
