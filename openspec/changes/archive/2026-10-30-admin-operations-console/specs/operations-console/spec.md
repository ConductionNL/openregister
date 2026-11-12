# operations-console

## ADDED Requirements

### Requirement: Every background run is listed with its outcome (REQ-AOC-001)

The system SHALL record every background job run: the job, the start, the
end, the duration, the outcome and, on failure, the message. An
administrator SHALL be able to list the runs and filter them by job, by
outcome and by period. A job that does not run through the recorded path
SHALL be named on the console as unobserved rather than silently omitted.

#### Scenario: a failed run is visible with its reason

- **GIVEN** a background job that failed on its last run
- **WHEN** an administrator opens the operations console
- **THEN** the run is listed with outcome failed and the failure message

#### Scenario: an unobserved job is named as such

- **GIVEN** a job registered outside the recorded path
- **WHEN** the console is read
- **THEN** the job is listed as unobserved, with no runs
- @e2e exclude {registration edge case, covered by unit tests}

### Requirement: A run is started again from the console, once (REQ-AOC-002)

An authorised administrator SHALL be able to start a job from the console.
The act SHALL record who started it. A job already running SHALL NOT be
started a second time; the attempt SHALL be refused, naming the run that
holds it.

#### Scenario: a stuck queue is cleared by hand

- **GIVEN** a job whose last run failed
- **WHEN** an administrator starts it from the console
- **THEN** a new run appears, recording the administrator as its cause

#### Scenario: a running job is not started twice

- **GIVEN** a job that is currently running
- **WHEN** an administrator starts it
- **THEN** the attempt is refused, naming the running run

### Requirement: A recurring job's schedule is administered and failures raise an alert (REQ-AOC-003)

An administrator SHALL be able to set a job's interval, the window it may
run in, and whether it is enabled. The row SHALL carry the last run and
the next due time. The system SHALL raise an alert when a job fails more
than an administered number of times within an administered period,
delivered as a notification and readable on the console, naming the job
and its first failure in that period.

#### Scenario: a job is disabled and stops being due

- **GIVEN** an enabled recurring job
- **WHEN** an administrator disables it
- **THEN** it has no next due time and does not run

#### Scenario: repeated failures alert the administrators

- **GIVEN** a threshold of three failures in one hour
- **WHEN** a job fails four times within the hour
- **THEN** one alert is raised naming the job and the first of those failures
- @e2e exclude {threshold over time, covered by unit tests with a clock fixture}

### Requirement: Maintenance actions run as observable jobs (REQ-AOC-004)

Rebuilding the search index, clearing and warming the cache and running
the data consistency check SHALL be available on demand to an authorised
administrator, and each SHALL run as a recorded job with its own run row,
outcome and failure.

#### Scenario: a rebuild is as observable as any other job

- **GIVEN** an administrator who starts a search index rebuild
- **WHEN** the console is read
- **THEN** a run row exists for it, with progress while it runs and an outcome when it ends

### Requirement: The instance checks its own data and repairs it as a separate act (REQ-AOC-005)

The system SHALL provide a read-only consistency check that names each
inconsistency it finds and the objects it concerns, and SHALL NOT change
anything while checking. Repairing SHALL be a separate, authorised act
that names what it will change before it runs, and SHALL be recorded on
the audit trail.

#### Scenario: the check changes nothing

- **GIVEN** a register holding a known inconsistency
- **WHEN** the check runs
- **THEN** the inconsistency is reported and no object is written

#### Scenario: the repair is authorised and recorded

- **GIVEN** the reported inconsistency
- **WHEN** an authorised administrator repairs it
- **THEN** the change is applied and one audit entry names the actor and the objects

### Requirement: Maintenance mode closes the instance without locking administration out (REQ-AOC-006)

An administrator SHALL be able to put the instance into maintenance mode
with an administered message. While it holds, reads and writes SHALL be
refused with that message, the administration surface SHALL stay
reachable, and entering and leaving the mode SHALL be on the audit trail.

#### Scenario: a user is told why the instance is closed

- **GIVEN** maintenance mode with the message "onderhoud tot 14:00"
- **WHEN** a user opens a register
- **THEN** the request is refused and the message is shown

#### Scenario: the administrator can still leave the mode

- **GIVEN** maintenance mode holding
- **WHEN** an administrator opens the operations console
- **THEN** it renders, and leaving the mode is possible

### Requirement: A support bundle and the instance's own facts are readable (REQ-AOC-007)

The system SHALL produce a downloadable support bundle carrying the
version, the build, the active configuration with every secret redacted,
the consistency check results and the recent failed runs. A page SHALL
name the running version and build, the apps depended on with their
versions, and the licence.

#### Scenario: the bundle carries no secret

- **GIVEN** an instance with configured credentials
- **WHEN** the support bundle is produced
- **THEN** it holds the configuration keys and no credential value

#### Scenario: a support call opens with the version

- **GIVEN** an administrator on the instance facts page
- **THEN** the running version and build are named
- @e2e exclude {static render, covered by component tests}
