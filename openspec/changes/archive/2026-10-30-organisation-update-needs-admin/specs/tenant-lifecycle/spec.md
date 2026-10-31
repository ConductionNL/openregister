# tenant-lifecycle Delta

## ADDED Requirements

### Requirement: Only an administrator or the organisation's administrator changes an organisation

`PUT` and `PATCH /api/organisations/{uuid}` SHALL answer 403 to a caller who is neither a Nextcloud administrator nor
the organisation's administrator (its owner), before anything is written, with a message saying members can read the
organisation. A Nextcloud administrator and the organisation's administrator SHALL be able to change its name and
description.

#### Scenario: A member renames the organisation

- **GIVEN** Pieter, a member but not the owner of "Gemeente Waterland"
- **WHEN** he sends a new name
- **THEN** the answer is 403 and the name is unchanged

#### Scenario: The organisation's administrator renames it

- **GIVEN** Marieke, the owner of "Gemeente Waterland"
- **WHEN** she sends a new name
- **THEN** the answer is 200 and the organisation carries the new name
