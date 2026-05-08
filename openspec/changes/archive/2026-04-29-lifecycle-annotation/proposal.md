# Lifecycle Annotation

## Problem
Every Conduction app reinvents the same state-machine machinery (`TRANSITIONS` const + `*Service::transition()` + `*Controller::lifecycle` + `POST /api/<resource>/{id}/lifecycle`). The audit on 2026-04-29 totalled ~2,300 lines of identical-shape code across decidesk + pipelinq.

OpenRegister's `event-driven-architecture` already provides the right hook (`ObjectUpdatingEvent` with `StoppableEventInterface`); apps don't need a new engine, they need a declarative way to use what's already there.

## Proposed Solution
Add the `x-openregister-lifecycle` schema annotation + a pre-save validator that subscribes to `ObjectUpdatingEvent` and rejects invalid transitions, plus a sugar `POST /api/objects/{id}/transition` endpoint and a typed `ObjectTransitionedEvent` for downstream listeners. Net: ~150 lines of OR code; ~2,300 lines deletable from apps.
