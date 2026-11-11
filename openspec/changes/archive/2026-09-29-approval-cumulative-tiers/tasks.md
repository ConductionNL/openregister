# Tasks: approval-cumulative-tiers

- [x] 1.1 `tiers` on the chain declaration, compiled with a default of `highest`; an unknown value fails closed. Verify: `testAnUnknownTiersModeFailsClosed`.
- [x] 1.2 Cumulative routing: every tier at or below the amount, lowest first. Verify: `testCumulativeTiersRequireEveryTierAtOrBelowTheAmount`.
- [x] 1.3 Below the lowest cumulative tier nothing is provisioned and the transition goes ahead. Verify: `testCumulativeTiersBelowTheLowestTierNeedNoApproval`.
