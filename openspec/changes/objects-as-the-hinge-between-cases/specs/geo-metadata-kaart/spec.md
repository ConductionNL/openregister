# geo-metadata-kaart

## ADDED Requirements

### Requirement: Geographic features are inherited with their provenance (REQ-OHC-006)

A schema MAY declare that a record collects geographic features from the
objects and parties it references. Each inherited feature SHALL name the
relation it arrived through. A feature the record holds itself SHALL
outrank an inherited one for the same purpose.

#### Scenario: the case shows the address it is about

- **GIVEN** a case referencing an address object that holds a point
- **WHEN** the case's features are read
- **THEN** the point is returned, naming the reference it came from

#### Scenario: a corrected location wins

- **GIVEN** the same case holding its own point
- **WHEN** its features are read
- **THEN** the case's own point is used and the inherited one is marked as superseded
- @e2e exclude {collector behaviour, covered by unit tests}
