# Tasks: flow-node-config-forms

## Trigger nodes

- [ ] `TriggerObjectNode::configForm()` — `event` as a select fed from
      `EventCatalogService` via `optionsFrom` (never an inline copy of the
      sixteen events); `register` and `schema` as selects fed from their
      stores; all three `required`, with help text explaining WHY there is no
      "any" option (the spec's single-subject rule).
- [ ] `TriggerScheduleNode::configForm()` — `cron` as text, `required`, help
      naming the five-field format and that semantics are the scheduler's
      question.
- [ ] `TriggerManualNode::configForm()` — an EMPTY array, deliberately: the
      editor renders "this node takes no configuration".

## Step and end nodes (15 remaining — AwaitSignalNode already implements)

- [ ] `ObjectReadNode` / `ObjectWriteNode` — register/schema selects,
      filter/data fields; mark templated fields' help with the value-template
      syntax (`FlowValueTemplate`).
- [ ] `SetFieldsNode`, `MapNode`, `FilterNode` — expression/field-list fields
      with help pointing at the expression dialect (`FlowExpression`).
- [ ] `SwitchNode`, `RouterNode` — branch/condition configuration; where the
      shape is a keyed structure the form covers the scalar keys and the help
      text says the branch table is edited in the JSON pane (partial form,
      per the interface's own doc).
- [ ] `MergeNode`, `ExplodeNode`, `IterateNode`, `LoopNode` (`openregister.batch`),
      `FlowStateNode`, `WaitNode` — full forms; each key from `configKeys()`
      either gets a field or is named in a code comment saying why not.
- [ ] `SubFlowNode` — flow select fed from the flow store via `optionsFrom`.
- [ ] `EndNode` — `error` boolean with help ("failing is an outcome, not the
      absence of one").

## Guard rails

- [ ] Registry sweep test: every palette entry whose type id starts with
      `openregister.` carries a non-null form declaration — enumerating the
      registry, not a hand-kept list.
- [ ] Per-node unit test: every declared field's `key` is in that node's
      `configKeys()`; positive control with a deliberately bogus field proving
      the assertion can fail.
- [ ] Editor round-trip (nextcloud-vue, `CnFlowSidebar` component tests):
      form → JSON shows the written value; JSON → form populates the field;
      an un-covered key survives a form edit; malformed JSON is refused at the
      pane naming the parse error. If any of these does not already hold,
      the fix lands in nextcloud-vue as this change's dependency.
- [ ] All labels and help strings through `IL10N` — they are shown to
      operators (AwaitSignalNode is the pattern).

## Out of scope (recorded, not tasked)

- openconnector nodes (`openconnector.source-call` et al., ADR-094) and hermiq
  nodes implement `IFlowNodeConfigForm` in their own repos. File follow-up
  issues there referencing this change as the worked example; no requirement
  in this repo's specs targets those apps.

## Acceptance criteria

- All 19 built-in types implement `IFlowNodeConfigForm`; the registry sweep
  test proves it and fails on the next form-less built-in.
- The JSON pane remains reachable for every node and round-trips losslessly.
- No form field names a key its node does not read.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- `@spec` annotations on the new `configForm()` methods target
  `openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions`.
- Tests run on the container's PHP, not the host.
