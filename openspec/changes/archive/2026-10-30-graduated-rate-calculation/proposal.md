---
kind: code
---

# Proposal: graduated-rate-calculation

## Why

ADR-118 (hydra, decisions 182, 184, 185) moves procedures out of app code and into configuration. Capability C16 (money calculation and payment) needs an amount that accrues over time on a rate that steps up, stops at a cap, and stays current without app code. dossiq computes its Awb 4:17 penalty in a hand-written daily loop today (`DwangsomCalculationService`). The same shape is tiered pricing, a tax bracket table, or a late fee that rises per week.

OpenRegister's calculation engine already has the rest: materialised calculations, `diffDays`, `now` and the temporal sweep that rewrites a `now`-dependent calculation every day. It lacks one thing: summing a quantity over rate bands.

## What changes

- Two operators in `CalculationEvaluator`, backed by a pure `RateSchedule` class:
  - `graduated: [quantity, bands, cap?]` returns the amount the quantity accrues over the bands, capped when a cap is given.
  - `bandRate: [unit, bands]` returns the rate of the band a unit falls in, counting from 1.
- A band list is `[{upTo, rate}, ..., {rate}]`. Only the last band may leave out `upTo`. A malformed schedule is refused with an `EvaluationException`, never read as zero.
- `CalculationAnnotationValidator` treats a `lit` operand as data. Before this change, a literal holding objects (a rate schedule) was walked as if it were an expression and refused as `calculation-malformed-expr`.
- Both operators are published through `OperatorCatalogue` automatically, because the catalogue reads `CalculationEvaluator::OPERATORS`.

## Out of scope

- Interest over a period (clawback with statutory interest). That follows when subsidie moves (lane R4).
- The dossiq configuration that uses these operators. That ships in its own dossiq PR.
