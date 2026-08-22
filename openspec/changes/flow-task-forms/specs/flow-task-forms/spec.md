## Purpose

The contract for the structured form a human task presents: which fields of
the subject object a performer supplies, how that declaration is refused
when it cannot be rendered, how the payload is validated on completion, what
a failure shows and leaves unchanged, and how the form stays the one the
flow version declared after the flow is edited.

## ADDED Requirements

### Requirement: A task form is a declaration of existing fields, not a new form definition

A user-task step SHALL be able to declare a form. A declared form SHALL take
exactly one of two kinds and SHALL NOT introduce a form definition of its
own.

**Native.** The form names fields of the SUBJECT object's schema, in the
same shape the lifecycle transition input contract uses:
`[{field, required}]`. A form MAY obtain that list by naming a lifecycle
action on the subject schema, in which case the transition's declared inputs
are the field list verbatim and SHALL NOT be restated. A form MAY instead
carry its own list in that same shape.

**External.** The form names a Nextcloud Forms form bound to the subject
object, for citizen-facing or complex forms the register does not model.

No third kind SHALL exist. The system SHALL NOT introduce a form-definition
record, a form version lineage, or a field type vocabulary of its own: a
form is a field list plus the schema those fields already belong to.

A user-task step with NO declared form SHALL keep working exactly as it does
without this capability — the performer supplies an outcome and a comment
and nothing else. Declaring a form SHALL be opt-in per step.

#### Scenario: A step inherits a transition's declared inputs

- **GIVEN** a subject schema whose `reject` transition declares two inputs,
  one required
- **WHEN** a user-task step declares a native form naming that action
- **THEN** the step's field list MUST be exactly those two fields with that
  required flag
- **AND** the step configuration MUST NOT have restated either field
- @e2e exclude covered by task-form resolution unit tests

#### Scenario: A step with no form still completes

- **GIVEN** a user-task step declaring no form
- **WHEN** its task is completed with an outcome and a comment
- **THEN** the completion MUST be accepted
- **AND** no field validation MUST have been applied
- @e2e a task with no form completes with an outcome alone

### Requirement: A field that cannot be rendered is refused when the step is saved

The system SHALL validate every declared field against the subject schema at
step-configuration save time, and SHALL REFUSE the configuration naming the
schema, the field and the reason when a field:

- is not a property of the subject schema; or
- is marked read-only on that schema; or
- is marked not visible on that schema.

This is normative because of where the failure otherwise lands. The whitelist
that scopes the rendered form is applied AFTER the renderer has already
dropped read-only and invisible properties, so such a field renders nothing
at all. A field declared `required` that renders nothing leaves the performer
holding a form they cannot complete, cannot skip and cannot diagnose — and
the person who can fix it is the author, who is not in the room.

An authoring surface for the field list SHALL offer the subject schema's
property names and SHALL NOT accept a free-typed field name. A field name
that is not a schema property produces a payload the completion allowlist
rejects, which surfaces to the performer as a refusal they cannot act on.

Where the subject schema changes after a step was saved so that a declared
field becomes absent, read-only or invisible, the step SHALL be reported as
broken wherever steps are listed, and the field SHALL render as a disabled
row explaining why. It SHALL NOT be silently omitted from the form: a
silently-omitted required field turns into a completion that is refused for
a reason nobody can see.

#### Scenario: A misspelled field is refused at save time

- **GIVEN** a user-task step whose native form declares a field the subject
  schema does not have
- **WHEN** the step configuration is validated
- **THEN** validation MUST fail naming the schema and the field
- **AND** the step MUST NOT be saved
- @e2e exclude covered by user-task config-validation unit tests

#### Scenario: A read-only field is refused rather than rendered blank

- **GIVEN** a user-task step declaring a field its subject schema marks
  read-only
- **WHEN** the step configuration is validated
- **THEN** validation MUST fail with a reason naming read-only
- @e2e exclude covered by user-task config-validation unit tests

#### Scenario: A field the schema dropped later is visible as broken

- **GIVEN** a saved step whose declared field was removed from the subject
  schema afterwards
- **WHEN** a performer opens the task's form
- **THEN** the field MUST appear as a disabled row stating that the schema no
  longer offers it
- **AND** the form MUST NOT present as complete and correct
- @e2e a task form whose schema drifted shows the missing field as broken

### Requirement: The rendered form carries the declaration's required flags and order

The rendered form SHALL present exactly the declared fields, and SHALL carry
two properties from the DECLARATION rather than from the schema:

- **Required.** A field the declaration marks required SHALL render as
  required, whether or not the subject schema lists it as required. The
  renderer derives required-ness from the schema's own required list, so a
  transition-required field that is schema-optional would otherwise render as
  optional and the performer would be refused on submit for a field the form
  told them they could leave blank.
- **Order.** Fields SHALL render in the order they were declared. The
  renderer sorts by an explicit order, then the property's own order, then
  alphabetically, so an unprojected declaration order is discarded.

