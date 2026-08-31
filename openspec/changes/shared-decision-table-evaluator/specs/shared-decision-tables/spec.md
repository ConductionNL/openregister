# shared-decision-tables Specification

## Purpose

One home for DMN decision-table evaluation, so the fleet stops building it per
app. Per ADR-065 Decision 6.

## ADDED Requirements

### Requirement: REQ-SDT-001 One evaluator, the union of both dialects

OpenRegister SHALL provide a decision-table evaluator implementing at least
`UNIQUE`, `FIRST`, `COLLECT`, `PRIORITY` and `ANY`, and SHALL refuse any hit
policy it does not implement rather than silently treating it as `FIRST`.

`PRIORITY` is required: openbuild's evaluator has it and dossiq's does not, so
omitting it would make the consolidation a capability regression.

#### Scenario: An unimplemented policy is refused

- **WHEN** a table declares a hit policy the evaluator does not implement
- **THEN** it raises `hit_policy_not_implemented` and decides nothing

#### Scenario: PRIORITY takes the highest

- **GIVEN** matching rules with priorities 1, 10 and 5
- **WHEN** the table is evaluated
- **THEN** the rule with priority 10 wins

#### Scenario: PRIORITY is deterministic on a tie

- **GIVEN** two matching rules of equal priority
- **THEN** the earlier one in declaration order wins

#### Scenario: An absent priority counts as zero

- **GIVEN** one rule declaring no priority and one declaring 3
- **THEN** the one declaring 3 wins

### Requirement: REQ-SDT-002 ANY asserts agreement

Under `ANY` the system SHALL require every matching rule to produce the same
output, and SHALL raise `hit_policy_violation` when they differ.

A table declaring ANY asserts that its overlapping rules agree; a disagreement
is a fault in the table, not a choice for the engine. openbuild's evaluator
treated `any` as `collect` and returned a list, which is a different answer of a
different shape.

#### Scenario: Disagreeing rules are refused

- **GIVEN** two matching rules with different outputs under ANY
- **THEN** evaluation raises `hit_policy_violation`

#### Scenario: Agreeing rules return the shared output

- **GIVEN** two matching rules with the same output under ANY
- **THEN** that output is returned

### Requirement: REQ-SDT-003 The unary-test grammar is preserved intact

The evaluator SHALL support the grammar it inherits: wildcards, typed equality,
comparison operators, inclusive and exclusive ranges, set membership, and a
quoted literal that escapes the wildcard.

It SHALL NOT execute arbitrary code for any expression.

#### Scenario: Ranges keep their boundary semantics

- **GIVEN** `[0..25000]` and `(25000..100000]`
- **THEN** 25000 matches the first and 25001 the second

#### Scenario: Set membership matches any listed value

- **GIVEN** `in (gering, aanzienlijk)`
- **THEN** `aanzienlijk` matches and `ernstig` does not

### Requirement: REQ-SDT-004 A three-axis matrix is expressible as a table

A dense lookup over three inputs SHALL evaluate to its single matching rule
under `UNIQUE`.

This is the shape dossiq's Landelijke Handhavingsstrategie matrix takes:
(ernst x gedrag x actorType) to one intervention. It is a decision table, and it
belongs on this engine rather than in a bespoke matrix service.

#### Scenario: The LHS matrix shape evaluates

- **GIVEN** a table over severity, behaviour and actorType
- **WHEN** evaluated with a triple matching exactly one rule
- **THEN** that rule's intervention is returned with its id
