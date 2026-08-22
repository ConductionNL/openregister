# Design: flow-task-forms

## Context

See proposal.md — Why for the motivation. What shapes the approach is that
both halves already exist and were built for each other without ever being
introduced.

**Server — the contract, complete and unused.**
`TransitionEngine::resolveTransitionInputs()`
(`lib/Service/Lifecycle/TransitionEngine.php:697-729`) is the allowlist:
`array_diff(array_keys($data), array_keys($declared))` rejects undeclared
keys (`:701-712`), `collectMissingRequiredInputs()` (`:746-759`) rejects
absent or empty-string required inputs, and `normaliseDeclaredInputs()`
(`:774-790`) reads the `[{field, required}]` shape. It is called from exactly
two places: `transition()` at `:347-352`, where the accepted values are
`array_merge`d into `$objectData` BEFORE the lifecycle field is flipped
(`:356`) so both land in one save; and `:824`, which calls it with
`inputs: []` to assert that a payload-free path really carries no payload.

**Server — the discovery half, missing.**
`availableActions()` builds each entry at `:477-482` as
`{action, to, requires, description}`; `buildGraphAction()` at `:665-671`
adds `label`. Neither reads `$spec['inputs']`, which is sitting in the same
`$spec` array the loop is already holding (`:457`). The endpoint is
`GET /api/objects/{id}/available-actions` (`appinfo/routes.php:370` →
`TransitionController::availableActions()` at `:148-164`).

**Server — the error shape, already right.**
`InvalidTransitionInputException` carries `private readonly array $fields`
(`lib/Exception/InvalidTransitionInputException.php:44`) and its own class
docblock (`:29-36`) fixes the status mapping: 400 for a malformed payload,
as opposed to 422 for a refused transition and 403 for unauthorized.
`TransitionController` implements exactly that, returning
`['error' => ..., 'fields' => $e->getFields()]` (`:100-107`).

**Client — the renderer, and the four places the seams miss.**
`CnFormDialog` props: `schema`, `item`, `register`, `initialData`,
`lockedFields`, `fields`, `excludeFields`, `includeFields`, `fieldOverrides`
(`nextcloud-vue/src/components/CnFormDialog/CnFormDialog.vue:616-705`); it
emits `['close', 'confirm']` (`:739`) and does not persist. `includeFields`
reaches `fieldsFromSchema` as `include` (`:839`).
In `nextcloud-vue/src/utils/schema.js:469-585` the filter order is:
`visible === false` dropped (`:482`), `overrides[key].hidden` dropped
(`:487`), `readOnly === true` dropped unless `overrides[key].readOnly ===
false` (`:492`), `exclude` (`:494`), and only THEN `include` (`:496`).
`required` is `requiredKeys.includes(key)` from `schema.required` (`:542`,
`:477`). Sort is `overrides[key].order` → `prop.order` → `localeCompare`
(`:514-519`).
So `fieldOverrides` is the one prop that can repair all of it: it carries
`readOnly: false` to un-drop, `order` to re-sequence, and merged field props
to override the label — and `required` must be merged the same way.

**Client — the authoring surface is not the one it looks like.**
`CnFormBuilder` has no `schema` prop (`CnFormBuilder.vue:166-219`): `value`
is a free field array, `availableTypes` a type palette, and `key` is typed
by hand (`keyLabel`, `:201`). It builds forms out of nothing, which is the
opposite of what an allowlisted form needs.

**The external path already has its table.** `FormLink`
(`lib/Db/FormLink.php:63-141`) carries `objectUuid`, `registerId`,
`schemaId` (`:70-84`) — the SAME generic anchor `flow-task-entity`
specifies for a task — plus `formId`, `formHash`, `submissionId`, `status`
and `expiresAt` (`:91-127`). `FormLinkService::createAndLinkForm()`
(`lib/Service/FormLinkService.php:371`) throws 503 when the Forms app is
absent (`:385-398`), and the service's own docblock (`:11-15`) says the
cached metadata exists so surfaces still render when Forms is uninstalled or
the form was deleted.

