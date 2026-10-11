# Tasks: graduated-rate-calculation

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 4. -->

- [x] 1.1 `RateSchedule` sums a quantity over rate bands with an optional cap, and returns the rate of the band a unit falls in (REQ-GRC-01, REQ-GRC-02)
- [x] 1.2 `graduated` and `bandRate` are dispatched by `CalculationEvaluator` and listed in `OPERATORS`, null-safe like the other arithmetic operators (REQ-GRC-01, REQ-GRC-02)
- [x] 1.3 A malformed schedule is refused, not read as zero (REQ-GRC-03)
- [x] 1.4 `CalculationAnnotationValidator` does not walk into a `lit` operand (REQ-GRC-04)