The system SHALL NOT reimplement any field widget, layout or validation
already provided by the shared component library. Rendering a task form
SHALL mean scoping the existing schema-driven form component to the declared
field list; the component collects values and hands them back without
persisting them, and this capability decides where they go.

The rendered field list SHALL be DERIVED on each render from the declaration
and the live schema. It SHALL NOT be cached on the task record.

#### Scenario: A transition-required field renders as required

- **GIVEN** a declared field marked required whose subject schema does not
  list it among the schema's required properties
- **WHEN** the form renders
- **THEN** that field MUST be presented as required
- @e2e a task form marks a transition-required field as required

#### Scenario: Declared order survives to the screen

- **GIVEN** a form declaring three fields in an order that is neither the
  schema's own order nor alphabetical
- **WHEN** the form renders
- **THEN** the three fields MUST appear in the declared order
- @e2e exclude covered by task-form binding unit tests

### Requirement: A completion payload is validated by the lifecycle input allowlist and by nothing else

Completing a task that has a native form SHALL validate the submitted values
through the SAME lifecycle transition input allowlist an object write uses.
The system SHALL NOT introduce a second validator.

That allowlist SHALL be applied unchanged, including both of its existing
properties:

- A key the form did not declare SHALL be REJECTED. A step declaring no
  fields SHALL accept no field values at all.
- Accepted values SHALL be merged into the carrying object write, so ordinary
  save-path schema validation and read-only enforcement apply to them exactly
  as to any other object write. No value SHALL reach storage having been
  checked only by the form layer.

Where the form names a lifecycle action, the accepted values and the
lifecycle field change SHALL be written in ONE save, so a task cannot be
completed with values that were accepted while the state change was refused,
or the reverse.

Where the form names no action, the accepted values SHALL be written to the
subject object through the ordinary object write path, under the same
allowlist.

Neither path SHALL bypass the object write's own authorization. A performer
authorized to complete a task is not thereby authorized to write the subject
object; both checks SHALL apply and either SHALL be able to refuse.

#### Scenario: An undeclared key is rejected

- **GIVEN** a task whose form declares one field
- **WHEN** a completion is submitted carrying that field and one more
- **THEN** the completion MUST be refused naming the undeclared field
- **AND** the subject object MUST be unchanged
- @e2e exclude covered by completion-payload validation unit tests

#### Scenario: A value that violates the schema is refused by the schema

- **GIVEN** a declared field whose submitted value violates its property
  definition
- **WHEN** the completion is submitted
- **THEN** the write MUST be refused by the ordinary save-path validation
- **AND** the task MUST NOT be completed
- @e2e exclude covered by completion-payload validation unit tests

#### Scenario: Values and the state change land together

- **GIVEN** a form naming a lifecycle action, and a submitted value the
  schema will refuse
- **WHEN** the completion is submitted
- **THEN** the subject object's lifecycle field MUST be unchanged
- **AND** no partial write MUST be observable
- @e2e exclude covered by transition-write unit tests

### Requirement: A validation failure names its fields and completes nothing

When a completion payload fails validation, the response SHALL carry the
offending field names in a machine-readable form alongside the human
message, distinguishing an undeclared key from a missing required input.

The completing surface SHALL stay open with the submitted values intact and
SHALL flag each named field inline. It SHALL NOT discard what the performer
typed, and SHALL NOT present the failure as a generic error when the failing
fields are known.

The task SHALL NOT complete. Specifically, after a failed completion:

- the task SHALL remain in the state it was in before the call, with the same
  assignee;
- the task SHALL still appear as actionable in that assignee's inbox;
- the run, where the task has one, SHALL remain suspended and SHALL NOT
  advance by any amount;
- the task audit SHALL NOT record a completion. It MAY record a refused
  attempt; a refused attempt and a completion SHALL be distinguishable.

#### Scenario: A missing required field is named and the task stays open

- **GIVEN** a task whose form declares a required field
- **WHEN** a completion is submitted without it
- **THEN** the response MUST name that field
- **AND** the task MUST still be actionable in its assignee's inbox
- **AND** its run MUST still be suspended
- @e2e a task form missing a required field refuses and stays in the inbox

#### Scenario: The performer does not lose their typing

- **GIVEN** a form filled with several values, one of which fails validation
- **WHEN** the failure is shown
- **THEN** the other values MUST still be present in the form
- @e2e exclude covered by task-form component tests

#### Scenario: A refused completion is not an audited completion

- **GIVEN** a task whose completion was refused for a missing required field
- **WHEN** its audit trail is read
- **THEN** it MUST NOT contain a completion entry
- @e2e exclude covered by task audit unit tests

### Requirement: The form a task presents is the one its flow version declared

