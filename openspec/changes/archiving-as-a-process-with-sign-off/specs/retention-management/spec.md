# retention-management

## ADDED Requirements

### Requirement: An object is nominated for archiving when its business use ends (REQ-APS-001)

When an object reaches a terminal lifecycle state the system SHALL derive
its archival nomination and its archiefactiedatum from the applicable
selectielijst and SHALL write both onto the object together with the
selectielijst row and the rule that produced each value. An object for
which no rule applies SHALL be reported with the reason and SHALL NOT be
left silently unnominated. Recomputing a written nomination SHALL be an
explicit act, recorded with its actor and its reason.

#### Scenario: closing an object writes its archival future

- **GIVEN** a schema whose selectielijst row gives `vernietigen` after seven years
- **WHEN** an object reaches its terminal state on 1 March 2026
- **THEN** the object carries the nomination `vernietigen`, an archiefactiedatum of 1 March 2033
- **AND** both name the selectielijst row they came from

#### Scenario: an object nobody can nominate is reported

- **GIVEN** an object whose resultaat matches no selectielijst row
- **WHEN** it reaches its terminal state
- **THEN** it is reported as unnominatable with the reason
- @e2e exclude {derivation, covered by unit tests}

### Requirement: Every item on a destruction list has an accountable reviewer (REQ-APS-002)

An entry on a destruction list SHALL carry the person accountable for
deciding it. A list containing entries with no reviewer SHALL name those
entries. A reviewer SHALL be able to read their own pending entries across
lists. While entries wait, the system SHALL remind their reviewer on a
declared frequency.

#### Scenario: a reviewer sees what is waiting on them

- **GIVEN** two destruction lists with entries assigned to one reviewer
- **WHEN** that reviewer reads their pending items
- **THEN** the entries from both lists are returned

#### Scenario: an unassigned entry is named

- **GIVEN** a destruction list of 15 entries, 3 of them with no reviewer
- **WHEN** the list is read
- **THEN** the response names those 3 entries as unassigned

#### Scenario: waiting items produce a reminder

- **GIVEN** entries pending beyond the declared reminder frequency
- **WHEN** the reminder pass runs
- **THEN** each reviewer receives one notification naming their pending count
- @e2e exclude {background pass, covered by unit tests}

### Requirement: A review answer is destroy, retain or transfer (REQ-APS-003)

A reviewer SHALL be able to answer an entry with destroy, retain with a
new archiefactiedatum and a reason, or transfer to an e-depot. All three
SHALL be recorded in the same decision history, naming the reviewer, the
moment and the reason. A transfer answer SHALL hand the item to the
e-depot transfer path while the record of the decision stays with the
object.

#### Scenario: transfer is a decision, not a separate errand

- **GIVEN** a destruction list entry for a permanently preserved dossier
- **WHEN** the reviewer answers transfer
- **THEN** the decision history records transfer with the reviewer and the reason
- **AND** the item is handed to the transfer path

#### Scenario: retaining moves the date and says why

- **GIVEN** an entry a reviewer wants to keep for two more years
- **WHEN** the reviewer answers retain with a new date and a reason
- **THEN** the object's archiefactiedatum moves and the reason is recorded

### Requirement: The archival facts are readable on the object (REQ-APS-004)

An object read SHALL carry its archival nomination, its archiefactiedatum,
the selectielijst row and statutory basis behind them, any legal hold in
force, and the destruction or transfer record once one exists.

#### Scenario: a handler answering a Woo request sees the basis

- **GIVEN** a nominated object with a legal hold in force
- **WHEN** it is read
- **THEN** the response carries the nomination, the date, the selectielijst row, the statutory basis and the hold

### Requirement: The element mapping and the classification plan are administered (REQ-APS-005)

Which object property fills which MDTO or TMLO element SHALL be
configuration with a validator rather than a shipped default. A transfer
of objects whose mandatory elements are unmapped SHALL be refused, naming
the element. A selectielijst or classification plan SHALL be importable
from a file, versioned, and a new version SHALL be diffable against the
version in use.

#### Scenario: an unmapped mandatory element stops the transfer here

- **GIVEN** a mapping with no source for a mandatory MDTO element
- **WHEN** a transfer of objects of that schema is requested
- **THEN** the transfer is refused naming the element
- **AND** nothing was sent to the e-depot

#### Scenario: a new selectielijst is compared before it is used

- **GIVEN** a selectielijst in use and a newer version imported from a file
- **WHEN** the two versions are compared
- **THEN** the changed rows are listed with what they would change
- @e2e exclude {import path, covered by unit tests}
