---
kind: code
depends_on: [macro-flows-with-next-item, macro-flow-by-name, macro-run-changes]
---

# Proposal: object-actions-declared-and-run

## Summary

Running an action on an object becomes one OpenRegister capability. Examples are publishing a decision, recording an
incompleteness, completing a task, handing a request over to another app and signing an attestation. A schema declares
the action once: its name, its inputs as JSON Schema, who may run it, when it is available, and what it does. OpenRegister
answers which actions the current user can run on an object, runs one with inputs (sync or async), and records the run.
nextcloud-vue renders the actions generically as header buttons, row actions, bulk actions and dialogs built from the
input schema. Apps stop writing a controller per verb.

## Why

Ruben, 21 Oct 2026: "Being able to run an action on an object (be it publishing on cases) seems like general
functionality that should be generally supported instead of custom."

Today the same idea exists five times:

| Where | What it does | What it lacks |
|---|---|---|
| `x-openregister-action` (spec `declared-actions`) | names a verb the `authorization` block may grant, raises an event per attempt | no inputs, no precondition, no body except a macro flow |
| macros (`macro-flows-with-next-item`, `ObjectActionsController`) | a declared action runs a manual flow, sync, with `next` and an `action.macro` audit entry | no inputs reach the run; no way to ask which macros apply to this object; sync only |
| `x-openregister-lifecycle` (`TransitionEngine`) | named transitions with inputs, conditions, guards, `authorization`, and `GET /api/objects/{id}/available-actions` | only moves a status field; a second list beside the macros |
| bulk actions (`lib/BulkAction/*`, `bulk-action-jobs`) | previewed, reversible jobs over a selection | a separate registry of verbs |
| `x-openregister-handoff` | manual handoff to another app's object kind | its own trigger and its own button |

Around them the apps keep writing bespoke endpoints for the same shapes. The 21 Oct inventory (see design D-0) counted:

- **dossiq:** about 40 action routes, among them `/api/cases/{id}/woo/publish`, `/api/case/{caseId}/incompleteness`,
  `/api/case/{caseId}/approvals/{act}`, `/api/case/{caseId}/claim`, `/api/case-tasks/{taskId}/complete`, a status
  transition engine of its own (`StatusTransitionService`), and four separate `publish` routes;
- **pipelinq:** about 60, among them `/api/handoff/request/{id}/convert-to-case`,
  `/api/handoff/contract/{id}/send-to-invoicing`, `/api/tickets/{id}/assign-to-me`, `/api/articles/{id}/publish`, and
  `/api/contracts/{id}/transition`, which re-implements the declared lifecycle with a hand-rolled check;
- **learniq:** about 35, among them `/api/store/publish`, `/api/external-training/{recordId}/credential`, and the
  sign-up and allocate routes.

Each repeats the same five steps: authenticate, check a permission (three different permission systems across the
three apps), validate inputs, write, audit. Each needs its own button in the manifest, and that button carries
`visibleWhen` but no permission hint, so users see buttons that answer 403.

## What changes

1. **One declaration.** `x-openregister-action` entries gain `label`, `icon`, `inputs` (JSON Schema), `when` (a
   JSONLogic precondition), `confirm`, `surfaces`, `mode` (`sync`/`async`), `next`, and exactly one body:
   - `transition`, a lifecycle transition key;
   - `flow`, the existing macro binding;
   - `steps`, an inline list of flow nodes that is lowered to a manual-trigger flow.

   `macro: true` stays accepted as a synonym of `flow`. The `authorization` block grants the action exactly as today.
2. **One list.** `GET /api/objects/{register}/{schema}/{id}/actions` answers the actions the caller may run on this
   object. Each entry carries availability (with a reason when it is unavailable), the input schema and the
   surfaces. Declared lifecycle transitions appear in the same list without being declared twice.
   `GET /api/object-actions/{register}/{schema}` answers the actions for a list or a selection.
3. **One run path.** The existing `POST .../actions/{action}` takes `{ "inputs": { … } }`, validates them against
   the declared schema, checks the right and the precondition per object, and executes the body:
   - `transition` goes to `TransitionEngine::transition()`;
   - `flow` and `steps` go to `FlowService::run()` with the inputs in the run context.

   Sync answers the outcome. Async answers 202 with the run id, and the run is read at `/api/flow-runs/{uuid}`.
   A selection over 100 objects becomes a bulk job of the bulk action `openregister:run-action`.
4. **One audit entry.** Every run writes `action.run`, which names the action, its kind, the run, a digest of the
   inputs and the changes (`MacroRunChanges`). The declared-actions event fires for refusals and successes as
   before. `GET /api/objects/{register}/{schema}/{id}/actions/history` lists the runs of actions on the object.
5. **Two missing built-in nodes:**
   - `openregister.transition`, a lifecycle move inside a flow;
   - `openregister.link-object`, which creates or links a related object, optionally through a declared
     `x-openregister-handoff`.

   Apps keep contributing nodes through `RegisterFlowNodesEvent`. Nothing new is invented for that.
6. **One renderer.** nextcloud-vue gets `useObjectActions` and renders actions in the header, the row menu, the
   mass action bar and a dialog generated from `inputs`. It replaces `declaredMacros`/`macrosFrom` and generalises
   `CnTransitionInputFields`. Recorded here as the consumer half; it is built in nextcloud-vue.
