# Parallel paths and waits in a flow

A flow can do two things at once and wait for both, and it can stop until
someone outside the flow answers. This page shows how to draw that in the flow
editor, and how a process modelled in another tool gets there through a BPMN
import. The engine runs the saved flow exactly as it is drawn; the editor adds
no hidden steps.

## Split a path

Draw two or more edges out of one node. Every outgoing edge starts a path, and
the paths run side by side. There is no separate "split" node: fanning out is
what several outgoing edges mean.

## Wait for all paths

Where the paths meet, draw each of them into the same node. What that node
does depends on one setting:

- **Off (the default): merge.** The node runs each time a path arrives. Use it
  when any one path is enough.
- **On: wait for all paths.** The node is a join. It runs once, after every
  incoming path has arrived, with the items of all of them. In the stored flow
  this is `"join": true` on the node.

```json
{
  "id": "beslissen",
  "type": "openregister.merge",
  "name": "Beslissen",
  "join": true
}
```

`openregister.merge` is a good node to join on: in its default `append` mode it
passes on everything that arrived, in the order of the incoming edges. Its
`mergeByKey` and `unique` modes combine the items instead.

## Wait for an answer from outside

Place an `openregister.await-signal` node where the process has to wait. When a
run reaches it, the run is suspended at that node and the run page shows it
waiting there. Its form asks:

- **What is being asked** (`question`): shown to whoever answers, and written to
  the run log.
- **Who should answer** (`assignee`): a user or group id, recorded with the
  request. It does not by itself restrict who may answer.
- **Field to store the answer in** (`signalKey`, default `signal`): the answer
  is written onto every item under this field, so the next steps can route on
  it, for example with `openregister.switch`.
- **Re-check every (minutes)** (`heartbeatMinutes`): a safety net. An answer
  wakes the run at once either way.
- **Treat a rejection as a failure** (`failOnReject`): off by default; route on
  the answer instead.
- **Business key for the answer** (`correlationKey`): lets a caller answer by
  business key instead of by run.

The answer arrives as `POST /api/flow-runs/{uuid}/resume`, with the answer as
the request body, or as `POST /api/flow-run-signals/{key}` for a step that
declared a `correlationKey`. The run then continues from the await-signal node.

To wait for a time rather than an answer, use `openregister.wait` with `for`
(a duration) or `until` (a moment).

## Putting it together

A permit check that runs two reviews side by side and decides after both, with
an outside advice in between:

```
start ──► Budget toetsen ──────┐
   └────► Vergunning toetsen ──┴─► Samenvoegen ─► Wachten op advies ─► Beslissen ─► end
```

- `Samenvoegen` is an `openregister.merge` with `join: true`: both checks enter
  it, and it runs once, after both have finished.
- `Wachten op advies` is an `openregister.await-signal`: the run is suspended
  there until the advice arrives.
- `Beslissen` then runs once, with the items of both checks and the advice in
  the field the await-signal node named.

## Importing a process from BPMN

A process modelled in another tool comes in through
`POST /api/flows/import/bpmn` and opens on the same canvas as nodes and edges.
From then on it is edited and saved like any drawn flow; it does not stay a
BPMN document.

The BPMN elements this page is about import as follows:

| BPMN element | Becomes |
|---|---|
| `parallelGateway` that splits | `openregister.merge`, its outgoing edges running side by side |
| `parallelGateway` that joins (two or more incoming flows) | `openregister.merge` with `join: true` |
| `intermediateCatchEvent` with a message | `openregister.await-signal` |
| `intermediateCatchEvent` with a timer | `openregister.wait` |
| `sequenceFlow` | an edge |

The import returns a report that lists every element as mapped, approximated or
refused, with what to do about each one that is not mapped.

### What the import does not do

- An `inclusiveGateway` becomes an `openregister.route`, which takes one
  branch. A model that expects several branches to run needs splitting by hand.
- A BPMN export does not yet write `join: true` back out, so a flow exported and
  imported again loses its joins. Set "Wait for all paths" again after such a
  round trip.