**The fleet shapes this has to absorb.** procest's
`workflowTemplate.steps[].config.requiredFields` is `string[]` of case-field
names (`procest/lib/Settings/procest_register.json:3126`), validated by
`StepConfigValidator::validateRequiredFields()`
(`procest/lib/Service/StepConfigValidator.php:266-306`) with three error
codes — `malformed_required_fields`, `malformed_required_field`,
`unknown_field_reference`. The dangling-reference check is
`array_key_exists($field, $caseTypeProperties)` (`:299`), so despite the
schema description calling them "property paths" a dotted path fails today.
procest's `task.checklist` is `"type": "string"` holding JSON
(`procest_register.json:1510-1515`); `flow-task-entity` already replaces it
with a typed array.

## Goals / Non-Goals

**Goals:**

- Make the `inputs` contract usable by giving it its first consumer and its
  missing discovery step, without changing what it does to anything that
  exists today.
- Specify the binding between the contract and the renderer precisely enough
  that the four measured mismatches cannot be reintroduced by the next
  caller.
- Move every failure that an author can fix to the moment the author is
  present — configuration save time — so the performer only ever sees
  failures about their own input.
- Keep the form derivable: declaration × live schema, computed per render,
  cached nowhere.

**Non-Goals:**

- A form-definition model. No table, no version lineage, no field-type
  vocabulary, no designer. See proposal.md — What does NOT change.
- Nested field paths. The contract's `field` is a top-level property name
  today, in OpenRegister and in procest's validator alike; making it a path
  is a change to the contract, not to its first consumer.
- Mapping an external Forms submission back onto object properties. Binding a
  form is not a promise to read it.
- Schema versioning. This design states what a pinned declaration does when
  the schema moves; it does not stop the schema moving.
- Rendering. Every widget, layout and per-field validation stays in the
  shared component library. This change contributes zero components with a
  field widget in them.

## Decisions

### D-1 — Declarative-vs-imperative decision (ADR-031)

**This change lands on the DECLARATIVE side, which is unusual for this
chain, and the imperative parts are three narrow ones that each have a
structural reason.**

ADR-031's test is: when an `x-openregister-*` schema extension expresses the
requirement, declare it rather than write a service. Applied here, the answer
is unusually clean, because the extension already exists and already does the
work.

**Declarative — the form itself.** A task form IS
`x-openregister-lifecycle.transitions.<action>.inputs` on a schema in the
register. The validator is `resolveTransitionInputs()`
(`TransitionEngine.php:697-729`) and this change adds none of its own. The
crucial property is the one the docblock states at `:680-690`: accepted
values are merged into the carrying object write, so schema validation and
readOnly enforcement apply on the ordinary save path. A bespoke form
validator would have had to re-derive "is this value legal", would have
drifted from the schema, and would have been the second place a readOnly
field could be written. There is nothing to justify here: this is ADR-031's
default path working as designed, for the first time.

**Imperative — three parts, each fenced.**

1. **The node's `form` block is flow-graph config.** `lib/Db/Flow.php:5-11`
   states the position: a flow definition is deliberately NOT an
   OpenRegister object, because definitions used to live in a register and
   that meant every app owning flows needed its own register, resolver and
   executor. There is no schema to hang an annotation on and no object for
   `TransitionEngine` to transition. `flow-definition-versioning`'s design
   makes the same argument for the same reason; it is not re-made here.
2. **Publishing `inputs` on `available-actions` is a read-shape change** in
   `availableActions()` — a controller response, not a rule.
3. **The binding is client code.** Projecting `required` and `order` onto
   `fieldOverrides` happens in the component that mounts `CnFormDialog`.

**The fence.** The form layer decides WHICH declared fields to render and
WHERE to send the payload. It decides nothing else:

- whether a value is legal → the schema, on the save path;
- which fields a step wants → the declaration, in the register or in the
  pinned node config;
