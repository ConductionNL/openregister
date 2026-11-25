# Proposal: file-text-facade

## Why

hermiq's chat attachments (`chat-attachments-and-images`, design D6) and its
document chat need the text of a PDF or office file a person attached, at the
moment of the turn. OpenRegister holds the extractors, but they are internal:
`TextExtractionService::extractFile()` chunks, indexes and stores entities as a
side effect, and `GET /api/files/{fileId}/text` answers only after a background
extraction ran. A model that cannot read a PDF natively then gets nothing.

## What changes

- A public facade, `OCA\OpenRegister\Files\Service\FileTextFacade`, with
  `isAvailable(): bool` and `extractText(int $fileId, string $userId): ?string`.
- The file is resolved in the given user's own folder, so a file that user
  cannot open answers null; the text is read on demand with the same readers
  the extraction uses (plain text, PDF, Word, spreadsheet, mail), sanitised the
  same way, and nothing is written: no chunk, no index, no entity, no mail
  header row.
- Like `VectorSearchFacade`, the class name and the two signatures are a
  cross-app contract; a change to them needs an OpenSpec change.

## Impact

- New class and unit test only; no schema, route or migration.
- Consumers resolve the class behind `class_exists()`, so an older
  OpenRegister keeps answering "could not read" in the consumer.
