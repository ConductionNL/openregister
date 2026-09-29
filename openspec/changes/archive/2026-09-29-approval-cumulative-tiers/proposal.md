---
kind: code
depends_on: []
---

# Proposal: approval-cumulative-tiers

## Summary

An approval chain with amount tiers can say that an amount needs every tier at or below it. A purchase order of 12,500 euro then needs the team lead and the facility manager, one after the other, instead of the facility manager alone. An amount below the lowest tier needs no approval.

## Why

Ruben decided on 29 Sep 2026 (build-all DECISIONS.md, row 6) that purchase order approval tiers are cumulative, and that shillinq builds `purchasing-approval-delegation` on OpenRegister's approval chains. OpenRegister's threshold routing (approval-workflow REQ-008) selects a single tier: the one with the highest `minAmount` at or below the amount. With that rule a 12,500 euro order skips the team lead, and an order below the lowest tier falls back to every declared step, the opposite of shillinq's design (an order below the first tier approves directly).

## What changes

1. `x-openregister-approval-chains.<key>.tiers` takes `highest` (the default, today's behaviour, unchanged for every existing declaration) or `cumulative`.
2. With `cumulative`, the gate provisions every approver entry whose `minAmount` is at or below the object's amount, lowest first, as ordered steps.
3. With `cumulative`, an amount below the lowest tier provisions nothing and the transition goes ahead.
4. An unknown `tiers` value makes the chain misconfigured, which fails closed as an uncompilable chain does today.

## Out of scope

- Changing the default for existing declarations (shillinq's commitment and expense chains keep `highest` until shillinq opts in).
- Parallel approval within a tier.
