# Tasks: flow-task-forms

## 1. The contract gets its discovery half

- [ ] 1.1 `TransitionEngine::availableActions()` (`lib/Service/Lifecycle/TransitionEngine.php:457-484`)
      publishes each action's declared `inputs` — normalised through
      `normaliseDeclaredInputs()` (`:774-790`) so the response shape is the
      contract's shape — and `buildGraphAction()` (`:640-671`) does the same
      for graph mode. A transition with no declaration publishes an EMPTY
      list, never an absent key. The read-permission gate at `:414-433` is
      untouched.
- [ ] 1.2 Make `resolveTransitionInputs()` (`:697-729`) reachable from a
      second caller without duplicating it: one narrow internal seam,
      unchanged signature and unchanged throws. Both call sites MUST refuse
      the same payloads — assert it with a shared test fixture, not by
      reading the code.
- [ ] 1.3 Write the `inputs` contract into `openspec/specs/object-lifecycle/`
      via this change's delta. The contract has shipped since the transition
      engine's input work and has never been a requirement; the delta is the
      first time the allowlist and its 400 shape are specified.

## 2. Declaring a form on a user-task step

- [ ] 2.1 The `openregister.user-task` node's `configForm()` gains the `form`
      block — `kind: fields | external`, an optional lifecycle `action`, an
      inline `[{field, required}]` list, and the external form reference —
      served unchanged through `FlowNodeRegistry::palette()`
      (`FlowNodeRegistry.php:243-250`) and `GET /api/flow/node-catalog`, so
      the builder needs no editor change (design.md, D-9). The field picker
      offers the subject schema's property names and accepts no free-typed
      field name; `CnFormBuilder` is NOT used — it has no `schema` prop and a
      hand-typed `key`
      (`nextcloud-vue/src/components/CnFormBuilder/CnFormBuilder.vue:166-219`).
- [ ] 2.2 `validateConfig()` refuses, naming schema + field + reason: a field
      that is not a property of the subject schema; a field the schema marks
      `readOnly`; a field the schema marks `visible: false`; both an `action`
      and an inline list; and an `action` the subject schema does not declare.
- [ ] 2.3 `validateConfig()` refuses `kind: external` when the Forms app is
      not installed, mirroring `FormLinkService::createAndLinkForm()`'s 503
      (`lib/Service/FormLinkService.php:385-398`) at authoring time rather
      than in front of the performer.

## 3. Resolving the form

- [ ] 3.1 `lib/Service/Task/TaskFormResolver.php` — a task with a `run_uuid`
      resolves its declaration through the run's PINNED flow version
      (`flow-definition-versioning`), never the editable head; a run-less task
      reads the declaration off its own record. No third path, no fallback to
      head/latest/empty, and an unresolvable version fails naming flow AND
      version.
- [ ] 3.2 The resolver intersects the declaration with the LIVE subject
      schema on every call and returns per field: render / broken-with-reason.
      Nothing is cached on the task row (design.md, D-1 — derived, never
      stored).
- [ ] 3.3 Expose the resolved form on the task read so the completion surface
      needs no second round-trip, carrying each field's `required` from the
      DECLARATION and its position from the declaration order.

## 4. Completing with a payload

- [ ] 4.1 Completion with a form that names an action calls
      `TransitionEngine::transition($objectId, $action, $data)` so the
      allowlist, the merge and the lifecycle flip land in one save
      (`:344-356`); completion with an inline list runs the same allowlist and
      writes through `ObjectService::saveObject()`.
- [ ] 4.2 Ordering and failure: the object write commits BEFORE the task is
      completed. A refused write leaves the task actionable and the run
      suspended; a task-completion failure after a successful write leaves the
      write standing and the resubmit idempotent (design.md, D-5).
- [ ] 4.3 Both authorizations apply and either may refuse: the task verb's
      (`flow-task-entity`) and the object write's. Being the assignee grants
      no write on the subject.
- [ ] 4.4 Checklist completion stays on the task's own verbs and never enters
      the field payload; the optional "every item checked" precondition
      refuses a completion naming the unchecked item, without advancing the
      run.

## 5. Rendering and the binding

