---
status: proposed
---

# decisions

## ADDED Requirements

### Requirement: A decision is its own object (REQ-DEC-001)

OpenRegister SHALL store a decision as its own object in `openregister_decisions`, with `uuid`, `subjectType`, `subjectId`, `verdict`, `decidedBy`, `decidedAt`, `note` and `supersedes`. A decision SHALL NOT be edited after it is stored: changing a verdict, its grounds or its note SHALL record a new decision whose `supersedes` names the previous one, and the subject SHALL link the new one. A subject links its current decision by uuid; an entity relation does so through `decision_uuid`, serialised as `decision`. Every decision on a subject SHALL be readable in order through `DecisionService::history()`. A decision without a subject, a verdict or a decider, or with a note longer than 4,000 characters, SHALL be refused.

#### Scenario: a reviewer's redaction is recorded as a decision object
<!-- @e2e exclude Covered by PHPUnit DecisionOwnershipTest::testAnUndecidedRelationIsOpen and EntityRelationsControllerTest::testReturns200OnSuccessfulPatch through EntityRelationsController::update. -->

- **GIVEN** an undecided entity relation 7
- **WHEN** `j.devries` sends `PATCH /api/entity-relations/7` with `bases: ["5.1.2e"]` and `decisionNote: "Private person."`
- **THEN** a decision is stored with subject `entity-relation` 7, verdict `redact`, decided by `j.devries`, the moment and the note, the relation's `decision` names its uuid, and the response carries it as `decisionRecord`

#### Scenario: a change supersedes, it does not overwrite
<!-- @e2e exclude Covered by PHPUnit DecisionOwnershipTest::testASupervisorMayChangeAnotherReviewersDecision. -->

- **GIVEN** that decision
- **WHEN** a coordinator changes the relation to release
- **THEN** a second decision is stored, decided by the coordinator, verdict `release`, superseding the first, and the first is unchanged

#### Scenario: repeating a decision records nothing
<!-- @e2e exclude Covered by PHPUnit DecisionOwnershipTest::testTheNoteFollowsTheDecision. -->

- **GIVEN** a decided relation
- **WHEN** its decider sends the same bases again
- **THEN** no new decision is recorded

### Requirement: The app that owns the subject may answer who supervises (REQ-DEC-002)

Before anyone other than the decider or an administrator changes a decider-owned decision, OpenRegister SHALL dispatch `DecisionChangeRequestedEvent` carrying the decision, the caller, the policy, the subject (subject type and id and, when known, register, schema, object uuid and file id) and OpenRegister's own answer from the policy's supervisor groups. A listener MAY allow or refuse. A listener that throws SHALL refuse. The event SHALL NOT be dispatched under `open` ownership, for the decider, or for an administrator.

#### Scenario: a case's reviewer access answers for its case
<!-- @e2e exclude Covered by PHPUnit DecisionOwnershipTest (Service\Decision)::testAListenerMayAllow and testAListenerMayRefuse; dossiq's listener is tested in dossiq. -->

- **GIVEN** a decider-owned decision on a document of a case whose case type names `woo-coordinator` as supervisor group, and an app listening for the event
- **WHEN** a member of that group, who is in no group the schema names, changes the decision
- **THEN** the listener allows it and the change is stored

#### Scenario: a failing listener refuses
<!-- @e2e exclude Covered by PHPUnit DecisionOwnershipTest (Service\Decision)::testAFailingListenerRefuses. -->

- **GIVEN** a listener that throws
- **WHEN** a supervisor changes another reviewer's decision
- **THEN** the change is refused with `decision-owned`

### Requirement: The app that owns the subject may show a hidden decision (REQ-DEC-003)

Before a decision is hidden from a caller under `hideOthers` (REQ-ROD-002), OpenRegister SHALL dispatch `DecisionVisibilityRequestedEvent` carrying the decision (null when its record is gone), the caller, the policy and the subject (as in REQ-DEC-002). A listener MAY allow, and the decision is then shown to that caller. Nothing else SHALL make a hidden decision visible: without an allow it stays hidden, and a listener that throws SHALL leave it hidden. The event SHALL NOT be dispatched when nothing would be hidden (no decision, `hideOthers` off, the caller is the decider, an administrator or in a supervisor group of the policy) or when nobody is signed in.

#### Scenario: a case-type supervisor sees hidden decisions on their cases
<!-- @e2e exclude Covered by PHPUnit HiddenDecisionTest::testTheOwningAppMayShowAHiddenDecision; dossiq's listener is tested in dossiq. -->

- **GIVEN** `hideOthers` on and a decision by `j.devries` on a document of a case whose case type names `woo-coordinator` as supervisor group, which the schema does not name
- **WHEN** a member of `woo-coordinator` reads the document's detections
- **THEN** the app that owns the case allows it and the decision, its ground and its verdict are shown

#### Scenario: a failing listener keeps the decision hidden
<!-- @e2e exclude Covered by PHPUnit HiddenDecisionTest::testAFailingListenerKeepsItHidden. -->

- **GIVEN** a listener that throws
- **WHEN** a reviewer reads another reviewer's decision under `hideOthers`
- **THEN** the relation shows `decision: "hidden"` without its ground or verdict
