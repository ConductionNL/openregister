# A trigger is a node, and a flow may have several

## Why

A flow can be started more than one way. The same flow should be able to run
on a schedule *and* when an object is created *and* on demand — the sequencer
is exactly that shape today, and the model cannot say it.

Right now a trigger is four columns on the `Flow` entity:

| column            | holds                                  |
|-------------------|----------------------------------------|
| `trigger`         | one of `object.created`/`object.updated`/`object.deleted`/`schedule`/`manual` |
| `triggerRegister` | the subject register, for object events |
| `triggerSchema`   | the subject schema, for object events   |
| `cron`            | the expression, for `schedule`          |

One row therefore holds exactly **one** trigger, and the fields are
mutually exclusive in a way the shape does not express: `cron` is dead on an
object trigger, `triggerRegister`/`triggerSchema` are dead on a schedule.

This has two consequences that are visible to an operator:

1. **A flow cannot have two triggers.** There is nowhere to put the second
   one. The only workaround is a duplicate flow, which then has to be kept in
   step with the original by hand.
2. **The trigger is edited in a settings pane** rather than on the canvas,
   even though it is the thing the run STARTS from. Every other piece of
   behaviour moved onto the graph when the engine inverted its model
   (`flow-engine`: "Each node MUST carry the `type` and `config` of the step
   it performs"). The trigger is the one piece left behind, so the canvas
   shows a flow whose beginning is off-screen.

The second is the reason the first went unnoticed for so long: a settings
pane has room for exactly one of everything, so the limit looked like a
layout, not a model.

## What changes

A trigger becomes a **node**, like every other piece of behaviour:

- A new node type per trigger kind, carrying its own parameters in `config` —
  the register/schema pair for object events, the cron expression for a
  schedule, nothing for manual.
- A flow may carry **any number** of trigger nodes. Each is an entry point:
  firing one queues a run that starts from that node's outgoing place.
- `FlowTriggerService` and the schedule service resolve flows by reading
  trigger NODES, not the flow's columns. `IFlowResolver` implementations
  answer "which flows are wired to this event" from the graph.
- The four columns are migrated to a single trigger node per existing flow
  and then stop being read. They are NOT deleted in the same change — see
  Risks.

A flow with **no** trigger node is legal and means the same thing `manual`
means today: nothing starts it on its own.

## What does not change

- The Petri-net lowering, merge/join semantics, and dispatch are untouched. A
  trigger node contributes an entry point; it is not a step the dispatcher
  executes.
- Nothing about how a run behaves once it has started.

## Risks

**The migration is the whole risk.** A flow whose trigger stops resolving does
not fail loudly — it simply never fires again, and a flow that never fires
looks identical to a flow with nothing to do. The columns therefore stay
populated and authoritative until the node path is proven on real flows, and
the cutover is a separate change with its own verification: for every existing
flow, the set of events that resolve it MUST be identical before and after.

**Two entry points into one graph is a new shape for the lowering.** Merge
semantics already cover several edges converging on a node, but several
STARTING places is not something the builder has had to express. This needs a
test that two triggers on one flow produce two independent runs, not one run
with two tokens.

## Open questions

- Does a run record which trigger node started it? It should — "why did this
  run" is unanswerable otherwise once a flow has more than one entry point.
- Should two trigger nodes be allowed to converge on the same first step, or
  must each own its path until an explicit merge?