A task carrying a run reference SHALL resolve its form through the flow
DEFINITION VERSION that run is pinned to, and SHALL NOT read the editable
head of the flow. Editing a flow SHALL NOT change the form of any task
already created against an earlier version, and SHALL NOT change it while
the task is open.

A task carrying no run reference SHALL carry its own form declaration on the
task record. A run-less task is first-class, so "resolve it from the pinned
version" MUST NOT be the only path to a form.

Where the pinned version cannot be resolved, the task's form SHALL fail
visibly naming the flow and the version. It SHALL NOT fall back to the head,
to the latest published version, or to an empty form. An empty form is the
worst of the three: it silently turns a task that required evidence into one
that required nothing, and reports success.

The subject SCHEMA is not versioned by this capability. Where a pinned
declaration and the live schema disagree, the live schema SHALL govern
whether a value may be written, and the disagreement SHALL be reported on
the form as specified above — never resolved by silently dropping a field.

#### Scenario: Editing the flow leaves an open task's form alone

- **GIVEN** a task created from a user-task step whose form declares two
  fields
- **WHEN** the flow is edited to declare four fields on that step and a new
  version is published
- **THEN** the open task's form MUST still present two fields
- @e2e editing a flow does not change an already-open task's form

#### Scenario: A new task gets the new form

- **GIVEN** the same flow after the edit above
- **WHEN** a new run reaches that step
- **THEN** its task's form MUST present four fields
- @e2e exclude covered by task-form resolution unit tests

#### Scenario: An unresolvable version fails loudly

- **GIVEN** a task whose run is pinned to a flow version that cannot be
  resolved
- **WHEN** a performer opens its form
- **THEN** the failure MUST name the flow and the version
- **AND** no empty form MUST be presented as completable
- @e2e exclude covered by task-form resolution unit tests

### Requirement: A checklist is presented beside the field form, never merged into it

Where a task carries a checklist, the completion surface SHALL present it as
its own addressable section, separate from the declared field form.

A checklist item's checked state SHALL be written as task state, through the
task's own verbs, and SHALL NOT be submitted as a field value. A declared
field's value SHALL be written to the subject object through the input
allowlist. The two SHALL NOT share a payload: one is answered about the work,
the other is answered about the subject, and merging them would put checklist
state through an allowlist that has no property to validate it against.

A step MAY require that every checklist item is checked before the task may
be completed. Where it does, an unchecked item SHALL refuse the completion
naming the item, under the same rules as a missing required field — the task
does not complete and the run does not advance.

#### Scenario: Checking an item does not write the subject object

- **GIVEN** a task with a checklist and a declared field form
- **WHEN** a performer checks one checklist item
- **THEN** only that item's state MUST change
- **AND** the subject object MUST be unchanged
- @e2e exclude covered by checklist and completion unit tests

#### Scenario: An unchecked mandatory item refuses the completion

- **GIVEN** a step requiring every checklist item to be checked, and a task
  with one item unchecked
- **WHEN** a completion is submitted
- **THEN** it MUST be refused naming the unchecked item
- **AND** the task MUST remain actionable
- @e2e a task with an unchecked mandatory checklist item refuses completion

### Requirement: The external form path binds an existing Forms form and validates nothing about its contents

A step declaring an external form SHALL name a Nextcloud Forms form bound to
the subject object through the existing object-to-form link, which is
anchored by object uuid, register and schema — the same anchor a task already
uses for its subject. No new binding table SHALL be introduced.

The system SHALL be explicit about what it does NOT do on this path: the
external form's fields are not the subject schema's properties, so the input
allowlist does not apply and no value from the submission is written to the
subject object by this capability. Completion on this path records the
submission as the evidence that the work was done. Any mapping from a
submission back onto object properties is a separate, declared act and is not
implied by binding a form.

A step declaring an external form SHALL be REFUSED at configuration save time
on an instance where the Forms app is not installed. The link service already
fails with an unavailable-service error when its classes are absent; letting
that surface at completion time would put the failure in front of the
performer — and on a citizen-facing form, in front of a member of the public
who has no way to act on it.

Where the bound form has been deleted, archived or has expired, the task
SHALL say so and SHALL NOT present an unusable link as the way to complete
the work.

#### Scenario: An external step is refused without the Forms app

- **GIVEN** an instance without the Nextcloud Forms app
- **WHEN** a user-task step declaring an external form is saved
- **THEN** the configuration MUST be refused naming the missing app
- @e2e exclude covered by user-task config-validation unit tests

#### Scenario: An external submission writes no object fields

- **GIVEN** a task bound to an external form, completed through a submission
- **WHEN** the subject object is read
- **THEN** no property MUST have been written by this capability
- **AND** the completion MUST record the submission
- @e2e exclude covered by external-form completion unit tests

#### Scenario: An expired bound form is not offered as the way to finish

- **GIVEN** a task whose bound form has expired
- **WHEN** a performer opens the task
- **THEN** the task MUST state that the form is unavailable
- @e2e exclude covered by form-link state unit tests
