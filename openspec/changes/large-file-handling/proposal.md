---
kind: code
depends_on: []
---

# Proposal: large-file-handling

## Summary

Large files become a product promise: a stated extraction ceiling, uploads in parts with resume, and a completeness check by size and SHA-256 before the file exists.

- Rows: 16.7, 17.13 and 17.14 (not statutory).
- Wave 1, size L.
- Dependencies: none blocking. Once `openregister/upload-malware-scan` (https://github.com/ConductionNL/openregister/issues/4399) has landed, an assembled file is scanned too.
- No Ruben decision bears on it.
- Build rules: openspec/woo-build-rules.md

## Why

Three rows ask that large files be a product promise rather than a platform accident. Our column (the round 1 baseline):

| row | capability | ours today |
|---|---|---|
| 16.7 | Full text extraction has a stated size ceiling, and a file over it is still findable by its metadata | partial: `FileSettingsHandler` holds `maxFileSize` and `maxFileSizeMB` (both 100), but nothing in `lib/` reads either; `TextExtractionService::extractFile()` extracts whatever it is handed, and nothing marks a file as not text-searched |
| 17.13 | A file too large for one request uploads in parts, and the product states the expected part boundaries | partial: Nextcloud's chunked upload v2 works at the platform level; OpenRegister's file API (`FilesController::create()`, `createMultipart()`) takes one request and states no part size |
| 17.14 | An interrupted upload resumes rather than restarting, and the product states whether the file is complete | no: OpenRegister exposes no resume and reports no completeness |

A Woo dossier routinely holds scans and mail exports of hundreds of megabytes. An integrator that cannot upload them through the object's file API, or an officer who cannot find a file because its extraction silently failed, is the gap.

## What changes

- **Stated ceiling (16.7).** `textExtraction.maxBytes` replaces the two unread keys (migrated from `maxFileSizeMB`). `TextExtractionService::extractFile()` checks it before reading. A file over the ceiling is not read; it gets the metadata chunk only (name, type, size, owning object, path) and an extraction status `skipped-too-large`. The file stays findable by name and by its object's metadata in file search, and every search hit for it carries `textSearched: false`. The ceiling is returned by `GET /api/settings/files` and shown on the file configuration page.
- **Uploads in parts (17.13).** `POST /api/objects/{register}/{schema}/{id}/uploads` opens an upload session for a declared name, size and SHA-256 and answers with `uploadId`, `partSize` (the setting `upload.partSizeBytes`, default 10 MiB), `partCount` and the byte range of every part. `PUT .../uploads/{uploadId}/parts/{n}` stores part n, refusing a part whose length does not match its stated range.
- **Resume and completeness (17.14).** `GET .../uploads/{uploadId}` lists received and missing parts and `complete: false|true`. A client that lost its connection asks this and sends only the missing parts. `POST .../uploads/{uploadId}/complete` assembles the parts, verifies size and SHA-256, runs the same upload checks as any upload, and creates the file through the existing file creation path. Anything missing or mismatched is refused with 409, and no file is created.
- Parts live in the app's data store, never in the object's folder, and an unfinished session expires after `upload.sessionTtl` (default `PT24H`), removed by a background job.

## What does not change

- Single-request uploads through `files#create` and `files#createMultipart`.
- Nextcloud's own WebDAV chunked upload, which stays available to Files clients.

## Dependencies and absent apps

- None blocking. `upload-malware-scan` (wave 1) adds its scan to the shared upload checks, so an assembled file is scanned too once both have landed.
- No other app is involved.

## Wave and decision

Wave 1, size L. No decision bears on it. Closes 16.7, 17.13 and 17.14.
