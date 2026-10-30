# Design: object-actions-declared-and-run

## D-0: what exists, measured 21 Oct 2026

Read on the `development` branches of openregister (0de1184), dossiq, nextcloud-vue (841486a), pipelinq and learniq.

### OpenRegister already has the parts

| Part | Where | What it gives |
|---|---|---|
| Declared action | `configuration['x-openregister-action']`, `Schema::declaredActionNames()`, spec `declared-actions` | a verb the `authorization` block may grant; an event per attempt, refused or permitted |
| Macro | `MacroActionBinding`, `MacroActionValidator`, `MacroActionResolver`, `FlowNextHint`, `ObjectActionsController` | `macro: true` + `flow` runs a manual flow sync as the caller; selection of at most 100; `next`; `action.macro` audit entry with `changes` (`MacroRunChanges`) |
| Lifecycle | `x-openregister-lifecycle`, `TransitionEngine::transition()/availableActions()/declaredInputs()`, `LifecycleGuardRegistry`, `LifecycleActionRegistry` | transitions with `inputs`, `condition` + `message`, `requires` guards, `authorization`, post-actions, `provider` mode (an app answers), `GET /api/objects/{id}/available-actions` |
| Flow engine | `FlowService::run(uuid, subject, context, sync)`, `FlowRun`, `FlowRunStep`, ~30 `openregister.*` nodes, `RegisterFlowNodesEvent` | one engine (ADR-065); a run always exists before it advances; runs are read at `/api/flow-runs/{uuid}` |
| Bulk jobs | `BulkActionRegistry`, `lib/BulkAction/*`, spec `bulk-action-jobs` | previewed, async, reversible jobs over a selection |
| Handoff | `x-openregister-handoff` | manual handoff to another app's object kind, with field mapping and `whenUnavailable` |
| Audit | `AuditTrail` with `causeRun`, `flowRun`, `flowNode`, hash chain | every write in a run is attributed to it (`flow-object-attribution`) |

What is missing:

- no list of declared actions for an object;
- no inputs reach a macro run (the context is `{action}` only);
- no precondition on a declared action;
- no async single invoke;
- no `transition` and no `link-object` node;
- the run kinds audit under different names.

### The apps build the same five steps per verb

| App | Bespoke action routes (approx.) | Permission systems in use | Examples |
|---|---|---|---|
| dossiq | 40+ | `CaseAccessGuard` (handler), `TransitionAuthorizer` + `GuardRegistry`, `LifecycleActorGate` (role per act), OR grants | woo publish/withdraw, finish/abort/archive, hold, suspend/resume/extend, claim/release, hand-over, incompleteness, approvals, task complete, generate document, export dossier |
| pipelinq | 60+ | `ObjectOwnerAccessPolicy`, `BillingHandoffAccessPolicy`, `isAdmin`, schema lifecycle groups | convert-to-case, send-to-invoicing, assign-to-me, article publish, contract transition (re-implements the lifecycle), POS refund, blast send |
| learniq | 35+ | ADR-023 matrix (`ActionAuthService::requireAction`), schema lifecycle groups and 59 guards | store publish, issue credential, sign-up, allocate markers, raise contributions, Europass |

dossiq declares no macros. Its per-case-type "acts" are spread over four other declarations:

- `workflowTemplate.transitions[].automaticActions[]`;
- `steps[].task.effects[]`;
- `caseType.alwaysAvailableActs[]`;
- `x-openregister-flows` built from `dossiq.*` nodes.

Its legacy action types already translate to OpenRegister nodes (`RetiredNodeTranslator`).

nextcloud-vue renders actions through several paths:

- `api-call` buttons with hand-built URLs;
- `CnMacrosWidget`, the only reader of `x-openregister-action`, and only for macros;
- `CnLifecycleActions`, which reads `available-actions`;
- `CnActionDialog`, `run-node` and `CnMassActionBar`.

Visibility is `visibleWhen` only. The manifest `permission` field is not enforced, so a button the user may not press
shows and answers 403.

Classification across the three apps:

