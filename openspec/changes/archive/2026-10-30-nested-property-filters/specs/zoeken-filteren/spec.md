# zoeken-filteren

## ADDED Requirements

### Requirement: A filter can name a field inside an object property

A property filter SHALL accept a path into an object-typed property, written
as a dotted key (`dunning.currentStage=2`) or as the nested map the request
layer rebuilds from it (`['dunning' => ['currentStage' => 2]]`). It SHALL
compare the value at that path on PostgreSQL, MySQL and MariaDB, for the list
and the count, with equality, a list of values, `in`, `notIn`, `ne`, `isnull`,
`like` and the comparisons `gt`, `gte`, `lt` and `lte`. The comparisons SHALL
be numeric when the operand is a number. A boolean value SHALL compare in its
JSON spelling. A path segment that is not a plain property name SHALL match
nothing. A path into an encrypted property SHALL be refused, the same as a
filter on that property. A filter on the object property itself SHALL keep
its meaning.

#### Scenario: invoices in a reminder round

- **GIVEN** an ARInvoice schema whose object property `dunning` holds `currentStage`, two invoices at stage 1 and 2, and one without a stage
- **WHEN** a client lists `ARInvoice?dunning.currentStage[isnull]=false`
- **THEN** the two invoices with a stage are returned, and the total is 2

#### Scenario: the same filter from PHP

- **GIVEN** the same invoices
- **WHEN** a PHP caller searches with `['dunning.currentStage' => 2]`
- **THEN** the invoice at stage 2 is returned

#### Scenario: a path segment that is not a name

- **WHEN** a client filters on `dunning.current'Stage=1`
- **THEN** nothing matches and the filter is reported as ignored
