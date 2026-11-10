## Purpose

What a flow's version says about what changed: derived from the graph at
publish, breaking when something a consumer could depend on was taken away,
and never silently lowered.

## ADDED Requirements

### Requirement: A semantic version is derived at publish, from the graph

The system SHALL derive a semantic version when a flow is published, by
comparing the graph being published with the graph of the currently published
version.

The derivation SHALL happen at PUBLISH and not when a draft is created. The
ordinal is taken at draft creation, before any change exists to classify;
asking then what kind of change this will be has no answer.

The first publish of a flow SHALL be `1.0.0`.

The system SHALL keep the integer ordinal unchanged. The ordinal remains the
unique key with the flow uuid, remains what a run pins, and remains what
orders versions. The semantic version is an additional fact about a published
version, not a replacement for its identity.

#### Scenario: The first publish is 1.0.0

- **GIVEN** a flow that has never been published
- **WHEN** it is published
- **THEN** its semantic version MUST be `1.0.0`
- **AND** its ordinal MUST be unchanged by the derivation

#### Scenario: A run still pins the ordinal

- **GIVEN** a run of a published flow
- **WHEN** the flow is published again
- **THEN** the run MUST still name the ordinal it was pinned to
- **AND** the semantic version MUST NOT be what the run resolves its graph by

---

### Requirement: Taking something away is major; everything else is minor

The system SHALL classify a change as MAJOR when, compared with the published
graph, the graph being published:

- no longer contains a node that was there, or
- no longer contains an edge that was there, or
- no longer contains a configuration key on a node that survived.

Every other difference SHALL be MINOR: nodes added, edges added, labels
changed, configuration values changed, positions moved.

The rule is deliberately about REMOVAL. What a consumer of a flow can depend
on is that a step still exists, that a path still connects, and that a step
still reads the key it read. Adding to any of those cannot break them.
Changing a VALUE can break them, and is not detectable as such — which is
what the author's override below is for.

A publish that changes nothing at all SHALL still produce a version, and it
SHALL be minor. An identical republish is not a breaking change, and refusing
it would make an idempotent operation fail.

#### Scenario: A removed step is major

- **GIVEN** a published flow with steps `a`, `b` and `c`
- **WHEN** a draft removing `b` is published
- **THEN** the new semantic version MUST bump the MAJOR component

#### Scenario: A removed edge is major, even with every node intact

- **GIVEN** a published flow whose graph connects `a → b`
- **WHEN** a draft removing that edge, and no node, is published
- **THEN** the change MUST be classified MAJOR

#### Scenario: A removed config key on a surviving node is major

- **GIVEN** a published step carrying `assignee` and `title`
- **WHEN** a draft that drops `assignee` from it is published
- **THEN** the change MUST be classified MAJOR

#### Scenario: Adding is minor

- **GIVEN** a published flow
- **WHEN** a draft adding a step, an edge and a config key is published
- **THEN** the change MUST be classified MINOR

#### Scenario: An identical republish is minor, not a refusal

- **GIVEN** a published flow
- **WHEN** a draft identical to it is published
- **THEN** the publish MUST succeed
- **AND** the change MUST be classified MINOR

---

### Requirement: The author is told what it will be, before publishing

The system SHALL report, before a publish is committed, which component the
publish will bump and — when major — WHAT was removed.

A derivation the author cannot see before it fires is one they cannot trust,
and the first surprising major is the one that teaches them to ignore the
number.

#### Scenario: The preflight names what was removed

- **GIVEN** a draft that removes a step and an edge
- **WHEN** the author asks what publishing would do
- **THEN** the answer MUST say the publish is major
- **AND** it MUST name the removed step and the removed edge

---

### Requirement: An author may raise the verdict and never lower it

The system SHALL accept an explicit request to publish as MAJOR, and SHALL
honour it even when the diff found only additions. An author who knows that a
changed value breaks a consumer is the only source of that fact.

The system SHALL REFUSE a request to publish as minor when the diff found a
removal, and the refusal SHALL name what was removed.

The asymmetry is the point. The diff is evidence and cannot be argued down;
the author's knowledge exceeds the diff and can only be added to it.

#### Scenario: An author raises a minor to a major

- **GIVEN** a draft whose only change is a configuration VALUE
- **WHEN** the author publishes it as major
- **THEN** the MAJOR component MUST bump

#### Scenario: An author cannot talk a removal down

- **GIVEN** a draft that removes a step
- **WHEN** the author publishes it as minor
- **THEN** the publish MUST be refused
- **AND** the refusal MUST name the removed step

---

### Requirement: Existing published versions are stamped once, and honestly

The system SHALL provide a repair that gives every already-published version a
semantic version.

The repair SHALL NOT invent history. It cannot know whether the third publish
of a flow was breaking, because the graphs it would have to compare are the
ones it is being run to describe. It SHALL therefore stamp the versions of one
flow in ordinal order as `1.0.0`, `1.1.0`, `1.2.0` and so on, and SHALL record
that these were derived by back-fill rather than from a diff.

The repair SHALL NOT fail an upgrade over a version it cannot stamp.

#### Scenario: A back-filled version says it was back-filled

- **GIVEN** a flow with four published versions and no semantic versions
- **WHEN** the repair runs
- **THEN** they MUST read `1.0.0`, `1.1.0`, `1.2.0`, `1.3.0`
- **AND** each MUST be marked as back-filled rather than derived
