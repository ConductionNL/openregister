---
kind: code
status: superseded
superseded-by: flow-engine-unification
---

> **SUPERSEDED (2026-08-03) by `flow-engine-unification`.** Do not continue this
> change and do not merge its outstanding branch work.
>
> This change builds a visual builder on top of `x-openregister-flows` as a flat
> `{ name, trigger, actions[] }` list executed by `FlowActionService`. That
> dialect and that service are being **removed**: there is one flow engine
> (`FlowEngine`, node/edge, registry-driven), one flow store
> (`oc_openregister_flows`), and `x-openregister-flows` is redefined to declare
> flows of the engine type.
>
> Two concrete consequences:
>
> - Its Phase 1 artefacts (`CnFlowCanvas`, `CnFlowCanvasModal`) exist only on the
>   unmerged `codeberg/feat/vue-3` branch despite being ticked `[x]` here. They
>   must NOT be merged to `beta`; `CnFlowDetail` / `CnFlowEditModal` replace them.
> - This directory is deliberately **kept rather than deleted**. Five surviving
>   files still carry `@spec` anchors into `specs/flow-builder/spec.md` and
>   `specs/integration-flow/spec.md` (`FlowController`, `EventCatalogService`,
>   `EventCatalogListener`, `RegisterObjectEntity`,
>   `FlowEngineRegistrationListener`) — the event/node catalog and the Nextcloud
>   Flow entity registration are genuinely still in force. Removing the directory
>   would break `spec-anchor-existence` on all five.

## Why

OpenRegister already runs object-CRUD automations: `FlowActionListener` dispatches
`ObjectCreated/Updated/Deleted` events to `FlowActionService::run()`, which reads a
schema's `x-openregister-flows` (`{ name, trigger, actions[] }`) and executes each
action. The only authoring surface is `CnEditFlowsModal` — a flat **form** (schema
picker → trigger dropdown → repeatable action cards). That is enough for a two-step
email/calendar rule but does not scale to the product goal: a **visual, n8n-style
flow builder** the user reaches from the in-app "Edit flows…" menu on any object
page (e.g. procest `/cases`).

Three gaps block that goal:

1. **No visual builder.** The shared library ships `CnGraphCanvas` (ADR-065:
   pan/zoom/drag/drag-to-connect renderer) but nothing wires it to the
   `x-openregister-flows` contract, so flows can only be authored as a form.
2. **Triggers are object-CRUD only.** `FlowActionListener` hard-codes
   created/updated/deleted. The product needs to grow to **any Nextcloud event**
   (file, share, calendar, user, group, …) without rewriting the engine per event.
3. **No interoperability with Nextcloud Flow.** Nextcloud ships its own automation
   system (`workflowengine`: rules → operations). Today the two are siblings that
   ignore each other. The product requires them to **compose both ways**: an
   OpenRegister flow can invoke a Nextcloud Flow operation as one of its blocks,
   and an OpenRegister flow is itself exposed as a Nextcloud Flow **operation** so
   a native Flow rule can trigger it.

## What Changes

- **ADD** a visual flow builder (shared library `CnFlowCanvas` + `CnFlowCanvasModal`)
  that renders a schema's `x-openregister-flows` as a node graph on `CnGraphCanvas`
  — one **trigger** node per flow plus one node per action, edges defining execution
  order — with a node palette (drag-to-add) and per-node config panels. It loads and
  saves through the existing contract (`GET`/`PATCH /apps/openregister/api/schemas/{id}`,
  key `x-openregister-flows`), so the form editor and the canvas stay
  interchangeable and forward-compatible. "Edit flows…" opens the canvas.
- **EXTEND the trigger model** from the three object-CRUD verbs to a declarative
  **event catalog**: a flow's `trigger` may name any registered event source
  (`object.created`, …, and Nextcloud events such as `file.created`,
  `share.created`). A generic listener maps a subscribed catalog event to
  `FlowActionService::run()` with the resolved trigger, so new triggers are data,
  not new listeners. Object-CRUD triggers remain the default and stay
  backward-compatible (bare `created`/`updated`/`deleted`).
- **ADD object-CRUD action types** (`object.create`, `object.update`,
  `object.delete`, `object.set-field`, plus a `condition` guard) alongside the
  existing `email` / `calendar-event` / `agent` / `federate-share` actions, so a
  flow can act on the object graph, not just notify.
- **ADD bidirectional Nextcloud Flow interop**
  (`integration-flow` capability):
  - Nextcloud Flow **operations become blocks** in the builder — the builder lists
    registered `workflowengine` operations and a `nc-flow-operation` action invokes
    the chosen one at execution time.
  - Each OpenRegister flow is **registered as a Nextcloud Flow `IOperation`**, so a
    native Flow rule (any `IEntity`/check set) can invoke an OpenRegister flow —
    letting the two engines chain in either direction.

## Impact

- New shared-library components (`CnFlowCanvas`, `CnFlowCanvasModal`); "Edit flows…"
  re-pointed from the form modal to the canvas (form retained behind a toggle).
- OpenRegister backend: a generic event-catalog listener, new action runners in
  `FlowActionService`, an `IOperation` adapter, and read APIs for the event catalog
  and the NC-Flow operation list. No change to the persisted `x-openregister-flows`
  shape beyond additive `actions[].type` values (unknown types are already
  round-tripped, so old and new clients coexist).
- Delivered in phases: (1) canvas MVP over the current contract, (2) event catalog,
  (3) NC-Flow interop both ways, (4) object-CRUD actions.
