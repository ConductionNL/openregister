# file-actions

## ADDED Requirements

### Requirement: A reader can browse an object's folder without a share that outlives the read rule

A person who may read an object SHALL be able to browse the object's folder in the
shared files browser, and a person who may update it SHALL be able to add files
there. Access SHALL follow the object's access rules on every request.
OpenRegister SHALL NOT create a Nextcloud share to give a reader the folder,
because a share on an object folder is an object grant and would keep admitting
the reader after the rule that admitted them stops doing so.

#### Scenario: a reader browses the case folder

- **GIVEN** a schema that grants `read` to group `vergunningen`, user `bea` in that group, and a case with two files in its folder
- **WHEN** `bea` opens the case's files tab
- **THEN** the files browser shows the case folder with the two files
- @e2e exclude {needs the decision in the proposal; the e2e reference is written with the chosen option}

#### Scenario: access ends on the next request

- **GIVEN** `bea` could browse the case folder
- **WHEN** `bea` is removed from `vergunningen`
- **THEN** her next request shows neither the folder nor its files

#### Scenario: no mirror share

- **GIVEN** any reader opening any object's files tab
- **WHEN** the files browser shows the folder
- **THEN** no new Nextcloud share exists on the object folder