- **Generic:** about 85% of the verbs, in eight shapes. The shapes are transition, set field, assign, create or link
  an object (including handoff), notify or send, export or generate a document, publish (a dated visibility write or a
  handoff to a publishing app), and sign or approve (a transition stamped with the actor).
- **Truly app-specific:**
  - dossiq: Awb term arithmetic, Woo redaction rules, DROP/LVBB publication;
  - pipelinq: POS money logic, payment provider calls, the kassakoppeling hash chain;
  - learniq: assessment scoring, admissions decisions, wallet credentials.

  These stay app code, contributed as flow nodes or lifecycle guards and actions. They are not a reason to keep a
  controller per verb.

## D-1: the declaration lives on the schema, a register may supply defaults

`x-openregister-action` stays the key, and an entry keeps its key as its identity: it is the word the
`authorization` block grants. New keys:

```json
"x-openregister-action": {
  "publish": {
    "name": "publish",
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
    "when": { "and": [ { "==": [ { "var": "object.status" }, "decided" ] }, { "!": { "var": "object.publicatiedatum" } } ] },
    "whenMessage": { "nl": "Alleen een vastgesteld besluit kan worden gepubliceerd.", "en": "Only a decided decision can be published." },
    "confirm": { "nl": "Het besluit wordt openbaar.", "en": "The decision becomes public." },
    "mode": "sync",
    "next": "stay",
    "steps": [
      { "id": "date", "type": "openregister.set-fields", "config": { "fields": { "publicatiedatum": "{{inputs.from}}" } } },
      { "id": "tell", "type": "openregister.send-notification", "label": "Indiener geïnformeerd", "config": { "to": "@object.applicant", "subject": "…" } }
    ]
  }
}
```

The keys:

- **`label`** is shown to people. It may be a string or a locale map, and defaults to `name`.
- **`surfaces`** says where a host offers the action: `header`, `row`, `bulk`, `detail-menu`. The default is
  `["header", "row"]`. An action that cannot run on a selection (`inputs` with a per-object default such as
  `@object.*`) MUST NOT list `bulk`; the schema save refuses it.
- **`inputs`** is a JSON Schema object, validated with the same validator objects are, so formats, enums and
  `x-openregister-*` pickers work the same in a dialog as in a form. The defaults `@now`, `@user` and `@object.<path>`
  are resolved at run time.
- **`when`** is JSONLogic over `{object, user, inputs}`, using the expression evaluator lifecycle conditions already
  use. `whenMessage` is the reason given when it does not hold.
- **Body.** Exactly one of these three, which is the action's `kind`:
  - `transition` names a key in this schema's `x-openregister-lifecycle`;
  - `flow` is the macro binding, by uuid or by declared name. `macro: true` remains accepted and means `flow`;
  - `steps` is an inline list (D-3).

  An action with no body stays what it is today: a verb that authorization may grant and an app may check. It is
  never listed as runnable.
- **`elevate`** is optional (D-7).
- **`reversible`** is optional, as in `bulk-action-jobs`.

**Register-level defaults (open question 1).** A register's `configuration['x-openregister-action']` holds entries
that every schema in the register inherits. A schema entry with the same key replaces the inherited one entirely,
with no deep merge, so what runs is always one readable entry. Inheritance is resolved when the schema is read
(`ObjectActionDefinitions::forSchema()`), never copied, so changing the register changes every schema at once.
`authorization` stays per schema: an inherited action is runnable on a schema only when that schema's
authorization grants it, or the schema has no authorization block. This is today's default-open rule, unchanged.

## D-2: the right is the action's authorization entry, checked per object

There is no new permission model (ADR-023):

1. `PermissionHandler::hasPermission(schema, action: <key>, userId, objectOwner, _rbac: true, object)` is checked
   per object. Conditional `match` rules on the `authorization` block carry dossiq's "you are the handler"
   (`assignee == @user`) without code.
2. Then `when` is checked.
3. For `kind: transition`, `TransitionEngine` also runs its own guards, `condition` and transition `authorization`,
   exactly as a direct transition does. An action never weakens a transition.

