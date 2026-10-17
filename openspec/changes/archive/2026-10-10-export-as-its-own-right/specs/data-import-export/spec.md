# data-import-export

## ADDED Requirements

### Requirement: An export profile declares its field set, value mode and format (REQ-EXP-002)

An export profile SHALL be an object carrying a name, an ordered field
set, a value mode of `stored` or `rendered`, a format and an optional
filter, bound to a register and a schema. The field set SHALL be
independent of any saved view's columns. `stored` SHALL write values as
the object holds them; `rendered` SHALL write resolved relations, code
list labels and formatted dates as a surface would show them. The export
SHALL name its value mode in its own metadata.

#### Scenario: the monthly aanlevering has a fixed shape

- **GIVEN** a profile naming six fields in an order, and a saved view showing three different ones
- **WHEN** the profile is exported
- **THEN** the file holds the six fields in the profile's order

#### Scenario: one file, one value mode, stated

- **GIVEN** a profile with value mode `rendered`
- **WHEN** it is exported
- **THEN** relations and code list values appear as labels, and the file's metadata names the mode

#### Scenario: a stored export keeps the codes

- **GIVEN** the same profile with value mode `stored`
- **WHEN** it is exported
- **THEN** the raw values are written, unresolved
- @e2e exclude {writer behaviour, covered by unit tests}

### Requirement: A profile runs on a schedule and a whole-set extract runs as a bulk job (REQ-EXP-003)

An export profile SHALL be runnable on a schedule through the scheduled
report runner, using its owner's access. A profile with no filter and
every register in scope SHALL run as a background job through the bulk
action mechanism, writing one file per schema and reporting progress and
skips like any other bulk act.

#### Scenario: the datawarehouse extract runs overnight

- **GIVEN** a whole-set profile scheduled nightly
- **WHEN** it runs
- **THEN** one file per schema is produced and progress is readable while it runs
- @e2e exclude {long-running job, covered by unit tests with a fake writer}

#### Scenario: a scheduled export uses its owner's access

- **GIVEN** a scheduled profile owned by a principal who may read half the register
- **WHEN** it runs
- **THEN** the file holds that half

### Requirement: Every export is recorded on the audit trail (REQ-EXP-004)

Each completed export SHALL write one audit entry naming the actor, the
profile, the row count and the time. A refused export SHALL also be
recorded, with the reason.

#### Scenario: an incident can be reconstructed

- **GIVEN** three exports taken in one week
- **WHEN** an administrator reads the audit trail
- **THEN** each names its actor, profile and row count

#### Scenario: the refusal is recorded too

- **GIVEN** a principal refused the export verb
- **WHEN** the trail is read
- **THEN** one entry names the refusal and its reason
- @e2e exclude {audit assertion, covered by unit tests}
