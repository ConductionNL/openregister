# organisation-quota Specification

## Purpose
An organisation can carry a user limit and an object limit. Its quota reports both limits, and its usage counts the live objects of the schema the object limit names.

## Requirements

### Requirement: An organisation quota limits its users and its objects

An organisation SHALL be able to carry a user limit and an object limit with the slug of the schema the object limit
counts. Its quota SHALL answer them, and its usage SHALL count its live objects of that schema when it has an object
limit, null when the count fails.

#### Scenario: Gemeente Waterland

- **GIVEN** Waterland with `objectQuota: 5000`, `objectQuotaSchema: "case"` and `userQuota: 50`
- **WHEN** its usage is measured
- **THEN** `quota.objects` is 5000, `quota.users` is 50 and `usage.objects` counts its live case objects
