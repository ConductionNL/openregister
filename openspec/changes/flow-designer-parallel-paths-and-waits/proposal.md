---
kind: code
---

# Proposal: flow-designer-parallel-paths-and-waits

## Summary

A person models a process with parallel paths and waits in the flow editor, the process designer every app shares, and the engine runs what was drawn. A BPMN model imported from another tool opens as the same nodes and edges, so it is edited like any other flow. OpenRegister is the process engine for the fleet (decision 393): dossiq and buildiq hold no engine of their own and edit processes here.

## Why

The engine already runs parallel paths and waits. Converging edges merge, and `join: true` makes a node wait for every incoming path (flow-engine, "Converging edges MERGE by default; synchronising requires `join: true`"). A run parks on an external signal until it arrives (flow-engine, "A run suspended on an external signal MUST be reachable"). BPMN import maps a parallel gateway pair onto those nodes (flow-bpmn-interchange).

What no requirement says is the part a person sees: that they can draw this in the editor and that the drawing is what runs. buildiq's row logic-bpmn-process ("Model a multi-step process with parallel paths and waits, in a process designer") cites only the engine rule, so nothing holds the designer to it.

## What changes

- One requirement on flow-engine: the flow editor lets a person split a path, mark the node where the paths meet as waiting for all of them, and place a wait; the saved flow runs as drawn; an imported BPMN model opens on the same canvas.
- Tasks to check the editor against it and close what is missing.

## Out of scope

- A BPMN modeller. The editor edits the imported flow (decision 393).
- New node types. The split, the join and the wait use the nodes the engine has.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| buildiq | logic-bpmn-process | Model a multi-step process with parallel paths and waits, in a process designer | yes (engine), designer not stated |
| dossiq | 3.1 | Case processes modelled in BPMN, imported into OpenRegister and run on its flow engine | provider row |