**App matrices.** learniq gates verbs with an app matrix (`GenericActionAuthService`). An authorization rule MAY
name `action:<app>.<key>`, resolved through the store plane's existing `StoreActionAuthorizer` path:

- an unresolvable matrix refuses;
- an administrator does not bypass it.

This lets learniq keep its admin-editable matrix while the verb moves into OpenRegister.

Refusal order and status codes:

| Refusal | Status | Error code |
|---|---|---|
| unknown action | 404 | |
| no right | 403 | |
| `when` false | 409 | `action-precondition-unmet`, with `whenMessage` |
| invalid inputs | 422 | per field, ADR-050 envelope |
| body refused (guard, flow refusal) | 422 | the engine's message |

The list endpoint uses the same checks, so a button is never offered that the run would refuse for a reason known
in advance.

## D-3: inline steps lower to a flow; there is no second executor

`steps` is a straight line of nodes: `{id, type, config, label?}`. `FlowDefinitionBuilder` lowers it to:

- a `trigger-manual` node;
- the steps in order;
- an `end` node, carrying `next` from the declaration.

The lowered definition is hashed (`FlowDefinition`) and stored once per hash with `app` set to the declaring schema
and a system owner marker. The run then records `flowUuid` and `flowVersion` like any run, and the run detail page
shows it.

The lowering is refused at schema save in three cases:

- a node type the registry does not know;
- a node whose `validateConfig()` refuses;
- a branching node (`switch`, `route`, `wait`, `await-signal`, `user-task`, `portal-task`).

The message for a branching node says to declare a flow and bind it with `flow`. The line between `steps` and
`flow` is that `steps` never suspends, so a sync answer is always a finished answer.

**Why not always a stored flow (open question 2).**

- A stored flow is imported inert and must be adopted by an administrator before it runs (`macro-flow-by-name`).
  That is right for a process and wrong for "set `publicatiedatum`".
- Most of the inventoried verbs are one to three steps with no branch.
- Lowering keeps one engine, one run record and one audit trail while sparing the adopt step.
- `flow-powerful-steps-need-a-right` still applies at schema save: a person without the right for `send-email`
  cannot save a schema whose action uses it.

## D-4: lifecycle transitions are actions without being declared again

`GET /api/objects/{r}/{s}/{id}/actions` merges two sources:

1. every runnable declared action (D-1, D-2);
2. every entry `TransitionEngine::availableActions()` returns (static, `provider` and `graph` modes), as
   `kind: transition`, with `inputs` from `declaredInputs()` translated into JSON Schema.

When a declared action names a transition (`transition: "fulfil"`), the transition is listed once, under the
declared action's key, label and surfaces. Its `when` is added to the transition's own `condition`, never replacing
it.

This is what lets dossiq's provider-mode case lifecycle (`CaseActionProvider`) appear in the generic list on day one,
with its guards intact.

`GET /api/objects/{id}/available-actions` stays and answers the same transitions in its current shape, for one
release, with a `Deprecation` header naming the new route.

## D-5: one execution API

| Verb | Route | Answer |
|---|---|---|
| GET | `/api/objects/{register}/{schema}/{id}/actions` | `{actions: [{key, kind, label, description, icon, surfaces, available, reason?, inputs, confirm?, mode, next}]}`. Actions without the right are left out. Actions whose `when` fails are listed with `available: false` and the reason, so a host can show a disabled button with a tooltip. |
| GET | `/api/object-actions/{register}/{schema}` | actions offered on the `bulk` surface, right checked at schema level (owner-independent rules only); per-object checks happen at run time |
| POST | `/api/objects/{register}/{schema}/{id}/actions/{action}` | body `{inputs}`. Sync: `200 {run?, outcome, action, kind, next, object}`. Async: `202 {run, status: "queued", action, links: {run: "/api/flow-runs/{uuid}"}}` |
| POST | `/api/object-actions/{register}/{schema}/{action}` | body `{ids, inputs}`. Up to 100: sync per-object summary, as today. Above 100: `202 {job}`, a bulk job of `openregister:run-action` with preview, progress and retry from `bulk-action-jobs` |
| GET | `/api/objects/{register}/{schema}/{id}/actions/history` | the object's `action.run` and `action.macro` audit entries, newest first, each with its run link |

