## ADDED Requirements

### Requirement: A file property refuses a file outside its declared types or size

A file property MAY declare the file types it accepts and a largest size. The system MUST refuse an upload to that property whose type is not on the list or whose size is above the limit, before the file is stored, with a message that names the property and the types it accepts or the limit. A property without a list or a limit accepts any type and size the instance allows, apart from executables.

#### Scenario: A file of the wrong type is refused
- **GIVEN** a schema whose property `bijlage` accepts only `application/pdf`
- **WHEN** a user saves an object with a PNG image in `bijlage`
- **THEN** the save is refused with a message that names `bijlage`, the type `image/png` and the accepted type `application/pdf`
- **AND** no file is stored

#### Scenario: A file above the size limit is refused
- **GIVEN** the same property with a largest size of 5 MB
- **WHEN** a user saves an object with a 12 MB PDF in `bijlage`
- **THEN** the save is refused with a message that names the limit and the file's size

#### Scenario: A property without a list accepts any safe file
- **GIVEN** a file property that declares no types and no size
- **WHEN** a user saves an object with a spreadsheet in it
- **THEN** the file is stored
