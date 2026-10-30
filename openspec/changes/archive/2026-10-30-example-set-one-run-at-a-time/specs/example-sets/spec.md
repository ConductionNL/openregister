# example-sets (delta)

## ADDED Requirements

### Requirement: One install or removal of a set runs at a time

An install or a removal of an example set SHALL hold an exclusive lock on that set for its whole run, and SHALL release it when the run ends, whether or not the run succeeded. A run that finds the set held SHALL write nothing and SHALL say that another run holds the set.

#### Scenario: an install while another run holds the set
- **GIVEN** another run holds the set
- **WHEN** the set is installed
- **THEN** the install is refused with the reason that another install or removal is running, and no account, object or record entry is written

#### Scenario: an install lets go of the set
- **GIVEN** no other run holds the set
- **WHEN** the set is installed, and then installed again
- **THEN** the first run holds no lock after it ends, and the second run proceeds and writes nothing twice

#### Scenario: a removal while another run holds the set
- **GIVEN** an installed set that another run holds
- **WHEN** the set is removed
- **THEN** nothing is removed, the record stays as it was, and the removal names the other run as the reason

#### Scenario: other sets are not held up
- **GIVEN** another run holds a different set
- **WHEN** this set is installed
- **THEN** the install proceeds
