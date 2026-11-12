## ADDED Requirements

### Requirement: The text of an attached PDF, Word or mail file is extracted

When a PDF, a Word document (DOCX or ODT) or a mail file (EML, `message/rfc822`) is attached
to a record, the register MUST extract its plain text. The text MUST be stored with the file, so
search and the extraction tab can read it. A file whose text cannot be read MUST report that,
not an empty text.

#### Scenario: A PDF attachment is read
- **GIVEN** a record with an attached PDF whose first page reads "Aanvraag kapvergunning"
- **WHEN** the file's text is extracted
- **THEN** the extracted text contains "Aanvraag kapvergunning"

#### Scenario: A Word attachment is read
- **GIVEN** a record with an attached DOCX whose body reads "Besluit op bezwaar"
- **WHEN** the file's text is extracted
- **THEN** the extracted text contains "Besluit op bezwaar"

#### Scenario: A mail attachment is read
- **GIVEN** a record with an attached EML whose subject is "Vraag over mijn melding" and whose body reads "Wanneer komt u langs?"
- **WHEN** the file's text is extracted
- **THEN** the extracted text contains the subject and the body
