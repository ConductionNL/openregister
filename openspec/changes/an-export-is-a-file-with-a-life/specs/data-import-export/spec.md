## ADDED Requirements

### Requirement: A produced export is recorded as a run

Every export this platform produces SHALL be recorded as an export run
carrying its profile, its actor, its register and schema, its format, its
row count, the file it produced, when it was produced and when it expires.

The run SHALL be written by every export path: the API, the scheduled report
runner and the whole-dataset extract. An export that produces no run is a
copy of the register nobody can account for, and "who holds an export of
this register" is a question an administrator is asked rather than one they
choose to ask.

The data-subject bundle SHALL NOT be recorded here. `SubjectExport` has its
own lifecycle and its own legal clock, and a data subject's bundle does not
belong in an administrator's export list.

#### Scenario: A scheduled report writes a run

- **GIVEN** a scheduled report that delivers to the owner's Files
- **WHEN** the runner executes it
- **THEN** an export run SHALL be recorded naming the profile, the owner,
  the row count and the produced file

#### Scenario: An AVG bundle is not an export run

- **GIVEN** a data-subject export bundle is generated
- **WHEN** an administrator lists export runs
- **THEN** the bundle SHALL NOT appear

---

### Requirement: An export expires, and the row outlives the file

An export profile SHALL declare a retention for the files it produces. A run
SHALL carry the expiry it was produced under, so a later edit to the profile
does not move an existing file's deadline without anybody deciding to.

A background job SHALL delete the file of an expired run and SHALL keep the
run. The fact that an export happened outlives the copy it made.

A profile that declares no retention SHALL keep its files, and a run
produced under it SHALL say so on the row rather than showing an empty
expiry.

#### Scenario: An expired export loses its file and keeps its row

- **GIVEN** an export run whose expiry has passed
- **WHEN** the sweep runs
- **THEN** the file SHALL be deleted
- **AND** the run SHALL still list, naming the actor, the row count and the
  expiry

#### Scenario: A profile edit does not move an existing deadline

- **GIVEN** an export run produced under a 30 day retention
- **WHEN** an administrator changes the profile to 7 days
- **THEN** the existing run SHALL keep its original expiry

#### Scenario: A run whose file is already gone is not a failure

- **GIVEN** an expired run whose file a user already deleted
- **WHEN** the sweep runs
- **THEN** the sweep SHALL complete and SHALL NOT record an error

---

### Requirement: Downloads are counted on the run

A download served from the register SHALL increment the run's own download
count.

The count SHALL be the run's and not only the file's. A file copied or moved
inside Files is no longer the thing the register handed out, so a count that
follows the file answers a different question from the one an administrator
asked.

#### Scenario: Two downloads of one export

- **GIVEN** an export run whose file has been downloaded twice from the
  register
- **WHEN** an administrator reads the run
- **THEN** its download count SHALL be 2

---

### Requirement: An exports area lists the runs

The platform SHALL offer a list of export runs, filterable by register,
schema, profile, actor and period, showing the row count, the format, the
expiry and the download count.

The list SHALL be scoped by the export verb: a principal sees the runs they
made, and an administrator sees every run. The scope SHALL be resolved
through the authorization layer that already answers who may read what,
never through a second rule written for this page.

An expired run SHALL be named as expired rather than rendered as a link to
nothing. A missing file and a file nobody produced look the same on a list,
and only one of them means the retention did its job.

#### Scenario: A handler sees their own exports

- **GIVEN** two principals who have each produced an export
- **WHEN** the first opens the exports area
- **THEN** they SHALL see their own run
- **AND** SHALL NOT see the second principal's run

#### Scenario: An expired run is named

- **GIVEN** an expired export run
- **WHEN** it is listed
- **THEN** it SHALL be shown as expired
- **AND** SHALL NOT offer a download
