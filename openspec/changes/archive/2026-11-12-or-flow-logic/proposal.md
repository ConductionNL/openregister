# Proposal: or-flow-logic

## Summary

Give flows the two pieces of control they still lack: conditional branching
(If / Switch) and a deliberate end (Stop / Stop-And-Error).

## Why

The engine fired `$enabled[0]` — the first enabled transition — so a node with
several outgoing edges always took the first one, whatever the data. A flow
could fan out in the Petri net but could not *decide* which branch to take.
That is the difference between a pipeline and a workflow.

## What Changes

- **Conditional edge selection in the engine.** Among the enabled transitions,
  the engine fires the first whose edge `condition` (JSONLogic) holds. An edge
  with no condition is the default/else, taken only when no conditioned sibling
  matched. If nothing is eligible — every branch gated by a failing condition,
  no default — the run ends at that choice point instead of spinning on
  un-fireable transitions until the ceiling.

  Routing lives on the EDGE, not in a node. So any node can branch: an agent
  step can have a `succeeded` and a `failed` edge. There is no special router
  type the engine has to understand.

- **`SwitchNode`** — the visible palette anchor for a branch. A pass-through:
  it drops on the canvas, an author draws conditioned edges from it, and the
  engine does the routing. Convenience, not a requirement.

- **`FlowStop` + `StopNode`** — a step that ends the run on purpose, the
  counterpart to `FlowSuspension`. A plain stop is a clean `stopped`; an error
  stop is `failed` with the message. Caught before the generic `Throwable`, so
  it is never mistaken for a step failure and never subject to an `onError`
  policy — the author asked the run to end.

- **Palette resilience.** One node whose metadata throws (a missing icon, a
  broken translation) is skipped and logged rather than blanking the whole
  palette. Same principle as the dashboard fix (#2087): one bad unit degrades,
  it does not take everything down. Found by shipping a `fork.svg` icon that
  does not exist in Nextcloud 34.

## Design decisions

- **A condition evaluates against the first item** as the list's
  representative. Per-item routing — each item down a different branch — needs
  the engine to carry more than one item list across a split and is not
  attempted here.
- **First match wins, by declaration order.** A Switch's cases are tried top
  to bottom; a matching case always beats the default regardless of where the
  default is declared.

## Out of scope (this change)

- **Merge / join item semantics** — combining two branches' item lists
  (append, merge-by-key, wait-for-both). The Petri net already joins on the
  marking; what it does not do is combine the *items* the branches carried, and
  that needs per-place item storage in the engine. A follow-up.
- **Loop Over Items** — largely redundant now that steps are item-aware (a node
  already acts once per item), but batching with a size still has a place. Later.
- **Sub-flows** — calling a flow from a flow. Needs the run queue to suspend the
  parent while the child runs; the queue exists (#2078) but the wiring does not.
