# example-sets Delta

## ADDED Requirements

### Requirement: A fragment may give a task its requester and expiry

A fragment's task MAY declare `requester` and `expiresAt`. The check SHALL refuse a `requester` that is not an account the set creates or adopts, an `expiresAt` that is not a date or does not resolve, and an `expiresAt` before the task's `dueAt`. Install SHALL write both to the task.

#### Scenario: A hearing task asked for by a colleague
- **GIVEN** a fragment declares task "Hoorzitting inplannen" with `requester` `{{cast:anouk.uid}}` and `expiresAt` `{{datetime:9 17:00}}`
- **WHEN** the set is installed
- **THEN** the task SHALL name Anouk as its requester and expire nine days after the install day at 17:00

#### Scenario: A requester the set does not know
- **GIVEN** a fragment's task declares `requester` `{{cast:nobody.uid}}` or an account the set does not create or adopt
- **WHEN** the set is checked
- **THEN** the check SHALL refuse the task and write nothing
