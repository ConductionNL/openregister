# leaf-provider-registration

## ADDED Requirements

### Requirement: A leaf declares how its render bundle reaches the page (REQ-LPR-021)

A leaf descriptor MAY declare a load strategy: the shared `leaves` entry, its own
script, or already present. A leaf declaring the shared entry SHALL be refused
when its app ships no such bundle, and the refusal SHALL name the file. A leaf
declaring any other strategy, or declaring none, SHALL NOT be refused for a
missing bundle.

#### Scenario: a claimed shared entry with no bundle is refused

- **GIVEN** a leaf declaring the shared entry
- **AND** an app shipping no leaf bundle
- **WHEN** it is contributed
- **THEN** it is refused, naming the file

#### Scenario: an app that loads its own bundle is trusted

- **GIVEN** a leaf declaring its own script
- **AND** an app shipping no leaf bundle
- **WHEN** it is contributed
- **THEN** it registers

#### Scenario: silence is not a claim

- **GIVEN** a leaf declaring no strategy
- **WHEN** it is contributed
- **THEN** it registers
- **AND** the missing bundle is reported
