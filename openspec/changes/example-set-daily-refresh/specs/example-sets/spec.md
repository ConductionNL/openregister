# example-sets Delta: example-set-daily-refresh

## ADDED Requirements

### Requirement: A set may be refreshed every day

An administrator MAY mark a set for a daily refresh with `--daily` on the install command, and SHALL be able to stop it with `--no-daily`. A background job SHALL, once a day, refresh every marked set that is installed, against that day, exactly as `--refresh` does. A marked set that is not installed SHALL be skipped and SHALL NOT be installed by the job. No set SHALL be marked by default.

A refresh (`--refresh` and the daily job) SHALL rewrite the set's data only: it SHALL NOT create accounts, groups, memberships or sub-admin rights, so what an administrator removed after the install stays removed; the run SHALL report it as missing.

#### Scenario: Today follows the view day
- **GIVEN** an installed set with an object dated `{{date:0}}`, marked for the daily refresh
- **WHEN** the job runs the next day
- **THEN** the object SHALL carry the next day's date

#### Scenario: A removed membership stays removed
- **GIVEN** an installed set that added a cast person to a group, marked for the daily refresh
- **AND** an administrator removed that person from the group
- **WHEN** the job runs
- **THEN** the person SHALL NOT be a member again, and the run SHALL name the membership as missing
