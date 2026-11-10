---
kind: code
depends_on: [flow-user-task-node]
---

# Proposal: flow-task-forms

## Summary

Give a human task a structured form without building a form engine. A
user-task node declares WHICH fields of the subject object the performer
supplies; completing the task validates that payload through the lifecycle
transition `inputs` contract that already exists in
`lib/Service/Lifecycle/TransitionEngine.php`, and renders it through the
nc-vue form family that already exists. No new renderer, no new validator,
no form-definition entity.

The whole change is a binding: **a task form is `CnFormDialog` scoped to the
node's declared fields, submitting to a verb that runs the `inputs`
allowlist.** What this proposal buys is that the binding is specified rather
than reinvented per app — and that the four places where the two existing
seams do NOT meet are closed.

## Why

**There is a form contract in the codebase and nobody uses it.**
`resolveTransitionInputs()` (`lib/Service/Lifecycle/TransitionEngine.php:697-729`)
lets a schema declare, per transition,
`inputs: [{"field": "<propertyName>", "required": true|false}]`. Its own
docblock (`:680-690`) states the two properties that make it the right
contract for a task form:

- a transition with NO `inputs` **rejects ANY payload**, so opting in is
  explicit and nothing that exists today changes behaviour;
- the accepted values are **merged into the carrying object write**
  (`:347-352`), so ordinary save-path schema validation and readOnly
  enforcement apply to them exactly like any other object write. There is
  no second validator to keep in sync.

Adoption is **zero**. Scanning the 22 fleet checkouts under `apps-extra/`
that ship a `lib/Settings` for `"inputs"` in `*.json` returns five hits
total — four in shillinq (`bookkeeping-bado-controleprotocol.json:960`,
`bookkeeping-ifrs-rj-dual-gaap.json:609,1072,1105`) and one in procest
(`register.d/95-dmn-decision-tables.json:35`) — and every one of them is a
different `inputs`: an `x-openregister-calculations` input map, a DMN
decision table, or a seed object's own property. Across the 162 files that
declare `x-openregister-lifecycle`, the transition `inputs` key is declared
**zero times**. `openspec/specs/object-lifecycle/spec.md` does not contain the
word either: the contract is implemented, is docblocked
`@spec openspec/specs/object-lifecycle/spec.md`, and has never been
written down as a requirement.

**And it is not discoverable, which is why adoption is zero.**
`TransitionEngine::availableActions()` returns
`{action, to, requires, description}` per action (`:477-482`);
`buildGraphAction()` returns the same plus `label` (`:665-671`). Neither
publishes `inputs`. `GET /api/objects/{id}/available-actions`
(`appinfo/routes.php:370`) therefore tells a client every transition it may
take and nothing about what any of them wants. The one shipped consumer
proves the consequence: `CnLifecycleActions.vue:251` POSTs
`{ action: tr.action }` and no `data` at all. A client that guessed would
be refused — a transition with no declared `inputs` rejects any payload —
so the only safe client behaviour is the one that ships, and the contract
stays unused.

**Meanwhile the renderer exists and is field-scopable.** `CnFormDialog`
takes `schema`, `item`, and an `includeFields` whitelist
(`nextcloud-vue/src/components/CnFormDialog/CnFormDialog.vue:687`, passed
as `include` to `fieldsFromSchema` at `:839`), and emits `confirm` with the
collected payload without persisting it (`:739`) — so the host chooses
where the payload goes. `CnFormPage` renders the same field descriptors
full-page, `CnTabbedFormDialog` groups them. That is a task form already,
missing only its wiring.

**The wiring is where the money is, because the two seams do not actually
meet.** Four gaps, each measured in `nextcloud-vue/src/utils/schema.js`:

1. **The whitelist is an intersection, not an override.** `include` is
   applied at `:496`, AFTER `visible: false` is dropped at `:482` and
   after `readOnly: true` is dropped at `:492`. Naming a field in
   `includeFields` does not make it render. A required input that is
   readOnly or invisible on its schema renders **nothing**, and the
   performer is stuck on a field they cannot see, cannot fill and cannot
   skip.
2. **`required` comes from the wrong place.** `:542` sets
   `required: requiredKeys.includes(key)` — from `schema.required`, not
   from `inputs[].required`. A field the transition requires but the schema
   does not renders as optional; the performer submits, and
   `collectMissingRequiredInputs()` (`TransitionEngine.php:746-759`)
   returns a 400 for a field the form told them was optional.
3. **Declaration order is discarded.** Fields sort by
   `overrides[key].order`, then `prop.order`, then alphabetically
   (`:514-519`). The order the author wrote the `inputs` in is not the
   order the performer sees.
