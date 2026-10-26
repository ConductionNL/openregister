# Proposal: legal-holds-list

## Why

`GET /api/archival/legal-holds` answered an empty list with a comment that a full implementation would query the records. An archivist could place and release holds but not see which records are held, and an empty answer reads as "nothing is held". dossiq's Archiving card (board DqZaakArchivering, "Bewaren voor een procedure") and any review of holds need the real list.

## What changes

- `LegalHoldService::listActiveHolds()` walks every retention source through `RetentionRowScanner` and returns the records whose hold is active, each with `id`, `register`, `schema`, `name` and its active holds, plus `total` and `truncated` when the scan stopped at its cap.
- The endpoint returns that list. It stays archivist-only.
