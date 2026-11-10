# file-actions

## ADDED Requirements

### Requirement: A Files action attaches a file to a register object

OpenRegister SHALL register a Files action, "Add to object", on files and
folders, that offers a picker over the register objects the current user may
write to, optionally narrowed by a consuming app's `fileActions.attachTargets`
declaration, and SHALL attach the chosen node through the existing file
upsert pipeline so the attachment is validated, owned, tagged and audited as
an uploaded file would be.

#### Scenario: a file in Files is attached to a case

- **GIVEN** a user who may write objects of schema `case` and a file in their Files
- **WHEN** they run "Add to object" on the file and pick a case by its title
- **THEN** the file appears on the case's files leaf and an audit entry names the user and the file
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/ci/files-leaf-save-to-object.spec.ts when the plugin ships}

#### Scenario: the picker offers only writable objects

- **GIVEN** a user with read scope on register `archive` and write scope on register `cases`
- **WHEN** they open the picker
- **THEN** objects of `archive` are absent from the results
- @e2e exclude {the writable filter is the RBAC layer's and is covered by unit tests}

### Requirement: A Talk action saves a conversation to a register object

OpenRegister SHALL register a Talk conversation action, "Save chat to
object", that exports the conversation's messages as a text file and attaches
that file to the chosen object through the same pipeline as the Files action.

#### Scenario: a chat becomes a file on the object

- **GIVEN** a Talk conversation with three messages and a user who may write to schema `case`
- **WHEN** they run "Save chat to object" and pick a case
- **THEN** a text file holding the three messages with their authors and times appears on the case's files leaf
- @e2e exclude {Talk is not installed on the CI instance; covered by a unit test on the exporter and a manual check}