7. **A migration path** for the first five app verbs, named generically:

   | App endpoint | Generic action | Body |
   |---|---|---|
   | dossiq `POST /api/cases/{id}/woo/publish` | `publish` | `steps` |
   | dossiq case acts (`incompleteness`, `approvals/{act}`) | `record-act` | `steps` |
   | dossiq `POST /api/case-tasks/{id}/complete` and its `task.effects` | none: completion stays on `/api/flow-tasks/{uuid}/complete`; the effects become `steps` | `steps` |
   | pipelinq `convert-to-case` and `send-to-invoicing` | `hand-over` | `steps` with `link-object` via `x-openregister-handoff` |
   | learniq `store/publish` and `external-training/{id}/credential` | `publish` and `sign` | `steps` with the app's node |

   Design D-9 has the details. Each migration is its own follow-up change in the app's repo. This change only
   proves the model can carry them.

## Out of scope

- Building any of it. Ruben decides on this spec first.
- Retiring app endpoints. Each app migrates in its own change, and an endpoint stays until its manifest no longer
  calls it.
- A generic "run script" beyond the sidecar code step (`flow-code-step-in-a-sidecar`). See question 3.

## Open questions for Ruben

Each has a recommendation. The design is written to the recommendation, so a different answer changes only the
section it names.

1. **Where do actions live: schema only, or also register-wide?**
   - Recommendation: schema-level `x-openregister-action` stays the home. A register MAY declare
     `x-openregister-action` too, which every schema in it inherits, and a schema may override it. `publish` and
     `export` are the same on every schema in a register, and declaring them twenty times is how they drift.
     Inheritance is resolved at read time; nothing is copied into schemas. (Design D-1)
2. **Inline `steps`, or must every action name a stored flow?**
   - Recommendation: allow inline `steps`, lowered to an implicit manual flow that the run records.
   - A one-step "set `publicatiedatum` to now" should not need a flow record that an administrator must adopt before
     it works. A flow-backed action is still the way to do anything with branches, waits or tasks.
   - The rule: `steps` is a straight line with no branches; anything else is a `flow`. (Design D-3)
3. **May an action run a script?**
   - Recommendation: only through the sidecar code node from `flow-code-step-in-a-sidecar`, as one step among others,
     with its limits and its right (`flow-powerful-steps-need-a-right`). No PHP or JS is evaluated inside Nextcloud,
     ever.
   - Until the sidecar lands, the step is not offered, and an action that names it is refused at schema save.
     (Design D-6)
4. **Do lifecycle transitions show up as actions automatically?**
   - Recommendation: yes. Every transition the caller may take appears in the action list with `kind: transition`
     and its declared inputs, without being declared again.
   - A declared action with `transition: <key>` exists only to add a label, icon, confirm text, surfaces or extra
     `when` to one of them.
   - `available-actions` stays as an alias for one release. (Design D-4)
5. **What happens to the `action.macro` audit entries?**
   - Recommendation: new runs write `action.run` with `kind: flow|steps|transition`. Existing `action.macro` entries
     stay as they are; the hash chain forbids rewriting them. Readers treat both names as one kind.
   - nextcloud-vue's "last run" line reads both. (Design D-8)
6. **Can an action write where the person cannot?** This is the dossiq case: a handler publishes a decision into
   OpenCatalogi's register, where they hold no create right.
   - Recommendation: an action MAY declare `elevate`, a list of `{register, schema, verb}` it may write as the system
     after the person's right on the subject and the precondition have passed. Only a schema importer with admin
     rights can declare it, the schema save refuses a wildcard, and every elevated write carries the person as
     `triggeredBy` (ADR-099).
   - This is the same scoped elevation dossiq's `HandlerActsAsSystem` does today, moved to one audited place instead
     of one per app. Without it, `publish` cannot migrate. (Design D-7)

## Capabilities

### New

- `object-actions`: listing, running, inputs, preconditions, async runs and history of actions on an object.

### Modified

- `declared-actions`: the declaration gains inputs, precondition, surfaces and a body.

## Impact

| Area | Change |
|---|---|
| `lib/Db/Schema.php`, `lib/Db/Register.php` | `x-openregister-action` shape validation (register-level read) |
| `lib/Service/Flow/MacroActionBinding.php`, `MacroActionValidator.php` | generalised to `ObjectActionDefinition` and its validator; macro keys stay accepted |
| `lib/Service/Flow/MacroActionResolver.php` | becomes `ObjectActionRunner`, executing `transition`, `flow`, `steps` |
| `lib/Controller/ObjectActionsController.php` | list, history and inputs; async 202 |
| `lib/Service/Lifecycle/TransitionEngine.php` | serves its transitions into the merged list |
| `lib/Service/Flow/FlowDefinitionBuilder.php` | lowers inline `steps` |
| `lib/Service/Flow/Nodes/` | `TransitionNode`, `LinkObjectNode` |
| `lib/BulkAction/RunActionAction.php` | selections over 100 |
| `appinfo/routes.php` | three GET routes |
| nextcloud-vue | `useObjectActions`, generic action rendering (its own change) |
| dossiq, pipelinq, learniq | follow-up migration changes |

Consumers keep working unchanged: an existing `macro: true` declaration, `available-actions` and the existing invoke
routes all keep their contracts.

## ADRs

- ADR-023: the action is the authorised verb. This change does not add a second permission model.
- ADR-031: an action is schema data, not a service class.
- ADR-041: steps that act in another app dispatch typed events or use that app's node, never a direct call.
- ADR-065 and ADR-094: one flow engine. `steps` lower into it, so there is no second executor.
- ADR-099: a run acts as the person. `elevate` is scoped, declared and attributed.
- ADR-022: this replaces app pass-through endpoints.
