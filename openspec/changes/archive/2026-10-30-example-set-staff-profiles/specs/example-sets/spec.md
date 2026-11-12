# example-sets Delta: example-set-staff-profiles

## ADDED Requirements

### Requirement: A cast person may carry a function, a role, an absence and a status

A cast person's `function` SHALL be written as the profile headline of the account the set created for them, and an
optional `roles` as its profile role. An optional `absence` (`fromDay` and `untilDay` counted from the install day)
SHALL be written as the account's out of office, and an optional `status` as its user status with its message. The
install SHALL write these at every run on accounts the set created only, and SHALL name a part it could not write
without refusing the set.

#### Scenario: A colleague away until next week

- **GIVEN** a cast person with `absence` `{"fromDay": -2, "untilDay": 7}`
- **WHEN** the set is installed
- **THEN** their account is out of office from two days before the install day until seven days after it

#### Scenario: An adopted account

- **GIVEN** a cast person whose account the administrator adopted
- **WHEN** the set is installed
- **THEN** their profile, absence and status are not written
