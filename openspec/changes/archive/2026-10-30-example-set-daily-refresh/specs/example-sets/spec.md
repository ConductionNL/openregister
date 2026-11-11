# example-sets Delta: example-set-daily-refresh

## ADDED Requirements

### Requirement: A set may be refreshed every day

An administrator MAY mark a set for a daily refresh with `--daily` on the install command, and SHALL be able to stop it with `--no-daily`. A background job SHALL, once a day, refresh every marked set that is installed, against that day, exactly as `--refresh` does. A marked set that is not installed SHALL be skipped and SHALL NOT be installed by the job. No set SHALL be marked by default.

#### Scenario: Today follows the view day
- **GIVEN** an installed set with an object dated `{{date:0}}`, marked for the daily refresh
- **WHEN** the job runs the next day
- **THEN** the object SHALL carry the next day's date
