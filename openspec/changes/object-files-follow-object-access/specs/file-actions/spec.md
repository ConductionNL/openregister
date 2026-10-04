## ADDED Requirements

### Requirement: OpenRegister's own account holds every managed folder (REQ-OFOA-001)

The system SHALL create every register folder and every object folder it manages in the home of the `openregister` account, whoever is signed in when the folder is made. The account that saves an object SHALL NOT receive a folder, a copy of a file, or a share of a managed folder. A folder a caller bound with `@self.folder` to a node outside `Open Registers/` SHALL keep its owner.

#### Scenario: An intake account saves a submission with attachments

- **GIVEN** a schema whose authorization grants `create` to group `openformulieren-intake` and `read` and `update` to group `openformulieren-behandelaars`
- **AND** account `of-intake` is a member of `openformulieren-intake`
- **WHEN** `of-intake` saves a submission and adds two attachment files to it
- **THEN** the object folder is `/openregister/files/Open Registers/<register>/<object>`
- **AND** both files are owned by the `openregister` account
- @e2e exclude {backend storage placement with no UI of its own; covered by PHPUnit against a real FolderManagementHandler with a fake root}

#### Scenario: The saving account holds no private copy

- **GIVEN** `of-intake` saved a submission with two attachments
- **WHEN** the home of `of-intake` is listed over WebDAV
- **THEN** it contains no `Open Registers` folder and no copy of either attachment
- **AND** no share of the object folder names `of-intake`
- @e2e exclude {WebDAV listing of another account's home; covered by an integration test on the Nextcloud test image}

#### Scenario: A folder bound outside the managed tree keeps its owner

- **GIVEN** user `alice` binds an object to folder `42` in her own home with `@self.folder`
- **WHEN** a file is added to that object
- **THEN** the file is created in folder `42`, owned by `alice`
- **AND** the `self-folder-access-control` rules still apply to the bind
- @e2e exclude {bind path covered by the self-folder-access-control PHPUnit suite}

### Requirement: Reading an object's files follows the object's read rule (REQ-OFOA-002)

Every file read of an object SHALL require read on that object, decided by the same rule that decides reading the object. File reads are: list, show, download, download by file id, preview, versions, ZIP export, extracted text and file search hits. A caller who may not read the object SHALL receive no file content, no file metadata and no file count. After the check, the system SHALL read the file as the `openregister` account, so the caller needs no Nextcloud access to the file itself.

#### Scenario: A handler group member reads the intake's attachments

- **GIVEN** a submission saved by `of-intake` with two attachments
- **AND** user `behandelaar` is a member of `openformulieren-behandelaars`, which the schema grants `read`
- **WHEN** `behandelaar` lists the files of the submission
- **THEN** the response lists both attachments
- **AND** `behandelaar` can download each of them with its original bytes
- @e2e exclude {needs two signed-in accounts and a configured intake schema; covered by an API test on the Nextcloud test image}

#### Scenario: A user outside the authorized groups is refused

- **GIVEN** the same submission
- **AND** user `gewoon` is in no group the schema grants `read`, and is not the object's owner
- **WHEN** `gewoon` lists the files of the submission
- **THEN** the request is refused the way reading the object is refused
- **AND** the response carries no file name, no file id and no file count
- @e2e exclude {needs two signed-in accounts; covered by an API test on the Nextcloud test image}

#### Scenario: A user outside the authorized groups cannot fetch a file by id

- **GIVEN** the same submission and user `gewoon`
- **AND** `gewoon` knows the file id of one attachment
- **WHEN** `gewoon` downloads that file id through the download-by-id endpoint
- **THEN** the request is refused
- **AND** no file content is returned
- @e2e exclude {id-guessing path; covered by an API test on the Nextcloud test image}

#### Scenario: An admin reads the attachments

- **GIVEN** the same submission
- **AND** user `admin` is a member of the `admin` group
- **WHEN** `admin` lists and downloads the files of the submission
- **THEN** both attachments are listed and downloaded
- @e2e exclude {admin read through the API; covered by an API test on the Nextcloud test image}

#### Scenario: The saving account reads through the object rule

- **GIVEN** the same submission, owned by `of-intake`
- **WHEN** `of-intake` lists the files of the submission
- **THEN** both attachments are listed, because the owner rule lets `of-intake` read its own object
- **AND** the files are read from the `openregister` account's home, not from the home of `of-intake`
- @e2e exclude {owner-rule read through the API; covered by an API test on the Nextcloud test image}

#### Scenario: File search shows hits only on readable objects

- **GIVEN** text extracted from an attachment of the submission
- **WHEN** `behandelaar` and `gewoon` each search for a word in that text
- **THEN** `behandelaar` gets the hit
- **AND** `gewoon` does not
- @e2e exclude {search scope filter; covered by PHPUnit on FileReadScope with real PermissionHandler decisions}

### Requirement: Changing an object's files follows the object's update rule (REQ-OFOA-003)

Every change to an object's files SHALL require update on that object, decided by the same rule that decides updating the object. Changes are: create, save, multipart upload, update, rename, labels, metadata, lock, unlock, restore a version and delete. Copying a file SHALL require read on the source object and update on the target object. Moving a file SHALL require update on both. After the check, the system SHALL write the file as the `openregister` account.

#### Scenario: A handler copies the intake's attachments to the case

- **GIVEN** a submission saved by `of-intake` with two attachments
- **AND** a case object that `behandelaar` may update
- **WHEN** the handoff copies both attachments from the submission to the case, acting as `behandelaar`
- **THEN** both copies exist in the case's folder in the `openregister` account's home
- **AND** each attachment is marked `copiedToCase: true`
- @e2e exclude {integriq handoff path; covered by an integration test on the Nextcloud test image with integriq installed}

#### Scenario: A reader who may not update cannot add a file

- **GIVEN** an object that user `lezer` may read but not update
- **WHEN** `lezer` uploads a file to it
- **THEN** the upload is refused
- **AND** no file is created
- @e2e exclude {needs a read-only account; covered by an API test on the Nextcloud test image}

### Requirement: Existing files move into OpenRegister's own account (REQ-OFOA-004)

On upgrade the system SHALL move every managed register and object folder that sits in a person's home into the `openregister` account's home. The move SHALL keep every file id and folder id. Tags, OpenRegister file records, extracted text and published links SHALL keep working on the moved files. A folder the step cannot move SHALL stay where it is, SHALL be logged and SHALL be counted. The step SHALL NOT delete any file. Running the step again SHALL move only what is left.

#### Scenario: An intake's attachments migrate

- **GIVEN** an instance upgraded from a version that stored submission `a184fccf`'s attachments in `/of-intake/files/Open Registers/OpenConnector Register/a184fccf/`
- **WHEN** the upgrade runs the migration step
- **THEN** both attachments are in `/openregister/files/Open Registers/OpenConnector Register/a184fccf/`
- **AND** their file ids are unchanged, and the object's stored folder id still resolves
- **AND** `behandelaar` now lists both attachments
- @e2e exclude {upgrade migration; covered by a repair-step integration test on the Nextcloud test image with seeded homes}

#### Scenario: A published link keeps working after the move

- **GIVEN** a published attachment with a public link token
- **WHEN** the migration step moves its folder
- **THEN** the public link returns the same file
- **AND** the share's owner is the `openregister` account
- @e2e exclude {public share after migration; covered by a repair-step integration test}

#### Scenario: Two homes hold folders for the same register

- **GIVEN** `alice` and `bob` each hold a folder for register `zaken` with different object folders inside
- **WHEN** the migration step runs
- **THEN** every object folder of both ends up under the one `zaken` folder in the `openregister` home
- **AND** a file name that clashes inside one object folder gets a numeric suffix rather than replacing the other file
- @e2e exclude {merge rules; covered by PHPUnit on the repair step with a fake root}

#### Scenario: A folder that cannot be moved is left and reported

- **GIVEN** an object folder whose move fails, for example because it is locked
- **WHEN** the migration step runs
- **THEN** that folder stays where it was with all its files
- **AND** the step reports it in its count of folders left
- **AND** a later run moves it once it can
- @e2e exclude {failure and retry path; covered by PHPUnit on the repair step}

### Requirement: The acting person is the actor of a file action (REQ-OFOA-005)

OpenRegister's audit trail SHALL record the acting person as the actor of every file action on an object, not the `openregister` account. The acting person is the session user, or the identity of an explicit run-as scope.

#### Scenario: A handler's download is attributed to the handler

- **GIVEN** `behandelaar` downloads an attachment of a submission
- **WHEN** the audit trail of the submission is read
- **THEN** the download entry names `behandelaar` as the actor
- @e2e exclude {audit attribution; covered by PHPUnit on FileAuditHandler through the controller}