- [ ] 5.1 One task-completion component owns the binding: `CnFormDialog` with
      `:schema` = the subject schema, `:item` = the subject object,
      `:includeFields` = the declared fields, and `:fieldOverrides` carrying
      `required` from the declaration and `order` from the declaration index —
      the two repairs for `nextcloud-vue/src/utils/schema.js:542` and
      `:514-519`. `@confirm` posts the payload; the component does not
      persist.
- [ ] 5.2 Failure surfaces, both kinds. A BROKEN field (the schema dropped it,
      or made it readOnly/invisible after the step was saved) renders as a
      disabled row stating why, and the step is flagged wherever steps are
      listed — never silently omitted. A REFUSED completion keeps the dialog
      open with the typed values intact and flags each field named in the
      400's `fields` array
      (`lib/Exception/InvalidTransitionInputException.php:44`,
      `lib/Controller/TransitionController.php:100-107`), distinguishing an
      undeclared key from a missing required input.
- [ ] 5.3 `CnLifecycleActions.vue:251` gains the ability to send `data` for a
      transition whose published `inputs` are non-empty, and keeps sending
      `{action}` alone when they are empty.
- [ ] 5.4 External path: the task presents the bound Forms form through
      `FormLink` (`lib/Db/FormLink.php:70-127`), resolved via the subject
      anchor with no new table; an expired, deleted or archived form makes the
      task say so instead of offering a dead link.

## 6. Tests

- [ ] 6.1 Contract tests: an undeclared key, a missing required input, an
      empty-string required input, and a payload against a transition with no
      declaration — each refused, each naming its fields, each leaving the
      lifecycle field unchanged; plus an accepted value that the schema then
      refuses, and one naming a readOnly property. Discovery alongside:
      `available-actions` publishes `inputs` for static and graph modes, an
      empty list is present rather than absent, and a caller without read
      permission gets nothing.
- [ ] 6.2 Binding tests: a declaration whose `required` disagrees with
      `schema.required` in BOTH directions renders correctly; declared order
      survives a schema whose own `order` disagrees; a readOnly or invisible
      declared field is refused at save and never reaches a render.
- [ ] 6.3 Versioning and regression: an open task keeps its form across a
      publish that changes the step, while a new run gets the new form; an
      unresolvable pinned version fails loudly; and a pass with opencatalogi
      and softwarecatalog installed proving their lifecycle transitions still
      list and still apply unchanged.

## Acceptance criteria

- No shipped schema gains an `inputs` declaration in this change. A grep for
  `"inputs"` under every app's `lib/Settings` returns the same five unrelated
  hits it returned before (four in shillinq, one in procest), and every
  transition in the fleet still rejects every payload.
- There is exactly one implementation of the input allowlist. A grep shows one
  method that rejects undeclared keys and one that collects missing required
  inputs, with no second copy under a task, form or flow namespace.
- Every `available-actions` response carries an `inputs` key on every action,
  including actions whose transitions declare none — where it is present and
  empty.
- No task row stores a rendered field list, a field descriptor, or a copy of a
  declaration resolvable from a pinned version.
- A form declaration that cannot be rendered is refused at configuration save
  time. A performer never sees a validation failure caused by a field name the
  author got wrong.
- A failed completion leaves the task in its pre-call state, actionable in the
  same inbox, with its run suspended and no completion entry in its audit.
- Editing and publishing a flow changes the form of zero already-open tasks.
- This change contributes no new form renderer and no component containing a
  field widget.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- New PHP files carry `@license EUPL-1.2` and `@copyright 2026 Conduction B.V.`
- `@spec` annotations point at
  `openspec/specs/flow-task-forms/spec.md` and, for the engine changes,
  `openspec/specs/object-lifecycle/spec.md` anchors.
- Every user-visible string is wrapped per the app's l10n rules; new keys land
  in `l10n/en.js` for frontend strings and in the backend catalogue for PHP
  strings, never the other way round.
- References ADR-098 Decision 5 (reuse the existing seams; no new renderer),
  Decision 6 (the pinned version supplies the form), ADR-031 (argued in
  design.md — this change lands on the declarative side), ADR-011 (reuse
  before implementing).
- No form-definition table, version lineage or field-type vocabulary is
  introduced, and no partial hook for one is left behind.
