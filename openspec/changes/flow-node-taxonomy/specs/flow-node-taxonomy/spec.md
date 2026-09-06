## Purpose

Two independent facts about a step type: what kind of thing it is, and where
an author should find it. The first is semantic and is what a BPMN export
needs; the second is a grouping and is what a palette of 64 entries needs.

## ADDED Requirements

### Requirement: A node declares a semantic kind, drawn from BPMN

A step type SHALL be able to declare a kind, and the kind SHALL be one of the
BPMN element types: `userTask`, `serviceTask`, `scriptTask`,
`businessRuleTask`, `sendTask`, `receiveTask`, `manualTask`, `gateway`,
`event`, `subProcess`.

The vocabulary SHALL NOT be extended with terms of our own. Its value is that
it is the vocabulary an interchange already has to emit; a local synonym
would have to be mapped back, and the mapping would be the thing that rots.

The kind SHALL describe what the step IS, not what it is about. A step that
calls an external system is a `serviceTask` whether it calls a payment
provider or a case register.

#### Scenario: The catalog reports a kind for a node that declares one

- **WHEN** the node catalog is read
- **THEN** the entry for `openregister.user-task` MUST report the kind
  `userTask`
- **AND** the entry for `openregister.switch` MUST report `gateway`

---

### Requirement: A node declares a palette category, independent of its kind

A step type SHALL be able to declare a category, and the category SHALL be
one of `triggers`, `human`, `objects`, `logic`, `messaging`, `ai`,
`integrations`, `other`.

The category SHALL be independent of the kind. The two answer different
questions and a node may pair them freely: "Ask a person" is `userTask` and
`human`; "Send an email" is `sendTask` and `messaging`; "Wait for an answer"
is `receiveTask` and also `human`, because an author looking for the other
half of an approval looks where approvals are.

The palette SHALL present categories in a fixed order and SHALL NOT order
them by registration. Registration order is an accident of which apps are
installed, so the same instance can present the palette differently after an
app is enabled.

#### Scenario: Two nodes of one kind sit in different categories

- **GIVEN** `openregister.send-email` and `openregister.send-notification`,
  both `sendTask`
- **WHEN** the palette is grouped
- **THEN** both MUST appear under `messaging`

#### Scenario: Two nodes of one category have different kinds

- **GIVEN** `openregister.user-task` (`userTask`) and
  `openregister.await-signal` (`receiveTask`)
- **WHEN** the palette is grouped
- **THEN** both MUST appear under `human`
- **AND** each MUST keep its own kind

---

### Requirement: Both are optional, and a node that declares neither still works

A step type that declares no kind SHALL be served as `serviceTask`. A step
type that declares no category SHALL be served as `other`.

A node SHALL NOT be refused registration, hidden from the palette, or
excluded from the catalog for declaring neither.

This is load-bearing rather than lenient. Of the 64 step types on a measured
instance, 43 are contributed by apps in other repositories, on their own
release cycles. A required declaration would mean either breaking those apps
or guessing their semantics for them, and a guessed `serviceTask` written
into a BPMN export is a wrong answer wearing the appearance of a right one.
An honest `other` in the palette is visible and gets fixed.

#### Scenario: A contributed node with no declaration is still offered

- **GIVEN** an app contributing a node that implements neither method
- **WHEN** the catalog is read
- **THEN** the node MUST appear
- **AND** it MUST report `serviceTask` and `other`

---

### Requirement: A new node in this repository must declare both

A gate SHALL refuse a step type defined in this repository that declares
neither a kind nor a category.

The gate SHALL apply only to this repository's own nodes. It SHALL NOT
inspect nodes contributed by other apps, whose defaults are deliberate.

Without the gate the flat list grows back one node at a time, and each
addition is individually too small to argue with.

#### Scenario: The gate refuses an undeclared node here and ignores one elsewhere

- **GIVEN** a new step type added under this repository's node directory with
  no kind and no category
- **WHEN** the gate runs
- **THEN** it MUST fail, naming the node
- **AND** a node in another app's repository MUST NOT be reported
