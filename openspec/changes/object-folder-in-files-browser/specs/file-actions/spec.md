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
- **THEN** the files browser lists the case folder with the two files, read through the object's folder endpoint
- @e2e tests/e2e/ci/object-folder-browser.spec.ts

#### Scenario: access ends on the next request

- **GIVEN** `bea` could browse the case folder
- **WHEN** the rule that admitted her no longer does
- **THEN** her next request for the folder answers 404 and lists nothing
- @e2e tests/e2e/ci/object-folder-browser.spec.ts

#### Scenario: no mirror share

- **GIVEN** any reader listing any object's folder
- **WHEN** the listing answers
- **THEN** no new Nextcloud share exists on the object folder
- @e2e tests/e2e/ci/object-folder-browser.spec.ts

### Requirement: Listing an object's folder and its subfolders follows the object's read rule

`GET /api/objects/{register}/{schema}/{id}/folder` SHALL list the files and
folders directly inside the object folder, or inside the subfolder named by
`path` relative to it, only for a caller who may read the object. Each entry
SHALL carry its id, name, type (`file` or `folder`), mime type, size, modified
time and path relative to the object folder. The answer SHALL say whether the
caller may change the folder (`canChange`), which is true exactly when the caller
may update the object. A caller who may not read the object SHALL get 404, the
answer an object read gives. A `path` with an empty, `.` or `..` segment, a
backslash or a NUL SHALL be refused with 400 before any lookup, and a `path` that
is not a folder of the object SHALL answer 404.

#### Scenario: a reader lists a subfolder

- **GIVEN** a case whose folder holds a file and a subfolder `Bijlagen` with one file, and `bea` may read but not update the case
- **WHEN** `bea` lists the folder, then lists `path=Bijlagen`
- **THEN** the first listing holds the file and the folder `Bijlagen`, the second holds the subfolder's file with path `Bijlagen/<name>`, and both say `canChange: false`
- @e2e tests/e2e/ci/object-folder-browser.spec.ts

#### Scenario: a person who may not read the case gets 404

- **GIVEN** a user who may not read the case
- **WHEN** they list its folder or any subfolder
- **THEN** the answer is 404 and names no file
- @e2e tests/e2e/ci/object-folder-browser.spec.ts

#### Scenario: a path cannot leave the object folder

- **GIVEN** a reader of the case
- **WHEN** they list `path=../<another object>` or `path=Bijlagen/../..`
- **THEN** the answer is 400 and nothing outside the case folder is read
- @e2e tests/e2e/ci/object-folder-browser.spec.ts

### Requirement: Changing an object's folder and its subfolders follows the object's update rule

Creating a subfolder, uploading files into the object folder or a subfolder,
renaming a file or folder, and deleting a file or folder inside the object folder
SHALL each require update on the object: 404 for a caller who may not read it,
403 for a reader who may not update it. A new name SHALL be one path segment. A
name already taken in the target folder SHALL answer 409 and change nothing. A
node id that is not inside the object folder, or that is the object folder
itself, SHALL answer 404. An upload SHALL go through the same pipeline as every
other object file upload. Renaming or deleting a folder that holds a file locked
by someone else SHALL answer 409.

#### Scenario: a person who may update the case adds a subfolder and a file in it

- **GIVEN** a user who may update the case
- **WHEN** they create folder `Bijlagen` and upload `brief.txt` into `path=Bijlagen`
- **THEN** listing `path=Bijlagen` shows `brief.txt`, and downloading it through the case's `files/{fileId}` endpoint returns its content
- @e2e tests/e2e/ci/object-folder-browser.spec.ts

#### Scenario: a reader may not change the folder

- **GIVEN** `bea` may read but not update the case
- **WHEN** she creates a folder, uploads a file, renames or deletes a node
- **THEN** each answers 403 and the folder is unchanged
- @e2e tests/e2e/ci/object-folder-browser.spec.ts

#### Scenario: a taken name is refused

- **GIVEN** the case folder holds `Bijlagen`
- **WHEN** an updater creates another folder `Bijlagen` or renames a file to `Bijlagen`
- **THEN** the answer is 409 and nothing changes
- @e2e tests/e2e/ci/object-folder-browser.spec.ts

#### Scenario: a node of another object is not reachable

- **GIVEN** an updater of case A and the id of a file in case B's folder
- **WHEN** they rename or delete that id through case A
- **THEN** the answer is 404 and case B's file is unchanged
- @e2e tests/e2e/ci/object-folder-browser.spec.ts

#### Scenario: an updater renames and deletes a subfolder

- **GIVEN** an updater and a subfolder `Bijlagen` holding a file
- **WHEN** they rename it to `Stukken`, then delete `Stukken`
- **THEN** the listing shows `Stukken` after the rename and neither folder after the delete
- @e2e tests/e2e/ci/object-folder-browser.spec.ts
