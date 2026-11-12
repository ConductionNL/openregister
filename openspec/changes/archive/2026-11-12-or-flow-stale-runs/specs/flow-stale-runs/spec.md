## ADDED Requirements

### Requirement: A run abandoned in `running` is failed, not left running

A run whose status is `running` and which has not been updated for longer than a
configured window SHALL be marked `failed`, with an error stating that the pass
which started it did not finish and that retrying will run it again.

Such a run SHALL NOT be left as-is. It is unreachable: the worker reads only
`queued` runs and due `suspended` ones, so nothing else will ever touch it, and
every surface that shows live runs shows it as running indefinitely.

#### Scenario: An abandoned run is failed with a readable reason

- **GIVEN** a run in `running` last updated two days ago
- **WHEN** the worker makes a pass
- **THEN** the run's status is `failed`
- **AND** its error says it was abandoned and can be retried

### Requirement: An abandoned run is never restarted automatically

Reaping SHALL NOT requeue or re-execute the run.

A run that died mid-walk may already have written an object, sent a mail or
called a webhook; restarting it would repeat those side effects silently. Turning
a terminal run back into a fresh run is what the retry surface is for, and it is
a decision for a person.

#### Scenario: Reaping does not run anything

- **GIVEN** an abandoned run
- **WHEN** the worker reaps it
- **THEN** no run is queued and no flow is executed

### Requirement: The abandonment window is configurable and can be switched off

The window SHALL be read from configuration with a default of 15 minutes, and a
value of `0` or less SHALL disable reaping entirely.

A run executes synchronously inside ONE worker pass, so a pass still in flight
has touched its row far more recently than the default; the window exists to be
unambiguous rather than tight. An operator whose single steps genuinely run
longer SHALL be able to opt out rather than have live work failed.

#### Scenario: The configured window reaches the query

- **GIVEN** a configured window of 90 minutes
- **WHEN** the worker makes a pass
- **THEN** the stale query is asked for runs untouched since ~90 minutes ago

#### Scenario: Zero disables the reaper

- **GIVEN** a configured window of `0`
- **WHEN** the worker makes a pass
- **THEN** no stale query is issued and no run is updated

### Requirement: Reaping happens before new work in the same pass

The reap SHALL run before the pass starts or resumes any run, so a pass cannot
mistake the `running` rows it has just created for abandoned ones.

#### Scenario: A pass does not reap its own work

- **GIVEN** a pass that starts a queued run
- **WHEN** that same pass reaps
- **THEN** the run it just started is not failed