Further rules:

- `mode: async` is honoured for `flow` and `steps`. A `transition` is always sync, because it is one write.
- A flow-bound action whose flow suspends (a user task, a wait) answers 202 even when declared sync. The answer says
  `suspended`, never a false `completed`.
- Inputs travel in the run context as `context.inputs`, and nodes read them as `{{inputs.x}}`, with the same
  interpolation `{{field}}` already uses.
- For `kind: transition`, inputs are passed as the transition's `data`.
- Sensitive inputs (`writeOnly: true` in the input schema) are passed to the run but never written to the run's
  stored context or the audit digest.

## D-6: nodes, and how apps contribute them

Actions use the flow node registry. Nothing parallel is introduced. Apps contribute nodes through
`RegisterFlowNodesEvent` as today, and those nodes are usable in `steps` once registered, under the taxonomy from
`flow-node-taxonomy`. Two built-in nodes are added because the inventory needs them everywhere:

- **`openregister.transition`**: config `{action, data?}`. It runs `TransitionEngine::transition()` on the subject
  (or on `object` from config) as the run's identity. Guards, inputs and audit behave as for a direct move. A
  refused move fails the step with the engine's message.
- **`openregister.link-object`**: config `{schema, register, data, relation}` or `{handoff: "<key>"}`. It creates an
  object and writes the relation on the subject, or runs a declared `x-openregister-handoff` from this schema. When
  the handoff's implementer is absent, the step fails with `not-available`. It never silently succeeds, unlike the
  `whenUnavailable: hide` display rule, which only governs listing.

Calls to external endpoints are integriq's contributed node (ADR-091). OpenRegister does not add an HTTP node.

**Scripts (open question 3).** The only script step is `openregister.code` from `flow-code-step-in-a-sidecar`: it
runs in the runner container, with limits and declared egress. While the runner is absent the node is not
registered, so a schema naming it is refused at save with a message that names the missing runner. No `eval`, no
inline PHP, no expression language beyond JSONLogic and `{{ }}` interpolation.

## D-7: scoped elevation instead of per-app "acts as system"

dossiq's `HandlerActsAsSystem` exists because publishing writes a publication in OpenCatalogi's register, where the
handler has no create right. Every app that hands work to another register will meet the same wall.

An action MAY declare:

```json
"elevate": [ { "register": "publication", "schema": "publication", "verbs": ["create", "update"] } ]
```

After the right (D-2) and `when` have passed, the steps run as the person. Writes that hit a listed
`(register, schema, verb)` and that the person's own rights refuse are retried once as the system. The rules:

- Each elevated write is audited with `elevatedBy: <action>`, `triggeredBy: <person>` and `runAs: system`
  (ADR-099 keeps attribution and authorization apart).
- Any other write is never elevated.
- Declaring `elevate` requires schema-import rights held by an administrator.
- The schema save refuses `*` in any position, and refuses `elevate` on an action with no `authorization` entry,
  since an elevated action open to everyone is a privilege escalation by configuration.

## D-8: audit and run history

Every run writes one audit entry on the subject with `action: "action.run"` and this context:

```json
{ "declaredAction": "publish", "kind": "steps", "run": "<uuid>|null", "inputsDigest": "sha256:…", "changes": [ { "step": "Indiener geïnformeerd", "kind": "message" } ], "outcome": "completed" }
```

- `changes` comes from `MacroRunChanges::fromLog()`, unchanged.
- `kind: transition` has no run. Its changes are the transition's own audit entry, linked by `request`.
- The declared-actions event keeps firing for every attempt, refused or permitted, so refusals are observable
  without an audit row.
- Existing `action.macro` entries are not rewritten: the hash chain forbids it. History and the nextcloud-vue "last
  run" line read both names (open question 5).

## D-9: migration path, named generically

Each migration is a follow-up change in the app's repo. It adds the declaration, moves the manifest button to
`type: object-action`, keeps the old route as a thin deprecated alias for one release, then removes it. Domain logic
the generic nodes cannot express becomes an app node or a lifecycle guard, never a controller.

