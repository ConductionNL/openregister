# File publication window

## ADDED Requirements

### Requirement: A file carries its own publication window (REQ-FPW-101)

A file MUST be able to declare when it becomes public and when it stops being
public, independently of the object it is attached to. Without this an
attachment can only be published or not published, which is why apps grew a
separate `document` object to hold the dates.

Both bounds are nullable, and each null means something specific:

- No publication date means the file was NEVER published. It MUST NOT default to
  the file's creation time. A file that exists is not a file that was published.
- A publication date in the future means not yet.
- No depublication date means no end date. It MUST NOT be read as an end date in
  the past.

The window is inclusive at the start and exclusive at the end, so a file
published and depublished at the same instant is not public.

#### Scenario: A file with no publication date is not published

- **GIVEN** a file whose `published` is null
- **WHEN** its publication state is evaluated
- **THEN** it is not published, whatever its creation time.

#### Scenario: A future publication date is not yet published

- **GIVEN** a file published tomorrow
- **WHEN** its publication state is evaluated today
- **THEN** it is not published.

#### Scenario: No depublication date means it stays published

- **GIVEN** a published file whose `depublished` is null
- **WHEN** its publication state is evaluated
- **THEN** it is published.

#### Scenario: A passed depublication date ends publication

- **GIVEN** a file published yesterday and depublished this morning
- **WHEN** its publication state is evaluated now
- **THEN** it is not published.

### Requirement: A depublication date expires the public share (REQ-FPW-102)

Publishing a file with a depublication date MUST set that date as the public
share's expiration. Recording the date only on the OpenRegister side would leave
a public URL that still serves the file, and a URL that still works is not a
depublication.

#### Scenario: The share carries the end of the window

- **WHEN** a file is published with a depublication date
- **THEN** the created public share carries that date as its expiration.

#### Scenario: No depublication date leaves the share open

- **WHEN** a file is published with no depublication date
- **THEN** the share has no expiration.

### Requirement: The file API reports the window, not the creation time (REQ-FPW-103)

The formatted file MUST report `published`, `depublished` and a computed
`isPublished`. It MUST NOT report the creation time under `published`: doing so
made every file that had ever existed look published, and made "not published"
unrepresentable.

The creation time remains available under `created`.

#### Scenario: An unpublished file reports no publication date

- **GIVEN** a file that was never published
- **WHEN** it is formatted
- **THEN** `published` is null and `isPublished` is false
- **AND** `created` carries its creation time.
