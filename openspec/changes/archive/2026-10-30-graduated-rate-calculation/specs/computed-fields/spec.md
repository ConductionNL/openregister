# Computed Fields Specification (delta)

## ADDED Requirements

### Requirement: A quantity accrues over graduated rate bands (REQ-GRC-01)

The calculation engine MUST provide a `graduated` operator taking `[quantity, bands, cap?]`. `bands` MUST resolve to a list of `{upTo, rate}` objects with rising `upTo`, where only the last band MAY leave out `upTo` and then covers every later unit. The result MUST be the sum, per band, of the units of the quantity that fall in that band times the band's rate, and MUST NOT exceed `cap` when a cap is given. Units past the last closed band of a schedule without an open band MUST NOT be charged. A quantity of zero or less MUST yield zero. A quantity or schedule that resolves to null MUST yield null.

#### Scenario: A stepped daily fee
- **GIVEN** bands `[{upTo: 3, rate: 2}, {upTo: 8, rate: 3}, {rate: 5}]`
- **WHEN** `graduated` is evaluated for quantity 9
- **THEN** the result MUST be 26 (3 x 2 + 5 x 3 + 1 x 5)

#### Scenario: The cap holds
- **GIVEN** the same bands and cap 40
- **WHEN** `graduated` is evaluated for quantity 13
- **THEN** the result MUST be 40

### Requirement: The rate of the band a unit falls in (REQ-GRC-02)

The calculation engine MUST provide a `bandRate` operator taking `[unit, bands]` that returns the rate of the band whose range contains the unit, counting units from 1, and null when no band covers the unit (a unit of zero or less, or a unit past a closed schedule).

#### Scenario: The boundary unit belongs to the lower band
- **GIVEN** bands `[{upTo: 3, rate: 2}, {upTo: 8, rate: 3}, {rate: 5}]`
- **WHEN** `bandRate` is evaluated for units 3, 4 and 9
- **THEN** the results MUST be 2, 3 and 5

### Requirement: A malformed schedule is refused (REQ-GRC-03)

An empty schedule, a schedule that is not a list, a band without a numeric rate, an open band before the last position, or `upTo` bounds that do not rise MUST raise an evaluation error. A malformed schedule MUST NOT be read as an amount of zero.

#### Scenario: Falling bounds
- **GIVEN** bands `[{upTo: 5, rate: 1}, {upTo: 3, rate: 2}]`
- **WHEN** `graduated` is evaluated
- **THEN** evaluation MUST fail with an evaluation error

### Requirement: A literal operand is data (REQ-GRC-04)

The calculation annotation validator MUST NOT walk into the operand of `lit`, so a literal list of objects such as a rate schedule is accepted on import.

#### Scenario: A schedule written in the expression
- **GIVEN** a calculation `graduated: [{prop: hours}, {lit: [{upTo: 3, rate: 2}, {rate: 5}]}, 40]`
- **WHEN** the schema is validated
- **THEN** the validator MUST return no errors
