## Purpose

The objects a run is deliberately working with, named by role, so a later step
can point at one. Distinct from the objects a run has written, which the audit
trail already answers.

## ADDED Requirements

### Requirement: A run keeps an ordered set of declared subjects

A flow run SHALL carry a set of subject entries. Each entry SHALL name a
`register`, a `schema`, a `uuid`, and a `role`: a short name chosen by the
flow's author, such as `case` or `decision`.

A role SHALL be unique within a run. Recording a second object under a role
already held SHALL replace the entry, and the replacement SHALL be recorded in
the run's log — a flow that quietly re-points `case` halfway through is a flow
whose later steps attach to something the author did not mean.

The run's triggering object, where it has one, SHALL be the first entry, with
the role `trigger`. A run that declares nothing else therefore holds exactly
the fact `subjectUuid` already holds, and behaves as it does today.

The set SHALL be persisted with the run and SHALL survive suspension and
resumption. A run parked on a human task for three weeks must still know what
it is working on.

#### Scenario: A run with no declarations holds only its trigger

- **GIVEN** a flow with no node declaring a subject role
- **WHEN** it runs from an object trigger
- **THEN** the run's subjects MUST hold exactly one entry, with role `trigger`
- **AND** it MUST name the triggering object

#### Scenario: Re-pointing a role replaces and is recorded

- **GIVEN** a run that has recorded object A under the role `case`
- **WHEN** a later step records object B under `case`
- **THEN** the entry MUST name B
- **AND** the run's log MUST record the replacement

---

### Requirement: A node adds a subject only by declaring it

A node SHALL record an object into the subject set only when its
configuration names a role for it. A node that names no role SHALL record
nothing.

The system SHALL NOT populate the set implicitly from what nodes write.
Implicit population is the audit-derived list, which already exists and
answers a different question; a set that filled itself would answer neither
question well.

Recording SHALL be idempotent for a node that fires more than once with the
same object and role, so a heartbeat wake, a retry or a resumed run does not
grow the set.

#### Scenario: A write with no role changes nothing

- **GIVEN** an object-write step with no `subjectRole`
- **WHEN** it writes an object
- **THEN** the run's subject set MUST be unchanged
- **AND** the audit-derived objects list MUST still show the write

#### Scenario: A re-fired node does not duplicate an entry

- **GIVEN** a step that recorded object A under `case`
- **WHEN** the same step fires again with the same object
- **THEN** the set MUST still hold one entry for `case`

---

### Requirement: Addressing a role the run has not recorded fails the step

When a node names a subject role in order to READ it, and the run holds no
entry under that role, the node SHALL fail the step, naming the role and
listing the roles the run does hold.

The system SHALL NOT fall back to the trigger. A step that asked for `case`
and silently got the triggering object would attach a decision to the wrong
record, and would do so most often in exactly the flows complex enough for
the two to differ.

#### Scenario: An unknown role fails loudly and says what is available

- **GIVEN** a run holding subjects under `trigger` and `case`
- **WHEN** a step addresses the role `decision`
- **THEN** the step MUST fail
- **AND** the failure MUST name `decision` and list `trigger` and `case`

---

### Requirement: The declared subjects and the touched objects are read separately

The run read SHALL serve the declared subject set. The existing objects
endpoint SHALL keep its audit-derived meaning and SHALL NOT be changed to
serve declarations.

The two answer different questions — *what is this run working on* and *what
has this run written* — and an object may appear in either, both, or neither.

#### Scenario: An object read but never written appears only in the declarations

- **GIVEN** a run that recorded a case under `case` and never wrote to it
- **WHEN** both reads are made
- **THEN** the declared subjects MUST include the case
- **AND** the audit-derived objects MUST NOT
