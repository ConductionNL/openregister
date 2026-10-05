---
kind: code
depends_on: []
---

# Proposal: near-duplicate-documents-by-text

## Why

Row 19.5, "Near-identical documents are grouped, so the same text is not read twice", is `partial` in our column (`baseline/openwoo.tsv`). `lib/Service/Quality/DuplicateDetectionService.php` groups objects by declared match rules (`exact`, `normalized`, `levenshtein` over object properties, with blocking keys from `x-openregister-dedup`). It never looks at a document's text. A Woo request gathers the same mail as a forwarded copy, a reply quoting it and a PDF print; their file names and object properties differ and their text is near-identical, so a reviewer reads it three times.

## What changes

- At extraction, `TextExtractionService` computes a MinHash signature of each file's text (word 5-shingles after lower-casing and whitespace folding, 128 hash functions) and stores it with the shingle count and a SHA-256 of the normalised text in a new `openregister_text_signatures` table. Files below 50 shingles get no signature, so short cover notes are never grouped by accident.
- `DuplicateDetectionService::findTextGroups(array $fileIds, ?float $threshold)` finds candidate pairs through locality-sensitive banding (32 bands of 4 rows), confirms each by estimated Jaccard similarity at or above the threshold (default 0.9, setting `duplicates.textThreshold`), and joins pairs into groups. Each group names a representative (the earliest created file), its members with their similarity to the representative, and whether a member is byte-identical in text.
- `GET /api/files/near-duplicates` takes a scope (`objectIds`, `folderId` or `fileIds`), applies the caller's read rights per file, and returns the groups plus the files that could not be compared (`noText`, `tooShort`, `notExtracted`).
- A new match rule method `text` lets a schema's `x-openregister-dedup` compare objects by the signatures of their attached files, so the existing duplicates review lists text duplicates beside property duplicates.
- Signatures are recomputed when a file's text is re-extracted and deleted with the file's chunks.

## What does not change

- The declarative property rules and their scores.
- What a consumer does with a group. dossiq's corpus collection and opencatalogi's review triage decide to read the representative only; this change supplies the grouping.

## Dependencies and absent apps

- None blocking. Consumers: `dossiq/woo-request-corpus-collection` and `opencatalogi/woo-review-triage` read the endpoint; without them the duplicates review in OpenRegister still shows the groups.

## Wave and decision

Wave 1, size M. No decision bears on it. Closes 19.5.