- who may answer → `TaskAuthorizationService` (`flow-task-entity`);
- what happens after the answer → an edge condition on the graph, where the
  author can see it.

A branch in the form layer about what a specific app's field MEANS is out of
bounds. The measurable version of that fence: the form layer contains no
call that writes an object property directly. Every write goes through
`TransitionEngine::transition()` or `ObjectService::saveObject()`.

**Derived, never stored.** The rendered field list is computed per render
from (pinned node config) × (live schema). It is not cached on the task row.
The fleet has paid for a cached derived value before — three task schemas
store `overdue` and decidesk's `actionOverdue` notification fired only when
something remembered to write it (fixed in decidiq#846). A cached field list
would be worse: it would go stale against a schema change silently, and the
stale reading is "the form is fine".

**No seed data (ADR-001).** No register and no schema is introduced or
modified by this change. Notably it does NOT add an `inputs` declaration to
any shipped schema: adoption stays zero until an app opts in, in its own
change, which is what makes this change safe to ship on a live instance.

### D-2 — The field list is a declaration, not a form; two kinds, no third

Camunda 8 splits a user task's form into `formId` (a form the engine knows)
and `externalReference` (a form it does not). The same split is the right
one here, for the same reason: one path can be validated end-to-end and the
other cannot, and pretending otherwise is where a form engine gets built by
accident.

- **Native (`kind: fields`)** — the field list. Either `action: "<name>"`,
  which means "the inputs that transition declares, verbatim", or an inline
  `[{field, required}]` list in the identical shape.
- **External (`kind: external`)** — a `FormLink` to a Nextcloud Forms form.

Two shapes for the native path rather than one, deliberately. Naming the
action is the better spelling and should be the documented default: the
declaration lives in the register next to the transition it belongs to, one
place, and a schema change and a form change are the same edit. The inline
list exists because a user-task step is not always a transition — a step can
collect fields without moving the subject's lifecycle at all, and forcing a
synthetic transition to exist just to hold a field list would put fake states
in a schema's lifecycle graph.

Rejected: a third kind that lets a step declare ad-hoc fields not present on
any schema. It is the obvious request, and it is the form engine. An ad-hoc
field has no property definition, so nothing validates its value, nothing
enforces readOnly on it, and it has nowhere to be written — which means
inventing a per-task blob, which means a second place object-ish data lives.
Where a step genuinely needs a field the subject does not have, the answer is
to add the property to the schema, which takes one edit and makes the value
queryable.

### D-3 — Every author-fixable failure moves to configuration save time

The renderer drops a field for three reasons before it ever consults the
whitelist (`schema.js:482`, `:487`, `:492`). Two of those — `visible: false`
and `readOnly: true` — are properties of the SCHEMA, knowable the moment a
step is saved. So they are checked then, and the step is refused.

The alternative is to repair them at render time. `fieldOverrides` can do it:
`overrides[key].readOnly === false` un-drops a readOnly property (`:492`),
which exists precisely for "read-only on edit, collected on create". It is
rejected here, and the reason is that the repair would be silently wrong. A
schema marks a property readOnly to say the save path must refuse a write to
it — and the save path WILL refuse it (`TransitionEngine.php:680-690`: the
merged values go through ordinary readOnly enforcement). Un-dropping it in
the form would render an editable field whose value is guaranteed to be
thrown away or to fail. That is a worse outcome than not rendering it: the
performer fills it in and is told no, or worse, is told yes and the value is
gone.

`visible: false` has no override at all (`:482`), so a declared invisible
field is unrenderable by construction, not by policy.

The same argument sends the free-typed field name to save time. A step whose
declaration names `reasonn` produces a payload the allowlist rejects at
`TransitionEngine.php:704-712` — correct behaviour, wrong audience. The
performer cannot spell it right, cannot edit the step and, on the external
path, may not even be an employee.

What is NOT movable to save time is later schema drift: a property removed
after the step was saved. That surfaces at render, as a disabled row saying
so, and the step is flagged wherever steps are listed. Rejected alternative:
drop the field silently and complete without it. That converts "this
approval required a written reason" into "this approval required nothing",
and reports success — the exact silent-feature-loss shape this fleet keeps
paying for.

### D-4 — `fieldOverrides` carries `required` and `order`; nothing else is repaired

Two of the four mismatches ARE render-time repairs, and both are done through
the one prop built for it.

**`required`.** `schema.js:542` reads `schema.required`, which is the
schema's opinion about the object, not the transition's opinion about this
step. They are different questions and both are legitimate: a `reason`
property can be optional on the object and mandatory when rejecting. So the
binding merges `required: <from the declaration>` into
`fieldOverrides[field]`. Rejected alternative: make the schema require it.
That would make every write of that object require it, including creates that
have nothing to do with the transition — the tail wagging the dog.

**`order`.** `schema.js:514-519` sorts by `overrides[key].order` first, so
projecting the declaration's array index as `order` makes declared order the
rendered order, without touching the schema's own `order` values that every
other surface (data widget, detail grid) depends on. Rejected alternative:
pass `fields` instead of `includeFields`, hand-building the descriptors in
declaration order. It works and it is a fork: the hand-built list would not
carry `resolveWidget`, reference resolution, semantic-type discovery
(`CnFormDialog.vue:970-1000`), enum handling or the conditional-visibility
pipeline, and it would rot the first time `fieldsFromSchema` gained a
feature.

Nothing else is repaired. `hidden` overrides, `lockedFields` and
`initialData` stay available to the surface but are not part of this
contract.

### D-5 — Completion is one of the two existing write paths, never a third

Where the form names an action, completion calls
`TransitionEngine::transition($objectId, $action, $data)`. That single call
gives four properties for free, all measurable in `:327-362`: the
from-state check (`:334-342`), the allowlist (`:347-352`), the merge before
the flip so "the status write always wins and both land in the same save"
(`:344-346`, `:356`), and the identity snapshot forwarded to the save path so
authorization uses the identity that authorized the transition (`:358-362`).

Where the form names no action, the accepted fields go through
`ObjectService::saveObject()` after the same allowlist call. This is the only
new call site of `resolveTransitionInputs()`, which means the method moves
from `private` to a narrowly-typed internal service seam. That is the whole
server-side surface of this change.

**Ordering, and why it is this way round.** The object write commits FIRST,
the task completion second. If the write fails, the task must not be
completed — a completed task whose evidence was refused is a lie in an
inbox, and the run would advance on it. If the task completion fails after
the write succeeded, the write stands and the task stays actionable; the
performer resubmits, and the second submit writes the same values to the
same object, which is idempotent for a value write. The reverse ordering has
no such recovery: it would advance the run on evidence that was never stored.

**Two authorizations, both apply.** `flow-task-entity` authorizes the verb;
the object write authorizes the write. Being the assignee does not grant
write on the subject. Either may refuse, and a refusal of the second is a
403 that leaves the task open — visible, and correct.

### D-6 — The pinned flow version is the form's version; there is no second snapshot

`flow-definition-versioning` pins a version onto the run at queue time and
resolves the graph by `(flow, version)` with a memo keyed on both. The node's
`form` block is part of `nodes`, which is part of the version snapshot row.
So the form of an open task is already frozen — provided resolution goes
through the pinned version.

Rejected alternative: copy the resolved field list onto the task at creation,
mirroring `flow-task-entity`'s `template_snapshot`. It is redundant against
the version pin and it is not free: the copy would be a derived value stored
on a row (D-1's fence), and it would have to be kept honest against the
schema anyway, because the schema is what the write is validated against. One
source, resolved per render.

A run-less task is the exception and it needs its own carrier, because there
is no run and therefore no pin. `flow-task-entity` is explicit that a
standalone task is first-class and that no code path may treat "no run" as
degraded — so the task record carries the declaration for that case. The
resolver takes both paths and has no third.

Failure is loud. An unresolvable pinned version fails the form naming flow
and version, matching `flow-definition-versioning`'s Decision 3, and there is
no fallback to head, to latest-published, or to empty. Empty is the dangerous
one and worth naming: an empty form is completable, so the fallback that
looks most harmless is the one that silently completes a task that required
evidence.

### D-7 — The checklist is a second surface, not more fields

A checklist item and a declared field are answered on the same screen and
must not share a payload. A field's value is subject-object state, written
through the allowlist to a property that validates it. A checklist item's
`checked` is TASK state, written through the task's own verbs and audited
there (`flow-task-entity`: "the change MUST appear in the task audit").

Merging them would put checklist state through an allowlist with no property
to validate it against — which means either the allowlist rejects it (it
would; the key is not a declared field) or the allowlist is loosened for it,
which is the first hole in the thing this whole change is built on.

Presenting them together is a layout decision and belongs to the completion
surface; `CnTabbedFormDialog` (`tabs`, `item` — `CnTabbedFormDialog.vue:152-212`)
is the natural shape when both are present and long. Nothing here mandates it.

"All items must be checked to complete" is a step-level option, refusing the
same way a missing required field refuses: named, not completed, run not
advanced. It is not a field, so it is not in the allowlist; it is a
precondition on the verb.

### D-8 — The external path binds and records; it does not read

A `FormLink` is anchored by `objectUuid` + `registerId` + `schemaId`
(`FormLink.php:70-84`), which is exactly the generic anchor
`flow-task-entity` gives a task. So a task's external form resolves through
its subject with no new table and no new column — the strongest argument for
this path over inventing a task-to-form binding.

What the design refuses to do is map submission answers onto object
properties. NC Forms questions are not schema properties: they have their own
ids, their own types, and no relationship to the register. A mapping layer
would be a second field-declaration dialect with none of the allowlist's
guarantees, sitting next to the first. So: the submission id
(`FormLink.php:106`) is recorded as the evidence, and the subject object is
untouched by this capability. An app that wants the answers written back
declares that separately.

The install check moves to save time for the same reason as D-3.
`createAndLinkForm()` throws 503 when the Forms classes are absent
(`FormLinkService.php:385-398`), and on a citizen-facing form the person who
would see that 503 is a member of the public.

The service already caches title, status, `form_hash` and `expires_at` at
link time precisely so a surface degrades gracefully
(`FormLinkService.php:11-15`). This design uses that: an expired or deleted
form makes the task say so, rather than offering a dead link as the way to
finish.

### D-9 — The authoring surface is the node's server-driven config form

`CnFormBuilder` is the component that looks like the answer and is not
(`CnFormBuilder.vue:166-219`: no `schema` prop, hand-typed `key`,
free-form type palette). Feeding it schema-derived `availableTypes` would
still leave the `key` field free-typed, and a free-typed key is D-3's
performer-facing 400.

The node already has the right mechanism. `openregister.user-task`
implements `IFlowNodeConfigForm`, and `FlowNodeRegistry::palette()` publishes
`configForm` for any node declaring one (`FlowNodeRegistry.php:243-250`),
served at `GET /api/flow/node-catalog` (`FlowController.php:213`,
`appinfo/routes.php:508`). So the `form` block's fields are described
server-side, where the subject schema's property list is known, and the
builder renders a constrained multi-select with no editor change — the same
property `flow-user-task-node` relies on for the rest of its config.

`CnFormBuilder` keeps its existing job. It is not deprecated by this and it
is not used by this.

## Risks / Trade-offs

- **Publishing `inputs` on `available-actions` widens a response every client
  reads.** → Additive: no existing key changes, and a transition with no
  declaration publishes an empty list. The read-permission gate at
  `TransitionEngine.php:414-433` is unchanged, so the new data is exactly as
  protected as the action names already were.
- **`resolveTransitionInputs()` gains a second caller and stops being
  private.** A shared validator is a shared blast radius. → The signature is
  pure (`inputs`, `data`, `action` → accepted values, or throw), it has no
  state, and the second caller passes the same shapes as the first. The
  mitigation is a test asserting both call sites reject the same payloads
  identically, rather than a promise that they will.
- **The four binding repairs are client-side, and a future caller can skip
  them.** A surface that mounts `CnFormDialog` with `includeFields` and no
  `fieldOverrides` silently gets optional-instead-of-required and
  alphabetical order. → Mitigated by putting the binding in ONE component
  that owns task completion rather than documenting a recipe, and by a test
  that renders a form whose declaration disagrees with `schema.required` in
  both directions.
- **Schema drift under a pinned form has no automatic repair.** → By design
  (D-3): it is visible on the form, flagged in step listings, and refuses
  rather than silently narrowing. A repair would mean either editing pinned
  versions, which `flow-definition-versioning` forbids outright, or versioning
  schemas, which is not in this chain.
- **The native path only reaches properties of ONE schema — the subject's.**
  A step that needs a value about something else (a related object, a
  free-standing note) has no native home. → Accepted for this change: a
  related object is reachable as a reference property, and a genuinely
  task-local answer is the outcome and comment `flow-task-entity` already
  models. If a real case survives both, it is a schema property.
- **Top-level property names only.** procest's `requiredFields` says "property
  paths" but validates a flat name (`StepConfigValidator.php:299`), and
  OpenRegister's `normaliseDeclaredInputs()` reads a flat `field`
  (`TransitionEngine.php:774-790`). A migrating step that relied on the
  description rather than the behaviour will find its dotted path refused. →
  Refused at save time with the path named, which is strictly better than
  today, where such an entry fails procest's own validator too.
- **Two writes, two failure modes (D-5).** The object write can succeed while
  the task completion fails. → Recoverable by construction: the task stays
  actionable and the resubmit is idempotent for a value write. The reverse
  ordering is not recoverable, which is why it is not used.

## Migration Plan

Nothing to migrate, and deliberately nothing to backfill.

1. Deploy order is the dependency chain: `flow-definition-versioning` →
   `flow-task-entity` → `flow-user-task-node` → this change.
2. No schema, table or column is added. No shipped schema gains an `inputs`
   declaration, so every transition in the fleet keeps rejecting every payload
   exactly as it does today. The 162 files declaring
   `x-openregister-lifecycle` are untouched.
3. The `available-actions` response gains a key. Clients that ignore unknown
   keys — including `CnLifecycleActions.vue`, which reads
   `action`/`to`/`requires`/`description` — are unaffected.
4. Rollback is removing the code. A user-task step that had declared a `form`
   block then falls back to outcome-and-comment completion: the block is inert
   config in the version snapshot, not a schema change, and it comes back
   intact on redeploy.
5. Verification after deploy: `available-actions` carries an `inputs` key for
   every action on a schema with no declarations, and it is empty, not
   missing; a step declaring a readOnly or absent field is refused at save; a
   completion carrying an undeclared key is refused with that key named; and a
   published flow edit does not change an already-open task's form.

## Open Questions

- **Should the inline field list be allowed at all, or should every native
  form name a transition?** Provisionally: allowed, because a step that
  collects without moving state is real and the alternative is fake lifecycle
  states (D-2). Removing it later is a config-validation change and
  invalidates nothing specified here.
- **Where does a declaration live for a step whose subject is chosen at run
  time rather than at authoring time?** Provisionally: out of scope — a
  user-task step names its subject schema in config, and a step that cannot
  is a step whose form cannot be validated at save time, which contradicts
  D-3. To be revisited only if `flow-parallel-streams` produces a shape that
  needs it.
- **Should a refused completion attempt be written to the task audit?** The
  spec permits it and requires only that it be distinguishable from a
  completion. Provisionally: yes for a validation refusal on a task with a
  form, because "the performer tried three times" is the signal that a form is
  wrong. Deferrable — it adds an audit entry type and changes no other
  behaviour.
