# text-extraction

## ADDED Requirements

### Requirement: A caller can read the extracted text of a file they can open

`GET /api/files/{fileId}/text` SHALL return the text OpenRegister extracted
from the file, assembled from its stored chunks in order, with the chunk count
and the extraction time, when the caller can open the file. A file the caller
cannot open and a file with no extracted text SHALL both answer 404, each with
its own fixed sentence.

#### Scenario: a maker hands a PDF to buildiq's assistant

- **GIVEN** a maker who uploaded `eisen.pdf`, from which OpenRegister extracted text
- **WHEN** buildiq calls `GET /index.php/apps/openregister/api/files/{fileId}/text` as that maker
- **THEN** the answer is 200 with the text of the PDF in reading order and its chunk count
- @e2e exclude {specified only; task 4.1 adds the Newman case}

#### Scenario: another user's file stays closed

- **GIVEN** a file owned by another user and not shared with the maker
- **WHEN** buildiq asks for its text as the maker
- **THEN** the answer is 404 "File not found." and no text
- @e2e exclude {API contract; covered by FileTextControllerTest in task 1.1}
