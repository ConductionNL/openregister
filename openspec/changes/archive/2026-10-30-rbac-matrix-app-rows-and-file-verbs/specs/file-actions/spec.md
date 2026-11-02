# file-actions

## ADDED Requirements

### Requirement: A schema can grant file rights apart from object rights

The permission catalogue SHALL publish `readFiles`, `addFiles` and `deleteFiles`, and the matrix SHALL accept them. When an effective authorization block names any of them, file actions on an object SHALL require `read` on the object and the file verb of the action (design D-4). When a block names none of them, file actions SHALL follow the object rule as before.

#### Scenario: a reader of files cannot add one

- **GIVEN** schema `case` whose authorization grants group `inspectors` `read` and `readFiles`
- **WHEN** a member of `inspectors` lists a case's files and then uploads one
- **THEN** the list answers 200 with the files
- **AND** the upload answers 403
- @e2e exclude {covered by PHPUnit through FilesController with a real PermissionHandler}

#### Scenario: adding is not deleting

- **GIVEN** group `intakers` with `read`, `readFiles` and `addFiles`
- **WHEN** a member uploads a file and then deletes it
- **THEN** the upload succeeds and the delete answers 403
- @e2e exclude {covered by PHPUnit through FilesController}

#### Scenario: a schema without file verbs keeps today's rule

- **GIVEN** a schema granting `read` and `update` and no file verb
- **WHEN** a member with `update` uploads a file
- **THEN** the upload succeeds as it does today
- @e2e exclude {covered by the existing object-files-follow-object-access tests}
