# Object Actions

**Status**: Implemented (backend). The nextcloud-vue rendering (`useObjectActions`, manifest `type: object-action`) is a separate change.

## Overview

An object action is a verb a schema declares, such as `publish`, `claim` or `hand-over`, that a person can run on one
object or on a selection. OpenRegister lists the actions a person may run on an object, checks the right and the
precondition, validates the inputs, runs the body and writes one audit entry. An app does not need a controller per
verb: the verb is configuration on the schema.

An action's body is one of three kinds:

| Kind | Body key | What runs |
|---|---|---|
| `transition` | `transition: "<key>"` | a move in this schema's `x-openregister-lifecycle`, with all its guards |
| `flow` | `flow: "<uuid or name>"` | a stored flow (the former macro binding; `macro: true` is read as `flow`) |
| `steps` | `steps: [...]` | an inline straight line of flow nodes, lowered to a flow and run by the same engine |

An entry with no body stays a verb that the schema's `authorization` block can grant and an app can check. It is never
listed as runnable.

## Declaring an action

Actions live in the schema's `configuration['x-openregister-action']`. The entry's key is its identity and the word
the `authorization` block grants.

```json
"x-openregister-action": {
  "publish": {
    "label": { "nl": "Publiceren", "en": "Publish" },
    "description": "Make the decision public from a date.",
    "icon": "publish",
    "surfaces": ["header", "row", "bulk"],
    "inputs": {
      "type": "object",
      "required": ["from"],
      "properties": {
        "from": { "type": "string", "format": "date", "title": "Public from", "default": "@now" }
      }
    },
    "when": { "==": [ { "var": "object.status" }, "decided" ] },
    "whenMessage": { "nl": "Alleen een vastgesteld besluit kan worden gepubliceerd.", "en": "Only a decided decision can be published." },
    "confirm": { "nl": "Het besluit wordt openbaar.", "en": "The decision becomes public." },
    "mode": "sync",
    "next": "stay",
    "steps": [
      { "id": "date", "type": "openregister.set-fields", "config": { "fields": { "publicatiedatum": "{{inputs.from}}" } } }
    ]
  }
}
```

| Key | Meaning |
|---|---|
| `label` | Shown to people; a string or a locale map. Defaults to the key. |
| `surfaces` | Where a host offers it: `header`, `row`, `bulk`, `detail-menu`. Default `["header", "row"]`. |
| `inputs` | A JSON Schema object, validated like an object. Defaults `@now`, `@user` and `@object.<path>` resolve at run time. |
| `when` / `whenMessage` | JSONLogic over `{object, user, inputs}`, and the reason given when it does not hold. |
| `confirm` | A confirmation text a host shows before running. |
| `mode` | `sync` (default) or `async`. A `transition` is always sync. |
| `next` | Where the host goes after the run, as for macro flows. |
| `elevate` | Optional scoped elevation, see below. |
| `reversible` | Optional, as for bulk action jobs. |

### Register-level defaults

A register's `configuration['x-openregister-action']` holds entries every schema in the register inherits. A schema
entry with the same key replaces the inherited one whole, with no deep merge. Inheritance is resolved when the schema
is read, so changing the register changes every schema at once. An inherited action is runnable on a schema only when
that schema's `authorization` grants it, or the schema has no `authorization` block.

### What the schema save refuses

- more than one body, or a `transition` that does not exist in the lifecycle;
- `inputs` that are not a JSON Schema object, or a `when` that is not JSONLogic;
- an unknown surface, or `bulk` on an action whose inputs default from `@object.*`;
- in `steps`: an unknown node type, a node whose config is refused, or a branching or suspending node (`switch`,
  `route`, `wait`, `await-signal`, `user-task`, `portal-task`). Declare a flow and bind it with `flow` instead;
- a node the saver has no right to use;
- an `elevate` with a wildcard, an `elevate` on an action without an `authorization` entry, or one saved by anyone
  but an administrator.

