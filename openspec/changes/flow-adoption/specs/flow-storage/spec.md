# flow-storage

## ADDED Requirements

### Requirement: Adopting a flow is a deliberate, caller-bound act

The system SHALL provide an explicit adoption action
(`POST /api/flows/{id}/adopt`) that sets a flow's `owner` to the CALLING
user's uid. The new owner SHALL NEVER be taken from the request body or any
other caller-chosen value. The action SHALL require the `flow.update` right
and SHALL be organisation-scoped the way every per-flow action is: a flow of
another organisation answers the same not-found a missing flow does.

A flow whose `owner` is already another user SHALL be refused with a
machine-readable reason rather than taken over — adoption re-points whose
identity existing subscriptions run as, which must never happen silently.
Adopting a flow one already owns SHALL succeed without a write.

Adoption SHALL NOT change `enabled`. A shipped flow becomes dispatchable only
when it has been both adopted and enabled, as two separate acts.

Every successful adoption SHALL be audited with the flow, the adopter and the
previous owner.

#### Scenario: The caller becomes the owner

- **GIVEN** an imported flow with `owner=null`
- **WHEN** an authorized user posts to its adopt action, whatever the body
  carries
- **THEN** the stored flow's `owner` MUST be that user's uid, and the
  adoption MUST be audited
- @e2e exclude API-layer seam — covered by `FlowControllerTest` (contract)
  and `FlowAdoptionTest` (service)

#### Scenario: Adoption is not a takeover

- **GIVEN** a flow owned by another user
- **WHEN** a caller posts to its adopt action
- **THEN** the request MUST be refused with reason `already-owned` and the
  owner MUST be unchanged
- @e2e exclude API-layer refusal — covered by `FlowAdoptionTest`

#### Scenario: An adopted and enabled flow dispatches

- **GIVEN** an imported flow that has been adopted and then enabled
- **WHEN** its trigger fires
- **THEN** the flow MUST be dispatchable — the ownerless refusal no longer
  applies
- @e2e exclude engine-internal dispatch check — covered by `FlowAdoptionTest`
  through `FlowLocator`

#### Scenario: An anonymous or unauthorized caller is refused

- **WHEN** a caller with no session, or without the `flow.update` right,
  posts to a flow's adopt action
- **THEN** the request MUST be refused (401 / 403) and no owner written
- @e2e exclude auth posture — covered by `FlowControllerTest`
