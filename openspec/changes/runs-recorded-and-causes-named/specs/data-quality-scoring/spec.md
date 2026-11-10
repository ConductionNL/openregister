# data-quality-scoring

## ADDED Requirements

### Requirement: A quality audit declares a population, a rule set and a tolerance (REQ-RCN-003)

A quality audit SHALL be a named record declaring the population it measures
as an object query, the rules to evaluate over it, and a tolerance the
result is judged against. It MAY declare a schedule. The rules SHALL be
evaluated by the same scorer that computes a per-object score on save, so
one rule cannot mean two things. An audit whose population query is invalid,
or whose rule set is empty, SHALL be refused at save naming the fault.

#### Scenario: a steward measures the address register

- **GIVEN** an audit over every object of one schema, with rules on `postcode` and `houseNumber` and a tolerance of 0.95
- **WHEN** it runs
- **THEN** it reports one score for the population and a verdict against the tolerance

#### Scenario: an empty rule set is refused

- **GIVEN** an audit declaring a population and no rules
- **WHEN** it is saved
- **THEN** the save fails naming the missing rules
- @e2e exclude {validator, covered by unit tests}

### Requirement: A quality audit run is kept, with its failures and the tolerance it was judged against (REQ-RCN-004)

A run SHALL record the moment, the actor or the schedule that started it,
the number of records evaluated, the score, the tolerance as it stood at the
run, the verdict, and the records that failed with the rules they failed.
The tolerance SHALL be stored on the run, so a later change to the audit
does not re-judge a past run. Runs SHALL be kept and readable in sequence,
and the failing records of a run SHALL be exportable.

#### Scenario: a past run keeps its verdict

- **GIVEN** a run that passed at a tolerance of 0.90
- **WHEN** the audit's tolerance is later raised to 0.98
- **THEN** the past run still reads as passed at 0.90

#### Scenario: the trend is readable

- **GIVEN** three monthly runs of one audit
- **WHEN** the audit is read
- **THEN** the three scores are returned in sequence with their dates

#### Scenario: the failures are workable

- **GIVEN** a run with forty failing records
- **WHEN** its failures are exported
- **THEN** each row names the record and the rules it failed
- @e2e exclude {export, covered by unit tests}