| From | To | Notes |
|---|---|---|
| dossiq `POST /api/cases/{id}/woo/publish` | action `publish` on `case`. `inputs: {decisionId}`. `when`: `wooPublicationStatus == "ready"` and case not closed. `steps`: `dossiq.woo-publication` (app node: redacted documents only, through `OpenCatalogiApiClient`), then `openregister.set-fields` (`wooPublicationStatus`). `elevate` on the OpenCatalogi publication schema | replaces `HandlerActsAsSystem` for this act; `authorization.publish` with `match: assignee == @user` replaces `CaseAccessGuard` |
| dossiq `woo/withdraw` | action `withdraw-publication`, same shape | |
| dossiq case acts: `incompleteness`, `hold`, `release-hold`, `approvals/{act}`, `caseType.alwaysAvailableActs[]` | one declared action per act (`record-incompleteness`, `hold`, …), `steps` of `set-fields` plus the case journal as an app node `dossiq.journal`; role per act (`LifecycleActorGate`) moves into `authorization` | `alwaysAvailableActs[]` becomes the list of declared action keys a case type offers, read like `macrosFrom` |
| dossiq `finish`, `abort`, `archive` | `kind: transition` actions over the provider lifecycle, with the existing guards | Awb and retention logic stays in guards and lifecycle actions |
| dossiq `POST /api/case-tasks/{id}/complete` | completion stays on OpenRegister `/api/flow-tasks/{uuid}/complete`. `task.effects[]` becomes the `steps` of an action the task declares as its completion effect | `TaskEffects` and `ActionHandlerRegistry` retire in favour of nodes |
| dossiq `claim`/`release` | actions `claim`/`release`: `steps: set-fields assignee = @user / null`, `when: assignee empty` | |
| pipelinq `convert-to-case`, `send-to-invoicing`, ticket `woo-request` | action `hand-over` (one per target): `steps: openregister.link-object {handoff: …}` then `openregister.transition` | the handoff mapping stays in `x-openregister-handoff` |
| pipelinq `assign-to-me` | action `assign-to-me`: `steps: set-fields assignee = @user` | |
| pipelinq `contracts/{id}/transition` | removed: the lifecycle already declares the moves, which the list serves as `kind: transition` | also fixes the hand-rolled check drifting from the lifecycle |
| pipelinq `articles/{id}/publish` | action `publish`: `kind: transition` to `published`, plus a `set-fields` stamp | |
| learniq `store/publish` | action `publish` on course: `steps: learniq.store-package` (app node) | authorization rule `action:learniq.store.publish` keeps the matrix |
| learniq `external-training/{id}/credential` | action `sign` (issue credential): `steps: learniq.issue-credential` (app node) then `set-fields credentialId` | |
| learniq attestation, learning plan, BSA warning signatures | already lifecycle transitions; listed as actions with no change | |

## D-10: nextcloud-vue renders the list (its own change)

- A composable `useObjectActions({register, schema, id})` reads the list.
- A manifest action `type: "object-action"` with `action: "<key>"` replaces hand-built `api-call` URLs for these
  verbs.
- `actionsFrom: "openregister"` on a detail header, row menu or bulk bar renders every listed action whose
  `surfaces` includes that place. A manifest may narrow it with `include: [keys]`, the generalisation of
  `macrosFrom`.
- An action with `inputs` opens a dialog built from the JSON Schema by the existing schema form. This absorbs
  `CnTransitionInputDialog`.
- `available: false` renders disabled with `reason` as the tooltip. Actions the user may not run are absent, which
  closes the visible-then-403 gap.
- `next` is honoured as `macro-flows-with-next-item` specified. A 202 shows a "running" state linked to the run.
- `CnMacrosWidget` becomes a view over the same list filtered to `kind: flow|steps`.

## D-11: kind and size

Code, in OpenRegister, size L. It splits into four builds along `tasks.md` sections, each mergeable alone:

1. declaration and validation;
2. list and run;
3. nodes;
4. audit and history.

The nextcloud-vue and app halves are separate changes.