4. **The obvious authoring surface authors the wrong thing.**
   `CnFormBuilder` has no `schema` prop (`CnFormBuilder.vue:166-219`): it
   builds free-form fields from a type palette, with a free-typed `key`. A
   key that is not a property of the subject schema produces a payload the
   allowlist rejects — at completion time, in front of the performer, who
   cannot fix it.

**And a form has to survive the flow being edited.** A task created against
version N of a flow must present version N's form. `flow-definition-versioning`
already pins a version onto the run at queue time and resolves the graph
by `(flow, version)`; the form declaration lives in the node config, so it
is pinned for free — but only if the form is resolved through the pinned
version and never off the live `openregister_flows` row.

## What Changes

- **A `form` block on the `openregister.user-task` node**, in exactly two
  kinds, mirroring Camunda 8's `formId` vs `externalReference` split:
  - `kind: fields` — the native path. Either names a lifecycle `action` on
    the subject schema and inherits that transition's declared `inputs`
    verbatim, or carries its own `[{field, required}]` list in the SAME
    shape. One shape, one validator, either way.
  - `kind: external` — bring-your-own. Names a Nextcloud **Forms** form
    bound through `FormLink` (`lib/Db/FormLink.php:63-141`,
    `lib/Service/FormLinkService.php`) for citizen-facing or complex forms
    OpenRegister does not model.
- **`available-actions` publishes `inputs`.** Each action in
  `TransitionEngine::availableActions()` gains the transition's declared
  `inputs` list (empty when none is declared, which is also the honest
  statement "this transition accepts no payload"). This is the discovery
  step the contract has been missing, and it serves every client, not only
  task forms.
- **The binding is specified, not left to each caller.** Rendering a task
  form means `CnFormDialog` with `:schema` = the subject object's schema,
  `:item` = the subject object, `:includeFields` = the declared field list,
  and `:fieldOverrides` carrying, per declared field, `required` from the
  contract and `order` from the declaration position — closing gaps 2 and 3
  above. `@confirm` posts the collected values as the completion payload.
- **A declared field that cannot be rendered is refused at SAVE time.**
  A field that is not a property of the subject schema, or is `readOnly`,
  or is `visible: false`, is rejected when the node's configuration is
  validated — with the schema, the field and the reason named. Gap 1 is a
  configuration error, and it must land on the author, not on the performer.
- **Completion runs the existing allowlist and nothing else.** Where the
  node names a transition, completion goes through
  `TransitionEngine::transition()` so the fields and the lifecycle flip
  land in ONE save (`:344-356`). Where it does not, the accepted fields are
  merged into an ordinary object write. Both paths reject an undeclared key
  and a missing required input the same way, because both call the same
  method.
- **Validation failure is shown, and the task does not complete.**
  `InvalidTransitionInputException` carries `getFields()`
  (`lib/Exception/InvalidTransitionInputException.php:44`) and
  `TransitionController` already returns `{error, fields}` as a 400
  (`lib/Controller/TransitionController.php:100-107`). The completing
  dialog stays open with each named field flagged; the task stays in its
  pre-call state in the assignee's inbox; the run does not advance; no
  completion is written to the task audit.
- **The form is resolved from the run's PINNED version.** A task carrying a
  `run_uuid` resolves its form through the flow version that run is pinned
  to. A standalone task (no run — first-class per `flow-task-entity`)
  carries its form declaration on the task record. Neither ever reads the
  editable head.
- **The checklist becomes part of the completion surface.**
  `flow-task-entity` already turns procest's JSON-in-a-string checklist
  (`procest/lib/Settings/procest_register.json:1510-1515`, `"type": "string"`)
  into a typed `{id, label, description, checked}` array. The task form
  renders it as an addressable section BESIDE the field form, never merged
  into it: a checklist item is task state, a declared field is subject-object
  state, and one of them goes through the `inputs` allowlist.
- **procest's `config.requiredFields[]` gets a target.**
  `workflowTemplate.steps[].config.requiredFields`
  (`procest/lib/Settings/procest_register.json:3126`, validated at
  `procest/lib/Service/StepConfigValidator.php:266-306`) maps 1:1 onto
  `inputs: [{field, required: true}]`. Measured caveat carried forward: its
  own description says "case-field property paths", but the validator
  accepts only a TOP-LEVEL property name
  (`array_key_exists($field, $caseTypeProperties)`, `:299`) — a dotted path
  fails today, so the target contract inherits flat property names and a
  nested path is a later decision, not an assumed capability.

## What does NOT change

- **No new form renderer, and no form-definition entity.** Every field is
  rendered by the nc-vue form family that ships today. There is no
  `openregister_forms` table, no form versioning of its own, no form
  designer. A form is a field list plus a schema.
