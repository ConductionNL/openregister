# flow-approval-consolidation

## ADDED Requirements

### Requirement: An approval position may declare the form its task presents

An `approvers` entry of `x-openregister-approval-chains` MAY carry `form` in the task form declaration shape (`kind` `fields` with `fields`, or `kind` `external` with `formId`). The compiled position SHALL carry the form, and the template version SHALL change when the form changes. Every task the task sequence creates for that position SHALL carry the form under `metadata.form`, normalised to the task form record shape. A schema save SHALL be refused, naming the chain, the position, the field and the reason, when a position's form declares a field that is not a property of the schema, is read-only or is not visible.

#### Scenario: the approver fills in the approved amount

- **GIVEN** a schema whose approval chain has one position for role `budgethouder` with `form: {kind: "fields", fields: [{name: "goedgekeurdBedrag", required: true}]}`
- **WHEN** an approval is requested on an object of that schema
- **THEN** the created task carries `metadata.form` with kind `fields` and the field `goedgekeurdBedrag` marked required
- **AND** completing the task without `goedgekeurdBedrag` is refused naming that field
- @e2e exclude {backend; task 2.1 adds a unit test through the real TaskSequenceService and TaskService}

#### Scenario: an unrenderable field is refused when the schema is saved

- **GIVEN** a schema whose property `besluitnummer` is read-only
- **WHEN** an administrator saves an approval chain whose position form declares `besluitnummer`
- **THEN** the save is refused naming the chain, the position, `besluitnummer` and that it is read-only
- @e2e exclude {validation; task 1.2 adds the unit test}

#### Scenario: an open task keeps its form

- **GIVEN** an open approval task created with a form of two fields
- **WHEN** the chain's position form is changed to one field
- **THEN** the open task still presents two fields, and the next approval request presents one
- @e2e exclude {backend; task 2.2 adds the unit test}

#### Scenario: a position without a form is unchanged

- **GIVEN** a chain whose positions carry no `form`
- **WHEN** an approval is requested
- **THEN** the created tasks carry no `metadata.form`, as before
- @e2e exclude {regression guard; covered by the existing TaskSequenceService tests}
