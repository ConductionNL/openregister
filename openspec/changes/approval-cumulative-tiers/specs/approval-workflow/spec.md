# approval-workflow

## ADDED Requirements

### Requirement: REQ-010 Amount tiers can be cumulative

A chain declaration with `amountField` MAY set `tiers` to `cumulative`. The gate SHALL then provision every approver entry whose `minAmount` is at or below the object's amount, ordered by `minAmount` from low to high, as consecutive steps. An amount below the lowest tier SHALL need no approval and the transition SHALL go ahead. Without `tiers`, or with `tiers: highest`, routing SHALL stay as REQ-008 describes. Any other value SHALL make the chain misconfigured and the gated transition SHALL be refused.

#### Scenario: an order of 12,500 euro needs the team lead and the facility manager

- **GIVEN** a purchase order schema whose `approve` transition carries a chain with `amountField` `totalAmount`, `tiers` `cumulative` and tiers teamleider from 1 cent, facility_manager from 1,000,000 cents and procurement_manager from 5,000,000 cents
- **WHEN** a user approves an order of 1,250,000 cents
- **THEN** the transition is held with `approval-chain-pending` and the sequence has two steps: teamleider first, then facility_manager
- @e2e exclude {backend routing; ApprovalChainGateListenerTest::testCumulativeTiersRequireEveryTierAtOrBelowTheAmount proves it}

#### Scenario: an order below the lowest tier approves directly

- **GIVEN** the same chain
- **WHEN** a user approves an order of 0 cents
- **THEN** the transition goes ahead and no approval sequence is created
- @e2e exclude {backend routing; ApprovalChainGateListenerTest::testCumulativeTiersBelowTheLowestTierNeedNoApproval proves it}

#### Scenario: an unknown tiers mode is refused

- **GIVEN** a chain with `tiers` `every-other`
- **WHEN** a user attempts the gated transition
- **THEN** the transition is refused with `approval-chain-misconfigured`
- @e2e exclude {backend routing; ApprovalChainGateListenerTest::testAnUnknownTiersModeFailsClosed proves it}
