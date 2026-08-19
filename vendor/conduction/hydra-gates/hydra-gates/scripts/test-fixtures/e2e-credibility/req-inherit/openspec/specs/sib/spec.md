# Sibling inheritance spec

Fixture for `.github#356`. ONE `@e2e exclude` in the REQUIREMENT body, three
scenarios beneath it, none of which carries an exclusion of its own and none of
which any e2e test references.

The gate currently reports `PASS — 0 reference(s) in e2e suite` over this file.
Measured on openbuild, this shape accounts for 471 of 509 exclusions (92.5%)
sharing a reason across only 42 distinct reasons, one of them covering 32
scenarios.

### Requirement: Sibling handling

@e2e exclude write-only field, invisible to a response-shape assertion

#### Scenario: alpha sibling

- WHEN a is submitted THEN the record is stored

#### Scenario: beta sibling

- WHEN b is submitted THEN the record is rejected

#### Scenario: gamma sibling

- WHEN c is submitted THEN a conflict is reported
