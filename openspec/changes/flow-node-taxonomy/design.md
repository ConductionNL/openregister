# Design

## D-1 · Two axes, because one cannot do both jobs

A single field would have to be either semantic or navigational, and each
choice loses the other.

Semantic only: the palette groups "Ask a person" with "Approve payroll" and
"Request a decision" — correct, they are all user tasks — and separates
"Ask a person" from "Wait for an answer", which are the two halves of one
authoring decision and belong side by side.

Navigational only: the BPMN interchange has nothing to emit, and would end up
inferring an element type from a category, which is the mapping this design
exists to avoid.

n8n reached the same conclusion from the other direction: an architectural
kind that decides how a node may be wired, and a separate palette category
that includes "Human in the loop". The categories here are ours because the
domain is ours; the kinds are BPMN's because an interchange has to speak it.

## D-2 · Why the defaults are on the interface, not enforced

43 of 64 nodes live in repositories this change cannot touch, on their own
release cycles. Three options existed:

1. Require the methods. Every contributed node fatals on the next release of
   openregister. Not viable.
2. Guess on their behalf from the node id or class name. Cheap, and produces
   a *wrong* BPMN element type that nobody will ever revisit, because it
   looks answered.
3. Default them, visibly, and let each owner declare.

Option 3 is the only one where being undeclared is distinguishable from being
declared. `other` in the palette is a prompt; a guessed `serviceTask` is a
lie with a long half-life.

The gate then keeps this repository honest without reaching into anyone
else's.

## D-3 · `receiveTask` is in the `human` category on purpose

The most tempting mistake in the category axis is to derive it from the kind.
`openregister.await-signal` is a `receiveTask` — it waits for a system to
call back — and by kind it belongs with the integration steps.

But its docblock already says what it is for: it is the machine-to-machine
half of a pair whose other half is "Ask a person", and the node's own
description tells authors which to pick. An author deciding between them is
looking in one place. Splitting the pair across two palette groups would undo
the only thing that currently helps them choose.

This is the concrete case that proves the axes must be independent.

## Traps

**Do not let the palette order come from the registry.** Registration order
depends on which apps are installed and in what order their listeners fire,
so the same palette reorders itself when an unrelated app is enabled. Fixed
order, defined once.

**The gate must be scoped by path, not by interface.** A gate that flags any
`IFlowNode` implementation missing the methods will fire on every contributed
node in the workspace, which is exactly the behaviour the defaults exist to
prevent. Scope it to this repository's own node directory.

**`getKind()` describes the step, not its subject.** An object-write step is
a `serviceTask` because it calls something, not a `businessRuleTask` because
the object has rules. The first wrong classification here will be copied by
the next twenty.
