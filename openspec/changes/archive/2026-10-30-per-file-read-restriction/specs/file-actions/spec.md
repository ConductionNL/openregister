## ADDED Requirements

### Requirement: A file in an object's folder can carry a read restriction (REQ-PFRR-001)

An app SHALL be able to restrict one file of an object to named users and groups through
`FileRestrictionService::restrict(fileId, users, groups, reason)`, change it by calling it again, and lift it with
`FileRestrictionService::lift(fileId)`. A restriction SHALL name at least one user or group and SHALL carry a
non-empty reason; the service SHALL record who set it and when. `restrictionOf(fileId)` SHALL answer the stored
restriction or null.

#### Scenario: A restriction is stored with who and when
- **GIVEN** user `pieter` is signed in
- **WHEN** an app restricts file 42 to user `pieter` and group `woo-team` with reason "Commercially sensitive prices"
- **THEN** `restrictionOf(42)` answers users `[pieter]`, groups `[woo-team]`, that reason, `setBy` `pieter` and the time

#### Scenario: A restriction without principals or reason is refused
- **WHEN** an app restricts a file with no users and no groups, or with an empty reason
- **THEN** the service throws `InvalidArgumentException` and stores nothing

#### Scenario: Lifting answers what was there
- **WHEN** an app lifts the restriction of file 42
- **THEN** the stored restriction is removed and the previous one is returned

### Requirement: A restricted file is readable only by its listed principals, administrators and admitted callers (REQ-PFRR-002)

`FileRestrictionGuard::mayRead(fileId)` SHALL answer true for a file without a restriction. For a restricted file it
SHALL answer true only when the caller is listed, is a member of a listed group, is an administrator, or is admitted
by a listener of `FileRestrictionAdmitEvent`. It SHALL answer false for an anonymous caller and when the restriction
cannot be read.

#### Scenario: A caller not listed is refused
- **GIVEN** file 42 is restricted to user `pieter` and group `woo-team`
- **WHEN** user `anna`, in neither, asks
- **THEN** the guard answers false

#### Scenario: A listed user, a group member and an admin are admitted
- **GIVEN** file 42 is restricted to user `pieter` and group `woo-team`
- **WHEN** `pieter`, a member of `woo-team`, or a member of `admin` asks
- **THEN** the guard answers true

#### Scenario: An app admits a caller through the event
- **GIVEN** file 42 is restricted and `anna` is not listed
- **WHEN** a listener of `FileRestrictionAdmitEvent` admits `anna` for file 42
- **THEN** the guard answers true for `anna`

#### Scenario: A lookup failure refuses
- **WHEN** reading the restriction throws
- **THEN** the guard answers false

### Requirement: Every OpenRegister file path honours the restriction (REQ-PFRR-003)

Every OpenRegister path that lists or returns an object's file SHALL ask `FileRestrictionGuard` before it lists or
returns the file. A refused file SHALL be absent from a listing and SHALL answer 404 on a per-file endpoint, so the
caller cannot tell a restricted file from a missing one.

#### Scenario: The object folder listing leaves the file out
- **GIVEN** a case folder holding `Offerte.pdf` (restricted) and `Brief.pdf`
- **WHEN** a reader of the case who is not admitted lists the folder
- **THEN** the listing holds `Brief.pdf` only

#### Scenario: A rename or delete through the folder browser answers 404
- **WHEN** that reader renames or deletes `Offerte.pdf` by node id
- **THEN** the browser throws NotFoundException (404)

#### Scenario: The files list leaves the file out
- **WHEN** that reader asks `GET /api/objects/{r}/{s}/{id}/files`
- **THEN** the restricted file is not in the result

#### Scenario: A per-file endpoint answers 404
- **WHEN** that reader asks show, preview, versions, Office or any other per-file endpoint for the restricted file
- **THEN** the answer is 404

#### Scenario: Download by file id answers 404
- **WHEN** that reader asks `GET /api/files/{fileId}/download` for the restricted file
- **THEN** the answer is 404

#### Scenario: The ZIP download leaves the file out
- **WHEN** that reader downloads the object's files as a ZIP
- **THEN** the restricted file is not in the archive

#### Scenario: Search leaves the file out
- **WHEN** a file search hit points at the restricted file
- **THEN** `FileReadScope` drops it for that reader

#### Scenario: The integration files leaf leaves the file out
- **WHEN** the files integration provider lists the object's files for that reader
- **THEN** the restricted file is not in the rows

#### Scenario: The rendered object leaves the file out
- **WHEN** that reader reads the object with its files, or a file-typed property names the restricted file
- **THEN** `@self.files` does not hold it and the property does not hydrate it

#### Scenario: An access link never describes it
- **WHEN** an access link to the object names the restricted file
- **THEN** no file descriptor is returned

#### Scenario: An admitted reader sees and opens the file on every path
- **WHEN** a listed user does any of the above
- **THEN** the file is listed and returned as it is today

### Requirement: A restricted file has no public link (REQ-PFRR-004)

Restricting a file SHALL remove its public link shares (share type 3). Publishing a restricted file SHALL be refused
while the restriction exists. Native Nextcloud paths (Files, WebDAV, `/f/<id>`, core preview) are outside this
guarantee; an object folder whose files may be restricted SHALL NOT be shared natively.

#### Scenario: Restricting revokes the public link
- **GIVEN** file 42 has a public link
- **WHEN** it is restricted
- **THEN** the public link share is deleted

#### Scenario: Publishing a restricted file is refused
- **WHEN** a caller publishes file 42 while it is restricted
- **THEN** the publish throws and no share is created
