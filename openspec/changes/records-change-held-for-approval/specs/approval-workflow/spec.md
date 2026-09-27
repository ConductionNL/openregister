# approval-workflow

## ADDED Requirements

### Requirement: An approval chain can hold a change until it is approved

An `x-openregister-approval-chains` entry MAY declare `gate.change` with the gated
properties, the operations (`create`, `update`) and exempt groups. A write that changes
a gated property, by a caller outside the exempt groups, SHALL be stored as a pending
change and answered with 202 and the pending change key, and the stored record MUST
remain unchanged until approval.

#### Scenario: A clerk changes a supplier's bank account

- **GIVEN** the schema `leverancier` with a change gate on `iban` and an approval chain for the group `financieel-beheer`
- **WHEN** a clerk sends `PATCH /api/objects/crediteuren/leverancier/{id}` with a new `iban`
- **THEN** the response is 202 with a pending change key
- **AND** `GET` on the supplier still returns the old `iban`
- @e2e exclude {specified only; task 4.1 adds tests/e2e/change-held-for-approval.spec.ts}

#### Scenario: A change to an ungated field goes through

- **GIVEN** the same gate on `iban` only
- **WHEN** the clerk changes the supplier's phone number
- **THEN** the response is 200 and the phone number is stored
- @e2e exclude {specified only; task 2.1 adds the listener test}

### Requirement: A gated new record does not exist until it is approved

When the gate covers `create`, a new record SHALL be stored as a pending change with a
reserved uuid and no live object. Reads of that uuid MUST answer 404 and no list,
count or search MUST include it until approval.

#### Scenario: A rejected submission is never stored

- **GIVEN** the schema `subsidieaanvraag` with a change gate on `create`
- **WHEN** an applicant's intake form submits a new aanvraag and the reviewer rejects it with a reason
- **THEN** no subsidieaanvraag object with that uuid exists
- **AND** the audit trail records the submission, the rejection and the reason
- @e2e exclude {specified only; task 3.2 adds the reject test}

### Requirement: The submitter cannot approve their own change

The approval of a pending change SHALL run as the chain's task sequence with
separation of duties, and the system MUST refuse an approval by the submitter or by
anyone acting on the submitter's behalf.

#### Scenario: A clerk tries to approve their own bank account change

- **GIVEN** a pending `iban` change submitted by a clerk who is also in `financieel-beheer`
- **WHEN** the clerk approves the task in their inbox
- **THEN** the approval is refused with the separation-of-duties reason
- **AND** the pending change stays open
- @e2e exclude {specified only; task 3.1 adds the test}

### Requirement: Approval applies the change and rejection discards it

On an approving outcome the system SHALL apply the pending change through the normal
save path, and on a rejecting outcome SHALL discard it, recording the decision and
comment on the audit trail. A second gated write to a record with an open pending
change for the same gate MUST be refused with 409.

#### Scenario: A second person approves and the change takes effect

- **GIVEN** a pending `iban` change submitted by a clerk
- **WHEN** a colleague in `financieel-beheer` approves it
- **THEN** `GET` on the supplier returns the new `iban`
- **AND** the audit trail names the clerk as submitter and the colleague as approver
- @e2e exclude {specified only; task 4.1 adds tests/e2e/change-held-for-approval.spec.ts}
