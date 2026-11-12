## ADDED Requirements

### Requirement: Someone who opens a locked record sees who holds the lock

A user MUST be able to lock a record from the records list or the record page. While the lock
holds, anyone else who opens the record MUST see that it is locked and the name of the user who
locked it.

#### Scenario: A colleague sees the lock holder
- **GIVEN** user `jan` has locked the record `melding-1`
- **WHEN** user `piet` opens `melding-1`
- **THEN** the record page says the record is locked by `jan`

#### Scenario: An unlocked record shows no lock
- **GIVEN** `jan` has unlocked `melding-1`
- **WHEN** `piet` opens `melding-1`
- **THEN** the record page shows no lock notice