## Listing and running

| Verb | Route | Answer |
|---|---|---|
| GET | `/api/objects/{register}/{schema}/{id}/actions` | the actions this person may run on the object |
| GET | `/api/object-actions/{register}/{schema}` | the actions offered on the `bulk` surface |
| POST | `/api/objects/{register}/{schema}/{id}/actions/{action}` | run on one object; body `{inputs}` |
| POST | `/api/object-actions/{register}/{schema}/{action}` | run on a selection; body `{ids, inputs}` |
| GET | `/api/objects/{register}/{schema}/{id}/actions/history` | the object's action runs, newest first |

The list merges the declared actions with the lifecycle transitions available from the object's current state, so a
provider-mode or graph-mode lifecycle appears without being declared again. A declared action that names a transition
lists it once, under the action's key, and adds its `when` to the transition's own condition. Actions the person has no
right to are left out. Actions whose `when` fails are listed with `available: false` and the reason, so a host can show
a disabled button with a tooltip.

A run checks, in this order:

| Refusal | Status |
|---|---|
| unknown action | 404 |
| no right (`authorization` entry for the key, per object) | 403 |
| `when` does not hold | 409, code `action-precondition-unmet`, with `whenMessage` |
| invalid inputs | 422, per field |
| body refused (lifecycle guard, flow refusal) | 422, with the engine's message |

A sync run answers 200 with the outcome and the object. An async run answers 202 with a link to the flow run at
`/api/flow-runs/{uuid}`. A flow that suspends (a user task, a wait) answers 202 `suspended`, never a false
`completed`. A selection of more than 100 objects becomes a bulk job (`openregister:run-action`) with preview,
progress and retry.

Inputs reach the run as `context.inputs`, and nodes read them as `{{inputs.x}}`. For a `transition`, they are passed
as the transition's data. Inputs marked `writeOnly: true` are passed to the run but never stored in the run's context,
log or audit digest.

The older `GET /api/objects/{id}/available-actions` still answers in its own shape, with a `Deprecation` header naming
the new list route.

## Nodes for actions

Inline `steps` may use any registered flow node, including nodes an app contributes through `RegisterFlowNodesEvent`.
Two nodes exist for actions in particular:

- **`openregister.transition`**: config `{action, data?}`. Runs a lifecycle move on the subject as the run's
  identity; a refused move fails the step with the engine's message.
- **`openregister.link-object`**: config `{schema, register, data, relation}` creates an object and links it to the
  subject; config `{handoff: "<key>"}` runs a declared `x-openregister-handoff`. When the handoff's target app is
  absent the step fails with `not-available`.

## Scoped elevation

Writing into another app's register, for example creating a publication, can need a right the person does not have.
An action may list exactly which writes may be retried as the system:

```json
"elevate": [ { "register": "publication", "schema": "publication", "verbs": ["create", "update"] } ]
```

After the right and `when` have passed, the body runs as the person. A write to a listed register, schema and verb
that the person's own rights refuse is retried once as the system. Any other write is never elevated. Each elevated
write is audited with `elevatedBy` (the action), `triggeredBy` (the person) and `runAs: system`.

## Audit

Every run writes one audit entry on the object with `action: "action.run"` and the declared action, its kind, the run
uuid, a digest of the inputs, the changes the steps made and the outcome. A `transition` run has no flow run; its
changes are the transition's own audit entry. Entries written as `action.macro` before this change are read by the
history route as well. The declared-actions event still fires for every attempt, refused or permitted.

## Related Features

- [Workflow Automation](workflow-automation.md): lifecycle transitions (`x-openregister-lifecycle`)
- [Workflow Operations](workflow-operations.md): flow runs and their operation
- [Registers & Schemas](registers-and-schemas.md): where actions are declared
- [Versioning & Audit](versioning-and-audit.md): the audit trail the runs write to