- **`flow-task-entity`** — the task record, the ten lifecycle verbs, the
  fail-closed authorization, the typed checklist array, the append-only
  audit, the inbox. This change adds no task field and no verb; it says
  what a completion payload must satisfy before a verb accepts it.
- **`flow-user-task-node`** — the node itself, `FlowSuspension` and resume
  on task terminality, per-item outcome placement, rejection-as-branch, the
  `advance` budget, cancellation propagation. This change adds ONE config
  block to a node that change ships.
- **`flow-task-inbox-projections`** — `INotificationManager` notifications
  and the CalDAV VTODO projection with its authorizing write-back listener.
  A form renders nothing into a calendar and notifies nobody.
- **`flow-business-timers`** — SLA arithmetic, business days, escalation
  matrices, `expires_at` enforcement. A form has no clock.
- **Schema versioning.** `x-openregister-lifecycle` lives on a schema, and
  schemas are not versioned by `flow-definition-versioning` or by anything
  else in this chain. This change does not introduce it; it states what a
  task does when the schema has drifted under a pinned form, which is a
  visible refusal rather than a silent omission.
- **Existing lifecycle behaviour.** No shipped schema gains an `inputs`
  declaration here. Every transition in the fleet keeps rejecting any
  payload, exactly as it does today, until an app opts in.

## Capabilities

### New Capabilities
- `flow-task-forms`: the task form contract — how a user-task node declares
  the fields a performer supplies, how that declaration is validated at save
  time, how it is rendered and bound in the existing nc-vue form family, how
  a completion payload is validated through the lifecycle `inputs`
  allowlist, what a validation failure shows and leaves unchanged, how the
  form is resolved from a pinned flow version, and the external
  Nextcloud-Forms path for citizen-facing forms.

### Modified Capabilities
- `object-lifecycle`: the transition `inputs` contract gains its first
  written requirement, and `available-actions` gains the discovery half —
  a client MUST be able to learn which fields a transition accepts and
  which are required, without reading the schema.

## Impact

- **Affected specs**: new `flow-task-forms`; `object-lifecycle` gains
  requirements for the `inputs` contract and its discovery. `flow-engine`
  untouched — no node semantics, no run semantics change here.
- **Affected code**: `lib/Service/Lifecycle/TransitionEngine.php`
  (`availableActions()` at `:403-486` and `buildGraphAction()` at
  `:640-671` publish `inputs`; `resolveTransitionInputs()` at `:697-729` is
  reused unchanged and becomes callable for a task-completion payload);
  a new `lib/Service/Task/TaskFormResolver.php` (pinned-version → node →
  field list, intersected with the live schema); the `openregister.user-task`
  node's `configForm()` and `validateConfig()` gain the `form` block;
  `lib/Service/FormLinkService.php` is consumed unchanged for the external
  path. No migration of its own.
- **Affected APIs**: `GET /api/objects/{id}/available-actions`
  (`appinfo/routes.php:370`) gains an `inputs` array per action — additive,
  no existing key changes. `POST /api/objects/{id}/transition`
  (`appinfo/routes.php:369`) is unchanged; it already accepts `data`
  (`TransitionController.php:86-93`). Task completion happens on
  `flow-task-entity`'s task verbs.
- **Affected UI (nc-vue)**: the task-completion surface binds `CnFormDialog`
  with `includeFields` + `fieldOverrides` as specified above;
  `CnLifecycleActions.vue` gains the ability to send `data` for a transition
  that declares `inputs`, which today it cannot (`:251`). `CnFormBuilder` is
  NOT used to pick subject-object fields — it authors free-typed keys
  (`CnFormBuilder.vue:166-219`) and a free-typed key is a 400 the performer
  cannot fix; the node's server-driven config form
  (`IFlowNodeConfigForm` → `GET /api/flow/node-catalog`) offers the subject
  schema's property names instead.
- **Affected apps**: none required. procest's `requiredFields` and
  `checklist`, and any citizen-facing FormLink flow, migrate in their own
  changes.
- **Depends on**: `flow-user-task-node` (the node whose config gains the
  `form` block), and transitively `flow-task-entity` (the record a
  completion payload is written against) and `flow-definition-versioning`
  (without a pinned version, "the form version N declared" is not a
  resolvable phrase).
- **ADRs**: ADR-098 D5 (task forms reuse existing seams; no new renderer),
  D6 (versioning before humans), D2/D3 (the record and the performer types
  a form is completed by); ADR-031 (declarative-vs-imperative — argued in
  design.md, and this change is unusual in landing on the declarative side);
  ADR-065 (one engine); ADR-011 (reuse before implementing — the whole
  point).
