## ADDED Requirements

### Requirement: A fragment may put a case plan on its objects

A fragment MAY declare `casePlans`. Each row SHALL name `object` and a `definition` with at least one item, and MAY give `history`. On install the set SHALL bring the items in through the case layer's convergent import as the fragment's app, each item in the state it declares. A second install SHALL create nothing, and remove SHALL delete the items the set created.

#### Scenario: a Woo case's plan
- **GIVEN** a row on case 710d44a4 with a reached milestone, an active task, a discretionary task and a waiting one
- **WHEN** the set is installed
- **THEN** the case's Zaakplan card shows the four items in those states

#### Scenario: a refresh after the plan moved on
- **GIVEN** a seeded plan whose waiting item a cascade activated since
- **WHEN** the set is refreshed
- **THEN** the items the set created are taken away and written again in their declared states
