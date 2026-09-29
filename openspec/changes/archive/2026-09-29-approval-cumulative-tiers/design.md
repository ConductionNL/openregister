# Design: approval-cumulative-tiers

Read at openregister development `d3684bf2c1`.

## What exists

| Piece | Where |
|---|---|
| Declaration compile | `lib/Service/ApprovalChainAnnotationInstaller.php` compile() carries `amountField` and the ordered `positions` |
| Tier routing | `lib/Listener/ApprovalChainGateListener.php` resolveTierPositions(): the single highest tier, or null |
| Provisioning | `lib/Service/Task/TaskSequenceService.php` provision(): `tierPositions` frozen on the sequence |

## Approach

1. compile() reads `tiers` (default `highest`), refuses an unknown value (returns null, logged), and carries it on the template.
2. resolveTierPositions() delegates to resolveCumulativeTiers() for `cumulative`: every position with `minAmount` at or below the amount, sorted by `minAmount`, renumbered from 1.
3. evaluateGate() resolves the tiers before looking up a sequence; an empty list means nothing to approve and the transition is released without a sequence.

## Declarative or imperative

Declarative: one key on the existing chain declaration.

## Tests

- `ApprovalChainGateListenerTest::testCumulativeTiersRequireEveryTierAtOrBelowTheAmount` (12,500 euro, three tiers declared out of order: team lead then facility manager).
- `ApprovalChainGateListenerTest::testCumulativeTiersBelowTheLowestTierNeedNoApproval`.
- `ApprovalChainGateListenerTest::testAnUnknownTiersModeFailsClosed`.
- The existing highest-tier tests stay green unchanged.
